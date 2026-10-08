<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Capture\PaperPublicCaptureOrphanFinalizer;
use App\Trading\Paper\Capture\PaperPublicDatasetCapture;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperFundingRateClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSource;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperDurableBatchSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperPublicLiveSource::class)]
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class HyperliquidPaperLiveCrashDurabilityTest extends TestCase
{
    private const DATASET_ID = 'paper-hyperliquid-crash-mainnet';

    private string $root;

    protected function setUp(): void
    {
        $temporary = realpath(sys_get_temp_dir());
        self::assertIsString($temporary);
        $this->root = $temporary . '/hyperliquid-crash-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testHardKillMidStreamKeepsDatasetEventsAndDurableCheckpointConsistent(): void
    {
        $frames = [
            self::candleFrame(0, '2'),
            self::tradeFrame(),
            self::bookFrame(),
            self::candleFrame(60_000, '3'),
            self::candleFrame(60_000, '3.5'),
            self::candleFrame(60_000, '3.7'),
        ];

        // Reference: the same frames without a crash. Completion runs the canonical
        // verifier, which checks the terminal checkpoint against every recorded event.
        $referenceRoot = $this->root . '/reference';
        self::assertTrue(mkdir($referenceRoot, 0700));
        $reference = $this->capture($referenceRoot, $frames, killWhenIdle: false);
        self::assertSame(PaperDatasetState::COMPLETE, $reference->state);
        self::assertSame(9, $reference->eventCount);
        $referenceEvents = file_get_contents($referenceRoot . '/' . self::DATASET_ID . '/events.ndjson');
        self::assertIsString($referenceEvents);

        // Crash: a child process is SIGKILLed while idle after the last frame, with the
        // two latest in-progress candle updates held only in memory.
        $crashRoot = $this->root . '/crash';
        $this->crash($crashRoot, $frames, null);
        self::assertSame('idle', file_get_contents($crashRoot . '/killed-mid-stream'));

        $directory = $crashRoot . '/' . self::DATASET_ID;
        self::assertSame(
            $referenceEvents,
            file_get_contents($directory . '/events.ndjson'),
            'Every event made durable before the crash must equal the uncrashed capture byte for byte.',
        );
        $manifest = self::manifestAt($directory);
        self::assertSame(PaperDatasetState::RECORDING, $manifest->state);
        self::assertSame(9, $manifest->eventCount);

        // The durable checkpoint passes full validation and sits on the last save: the
        // pending save of the last durable batch, the closed candle. While streaming, the
        // acknowledgement that ends a batch becomes durable with the next save, so this
        // recorded batch is still pending on disk and every earlier event is acknowledged.
        $checkpoint = self::durableCheckpoint($directory);
        $events = self::events($referenceEvents);
        $last = $events[\count($events) - 1];
        self::assertSame('streaming', $checkpoint->phase);
        self::assertTrue($checkpoint->continuity);
        self::assertInstanceOf(PaperMarketEvent::class, $checkpoint->pendingEvent);
        self::assertSame(
            CanonicalJson::encode($last->toArray()),
            CanonicalJson::encode($checkpoint->pendingEvent->toArray()),
        );
        self::assertSame(
            array_map(static fn (PaperMarketEvent $event): string => $event->eventId, array_slice($events, 0, -1)),
            $checkpoint->acknowledgedIdentities,
        );
        // The candle is finalized by its acknowledgement, which is not durable yet.
        self::assertSame([], $checkpoint->finalizedCandleFrontiers);
        self::assertCount(1, $checkpoint->tradeIdentityHistory);

        // The durable ordinal cursor covers every recorded event, so no sequence can
        // ever be assigned again to a different event.
        $latest = [];
        foreach ($events as $event) {
            $latest[implode('/', [
                $event->sourceNetwork->value,
                $event->sourceVenue->value,
                $event->symbol,
                $event->channel->value,
            ])] = $event;
        }
        $scopes = $checkpoint->ordinalState['scopes'];
        self::assertIsArray($scopes);
        ksort($latest, \SORT_STRING);
        self::assertSame(array_keys($latest), array_keys($scopes));
        foreach ($latest as $scope => $event) {
            self::assertSame($event->sequence, $scopes[$scope]['last_sequence']);
            self::assertSame(
                CanonicalJson::encode($event->toArray()),
                CanonicalJson::encode($scopes[$scope]['latest']['event']),
            );
        }

        // Durability window: in-progress candle updates are persisted with the next
        // durable transition, so the two updates after the last event were lost. They
        // never produce an event, and a streaming checkpoint is never resumed.
        self::assertSame(60_000, $checkpoint->currentCandles['BTC/1m']['t'] ?? null);
        self::assertSame('3', $checkpoint->currentCandles['BTC/1m']['c'] ?? null);

        // The supervisor finalizes an orphan from its manifest alone (shown on a copy)...
        $orphanRoot = $this->root . '/orphan';
        self::copyTree($crashRoot, $orphanRoot);
        self::assertTrue((new PaperPublicCaptureOrphanFinalizer($orphanRoot))->finalize(self::DATASET_ID));
        $manifest = self::manifestAt($orphanRoot . '/' . self::DATASET_ID);
        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(9, $manifest->eventCount);

        // ...and a restart of the streaming attempt replays the pending batch, which the
        // recorder answers REPLAYED, then fails closed before connecting: same bytes, and
        // the dataset becomes incomplete.
        [$manifest, $replayed, $transport] = $this->restart($crashRoot);
        self::assertSame([$last->eventId], $replayed);
        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(9, $manifest->eventCount);
        self::assertSame($referenceEvents, file_get_contents($directory . '/events.ndjson'));
        self::assertSame(0, $transport->connectCount, 'A streaming checkpoint must not resume.');
        $failed = self::durableCheckpoint($directory);
        self::assertSame('failed', $failed->phase);
        self::assertFalse($failed->continuity);
        self::assertSame('hyperliquid_public_trade_gap_unrecoverable', $failed->failureReason);
    }

    /**
     * A book batch is held in memory, saved as one pending durable batch, appended as one
     * recorder batch and acknowledged in memory. A crash at any of these points leaves a
     * byte prefix of the uncrashed dataset, and the restart replays the durable pending
     * batch (REPLAYED when already recorded, APPENDED otherwise) and fails closed.
     *
     * @return iterable<string, array{string, bool, int, string, list<int>, int}>
     */
    public static function bookBatchCrashPoints(): iterable
    {
        // point, frames up to the books only, events before the restart, durable pending
        // event, replayed reference positions, events after the restart
        yield 'books held in memory' => ['held', true, 7, 'trade_42', [6], 7];
        yield 'batch pending on disk' => ['pending_saved', false, 7, 'book_1001', [7, 8, 9], 10];
        yield 'batch partly acknowledged in memory' => ['mid_batch', false, 7, 'book_1001', [7, 8, 9], 10];
        yield 'batch appended, not acknowledged' => ['appended', false, 10, 'book_1001', [7, 8, 9], 10];
        yield 'batch acknowledged in memory' => ['acknowledged', false, 10, 'book_1001', [7, 8, 9], 10];
    }

    /** @param list<int> $replayedPositions */
    #[DataProvider('bookBatchCrashPoints')]
    public function testHardKillAtEachPointOfABookBatchLeavesAPrefixAndFailsClosed(
        string $point,
        bool $booksOnly,
        int $eventsBeforeRestart,
        string $durablePending,
        array $replayedPositions,
        int $eventsAfterRestart,
    ): void {
        $frames = [
            self::candleFrame(0, '2'),
            self::tradeFrame(42, 1_000),
            self::bboFrame('BTC', 1_001, '64999'),
            self::bboFrame('ETH', 1_002, '2499'),
            self::bboFrame('BTC', 1_003, '65000'),
            self::tradeFrame(43, 1_004),
            self::candleFrame(60_000, '3'),
        ];
        $referenceRoot = $this->root . '/reference';
        self::assertTrue(mkdir($referenceRoot, 0700));
        $reference = $this->capture($referenceRoot, $frames, killWhenIdle: false);
        self::assertSame(PaperDatasetState::COMPLETE, $reference->state);
        self::assertSame(12, $reference->eventCount);
        $referenceEvents = file_get_contents($referenceRoot . '/' . self::DATASET_ID . '/events.ndjson');
        self::assertIsString($referenceEvents);
        $events = self::events($referenceEvents);
        // The three books form one durable batch, between the two trades.
        self::assertSame(
            ['public_trade', 'top_of_book', 'top_of_book', 'top_of_book', 'public_trade', 'candle_1m'],
            array_map(static fn (PaperMarketEvent $event): string => $event->channel->value, array_slice($events, 6)),
        );

        $crashRoot = $this->root . '/crash';
        $this->crash(
            $crashRoot,
            $booksOnly ? array_slice($frames, 0, 5) : $frames,
            $booksOnly ? null : $point,
        );
        self::assertSame($booksOnly ? 'idle' : $point, file_get_contents($crashRoot . '/killed-mid-stream'));

        $directory = $crashRoot . '/' . self::DATASET_ID;
        self::assertSame(self::prefix($referenceEvents, $eventsBeforeRestart), file_get_contents($directory . '/events.ndjson'));
        self::assertSame($eventsBeforeRestart, self::manifestAt($directory)->eventCount);
        $checkpoint = self::durableCheckpoint($directory);
        self::assertSame('streaming', $checkpoint->phase);
        self::assertTrue($checkpoint->continuity);
        $pending = $checkpoint->pendingEvent;
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame($events[$replayedPositions[0]]->eventId, $pending->eventId);
        self::assertSame(
            $durablePending,
            $pending->channel === PaperMarketDataChannel::TOP_OF_BOOK
                ? 'book_' . $pending->payload['source_time']
                : 'trade_' . $pending->payload['trade_id'],
        );
        if ($pending->channel === PaperMarketDataChannel::TOP_OF_BOOK) {
            self::assertTrue($checkpoint->pendingContinuation['durable_batch'] ?? null);
            self::assertCount(2, $checkpoint->pendingContinuation['remaining_events'] ?? []);
        }

        [$manifest, $replayed, $transport] = $this->restart($crashRoot);
        self::assertSame(
            array_map(static fn (int $position): string => $events[$position]->eventId, $replayedPositions),
            $replayed,
        );
        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame($eventsAfterRestart, $manifest->eventCount);
        self::assertSame(self::prefix($referenceEvents, $eventsAfterRestart), file_get_contents($directory . '/events.ndjson'));
        self::assertSame(0, $transport->connectCount);
        $failed = self::durableCheckpoint($directory);
        self::assertSame('failed', $failed->phase);
        self::assertFalse($failed->continuity);
    }

    /**
     * A burst of books larger than one batch goes through the real capture: a full batch is
     * one recorder batch, the rest follows before the next trade, and the completed dataset
     * passes the canonical verifier with every book in arrival order.
     */
    public function testABurstOfBooksIsRecordedInFullBatchesAndCompletes(): void
    {
        $books = HyperliquidPaperLivePolicy::MAX_BOOK_BATCH_EVENTS + 10;
        $frames = [self::candleFrame(0, '2'), self::tradeFrame(42, 1_000)];
        for ($index = 0; $index < $books; ++$index) {
            $frames[] = self::bboFrame($index % 2 === 0 ? 'BTC' : 'ETH', 1_001 + $index, (string) (60_000 + $index));
        }
        $frames[] = self::tradeFrame(43, 1_001 + $books);
        $frames[] = self::candleFrame(60_000, '3');

        $manifest = $this->capture($this->root, $frames, killWhenIdle: false);

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(6 + 1 + $books + 1 + 1, $manifest->eventCount);
        $contents = file_get_contents($this->root . '/' . self::DATASET_ID . '/events.ndjson');
        self::assertIsString($contents);
        $sourceTimes = [];
        foreach (self::events($contents) as $event) {
            if ($event->channel === PaperMarketDataChannel::TOP_OF_BOOK) {
                $sourceTimes[] = (int) $event->payload['source_time'];
            }
        }
        self::assertSame(range(1_001, 1_000 + $books), $sourceTimes);
    }

    /**
     * Runs the capture in a child process that SIGKILLs itself at the given point (idle
     * when null) and asserts that it died that way.
     *
     * @param list<string> $frames
     */
    private function crash(string $root, array $frames, ?string $point): void
    {
        self::assertTrue(mkdir($root, 0700));
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            try {
                $this->capture($root, $frames, killWhenIdle: $point === null, killPoint: $point);
                file_put_contents($root . '/child-error', 'capture ended without the crash');
            } catch (\Throwable $failure) {
                file_put_contents($root . '/child-error', $failure::class . ': ' . $failure->getMessage());
            }
            posix_kill(getmypid(), \SIGKILL);
        }
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        if (is_file($root . '/child-error')) {
            self::fail('Child failed before the crash: ' . file_get_contents($root . '/child-error'));
        }
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(\SIGKILL, pcntl_wtermsig($status));
        self::assertFileExists($root . '/killed-mid-stream');
    }

    /**
     * Restarts the crashed attempt through the capture, on the same recorder and the durable
     * checkpoint: the capture marks the dataset incomplete and rethrows the source failure.
     * Returns the stored manifest and the events made durable again before the failure.
     *
     * @return array{PaperDatasetManifest, list<string>, CrashDurabilityHyperliquidTransport}
     */
    private function restart(string $root): array
    {
        $recorder = self::recorder($root);
        $transport = new CrashDurabilityHyperliquidTransport([]);
        $replayed = [];
        $failure = null;
        try {
            (new PaperPublicDatasetCapture())->run(
                $recorder,
                self::source($recorder->datasetDirectory(), $transport, new CrashDurabilityLoop()),
                static function (PaperMarketEvent $event) use (&$replayed): void {
                    $replayed[] = $event->eventId;
                },
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure, 'A streaming checkpoint must not resume.');
        self::assertSame('hyperliquid_public_trade_gap_unrecoverable', $failure->getMessage());

        return [self::manifestAt($root . '/' . self::DATASET_ID), $replayed, $transport];
    }

    /** @param list<string> $frames */
    private function capture(
        string $root,
        array $frames,
        bool $killWhenIdle,
        ?string $killPoint = null,
    ): PaperDatasetManifest {
        $recorder = self::recorder($root);
        $loop = new CrashDurabilityLoop();
        $source = self::source(
            $recorder->datasetDirectory(),
            new CrashDurabilityHyperliquidTransport($frames),
            $loop,
        );
        $loop->onIdle = static function () use ($source, $root, $killWhenIdle): void {
            if ($killWhenIdle) {
                file_put_contents($root . '/killed-mid-stream', 'idle');
                posix_kill(getmypid(), \SIGKILL);
            }
            $source->requestHealthyOperatorStop();
        };

        return (new PaperPublicDatasetCapture())->run(
            $recorder,
            $killPoint === null
                ? $source
                : new CrashPointHyperliquidSource($source, $killPoint, $root . '/killed-mid-stream'),
        );
    }

    private static function recorder(string $root): PaperDatasetRecorder
    {
        return new PaperDatasetRecorder($root, new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: 'test',
            datasetId: self::DATASET_ID,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT' => 'BTC', 'ETHUSDT' => 'ETH'],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        ));
    }

    private static function source(
        string $directory,
        HyperliquidPaperPublicWebSocketTransportInterface $transport,
        LoopInterface $loop,
    ): HyperliquidPaperPublicLiveSource {
        $store = self::store($directory);
        $checkpoint = $store->loadOrCreate(
            self::DATASET_ID,
            PaperMarketDataNetwork::MAINNET,
            HyperliquidPaperLivePolicy::configurationSha256(PaperMarketDataNetwork::MAINNET),
        );

        return new HyperliquidPaperPublicLiveSource(
            $transport,
            new HyperliquidPaperPublicConfig(
                PaperMarketDataNetwork::MAINNET,
                true,
                HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
                HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
                $directory,
            ),
            new MockClock('2026-07-29T10:00:00Z'),
            $store,
            $checkpoint,
            $loop,
            metadataClient: new CrashDurabilityMetadataClient(),
            fundingClient: new CrashDurabilityFundingClient(),
        );
    }

    private static function store(string $directory): HyperliquidPaperLiveCheckpointStore
    {
        return new HyperliquidPaperLiveCheckpointStore($directory);
    }

    private static function durableCheckpoint(string $directory): HyperliquidPaperLiveCheckpoint
    {
        return self::store($directory)->loadOrCreate(
            self::DATASET_ID,
            PaperMarketDataNetwork::MAINNET,
            HyperliquidPaperLivePolicy::configurationSha256(PaperMarketDataNetwork::MAINNET),
        );
    }

    /** The first $count lines of an events file. */
    private static function prefix(string $events, int $count): string
    {
        $lines = explode("\n", rtrim($events, "\n"));
        self::assertGreaterThanOrEqual($count, \count($lines));

        return implode('', array_map(static fn (string $line): string => $line . "\n", array_slice($lines, 0, $count)));
    }

    private static function copyTree(string $from, string $to): void
    {
        self::assertTrue(mkdir($to, 0700));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $entry) {
            $target = $to . substr($entry->getPathname(), \strlen($from));
            $entry->isDir()
                ? self::assertTrue(mkdir($target, 0700))
                : self::assertTrue(copy($entry->getPathname(), $target));
            // The recorder requires owner-only modes (0700 directories, 0600 files).
            self::assertTrue(chmod($target, $entry->getPerms() & 0777));
        }
    }

    private static function manifestAt(string $directory): PaperDatasetManifest
    {
        $contents = file_get_contents($directory . '/manifest.json');
        self::assertIsString($contents);

        return (new PaperDatasetManifestCodec())->decode($contents);
    }

    /** @return list<PaperMarketEvent> */
    private static function events(string $contents): array
    {
        $events = [];
        foreach (explode("\n", rtrim($contents, "\n")) as $line) {
            $data = json_decode($line, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
            self::assertIsArray($data);
            /** @var array<string, mixed> $data */
            $events[] = PaperMarketEvent::fromArray($data);
        }

        return $events;
    }

    private static function tradeFrame(int $tid = 42, int $time = 1_000): string
    {
        return CanonicalJson::encode(['channel' => 'trades', 'data' => [[
            'coin' => 'BTC',
            'side' => 'B',
            'px' => '65000',
            'sz' => '0.01',
            'hash' => $tid === 42 ? '0xabc' : '0x' . hash('sha256', 'trade-' . $tid),
            'time' => $time,
            'tid' => $tid,
            'users' => ['0xa', '0xb'],
        ]]]);
    }

    private static function bboFrame(string $coin, int $time, string $bid): string
    {
        return CanonicalJson::encode(['channel' => 'bbo', 'data' => [
            'bbo' => [
                ['n' => 1, 'px' => $bid, 'sz' => '1'],
                ['n' => 1, 'px' => (string) ((int) $bid + 2), 'sz' => '2'],
            ],
            'coin' => $coin,
            'time' => $time,
        ]]);
    }

    private static function bookFrame(): string
    {
        return CanonicalJson::encode([
            'channel' => 'l2Book',
            'data' => [
                'coin' => 'BTC',
                'levels' => [
                    [['px' => '64999', 'sz' => '1', 'n' => 1]],
                    [['px' => '65001', 'sz' => '2', 'n' => 1]],
                ],
                'time' => 1_001,
            ],
        ]);
    }

    private static function candleFrame(int $start, string $close): string
    {
        return CanonicalJson::encode([
            'channel' => 'candle',
            'data' => [
                'T' => $start + 59_999,
                'c' => $close,
                'h' => '4',
                'i' => '1m',
                'l' => '0.5',
                'n' => 5,
                'o' => '1',
                's' => 'BTC',
                't' => $start,
                'v' => '4',
            ],
        ]);
    }
}

/**
 * Delegates to the live source and SIGKILLs the process at one point of the first book
 * batch, as seen by the capture: when the batch is pending on disk, after the first book is
 * acknowledged in memory, after the recorder appended the batch, or after its last book is
 * acknowledged.
 */
final class CrashPointHyperliquidSource implements PaperDurableBatchSourceInterface
{
    private ?PaperMarketEvent $current = null;
    private bool $firstBookSeen = false;

    public function __construct(
        private readonly HyperliquidPaperPublicLiveSource $source,
        private readonly string $point,
        private readonly string $marker,
    ) {
    }

    public function venue(): PaperMarketDataVenue
    {
        return $this->source->venue();
    }

    public function events(): iterable
    {
        foreach ($this->source->events() as $event) {
            $this->current = $event;
            if ($this->isBook() && !$this->firstBookSeen) {
                $this->firstBookSeen = true;
                if ($this->point === 'pending_saved') {
                    $this->kill();
                }
            }
            yield $event;
        }
    }

    public function pendingDurableBatchSize(): int
    {
        return $this->source->pendingDurableBatchSize();
    }

    public function acknowledge(string $eventId): void
    {
        $lastOfBatch = $this->source->pendingDurableBatchSize() === 1;
        if ($this->point === 'appended' && $this->isBook() && $lastOfBatch) {
            $this->kill();
        }
        $this->source->acknowledge($eventId);
        if (($this->point === 'mid_batch' && $this->isBook() && !$lastOfBatch)
            || ($this->point === 'acknowledged' && $this->isBook() && $lastOfBatch)
        ) {
            $this->kill();
        }
    }

    public function stop(): void
    {
        $this->source->stop();
    }

    public function isComplete(): bool
    {
        return $this->source->isComplete();
    }

    public function requestHealthyOperatorStop(): void
    {
        $this->source->requestHealthyOperatorStop();
    }

    public function failureReason(): ?string
    {
        return $this->source->failureReason();
    }

    private function isBook(): bool
    {
        return $this->current?->channel === PaperMarketDataChannel::TOP_OF_BOOK;
    }

    private function kill(): void
    {
        file_put_contents($this->marker, $this->point);
        posix_kill(getmypid(), \SIGKILL);
    }
}

final class CrashDurabilityHyperliquidTransport implements HyperliquidPaperPublicWebSocketTransportInterface
{
    public int $connectCount = 0;
    private int $subscriptionCount = 0;

    /** @var callable(string): void|null */
    private $onMessage = null;

    /** @param list<string> $marketFrames */
    public function __construct(private readonly array $marketFrames)
    {
    }

    public function connect(callable $onOpen, callable $onMessage, callable $onClose, callable $onError): void
    {
        ++$this->connectCount;
        $this->onMessage = $onMessage;
        $onOpen();
    }

    public function send(array $message): void
    {
        if ($message === ['method' => 'ping']) {
            return;
        }
        $onMessage = $this->onMessage ?? throw new \LogicException();
        $onMessage(CanonicalJson::encode(['channel' => 'subscriptionResponse', 'data' => $message]));
        if (++$this->subscriptionCount === 12) {
            foreach ($this->marketFrames as $frame) {
                $onMessage($frame);
            }
        }
    }

    public function close(): void
    {
    }

    public function pauseReading(): void
    {
    }

    public function resumeReading(): void
    {
    }

    public function stopIngress(): void
    {
    }
}

/** Runs the idle callback whenever the source waits for the network. */
final class CrashDurabilityLoop implements LoopInterface
{
    public ?\Closure $onIdle = null;

    public function run(): void
    {
        if ($this->onIdle !== null) {
            ($this->onIdle)();
        }
    }

    public function stop(): void
    {
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        return new Timer((float) $interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        return new Timer((float) $interval, $callback, true);
    }

    public function cancelTimer(TimerInterface $timer): void
    {
    }

    public function futureTick($listener): void
    {
        $listener();
    }

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addSignal($signal, $listener): void
    {
    }

    public function removeSignal($signal, $listener): void
    {
    }
}

final class CrashDurabilityMetadataClient implements HyperliquidPaperInstrumentMetadataClientInterface
{
    public function instrumentMetadata(): array
    {
        return [
            ['coin' => 'BTC', 'asset_id' => 0, 'sz_decimals' => 5, 'max_leverage' => 50],
            ['coin' => 'ETH', 'asset_id' => 1, 'sz_decimals' => 4, 'max_leverage' => 25],
        ];
    }
}

final class CrashDurabilityFundingClient implements HyperliquidPaperFundingRateClientInterface
{
    public function fundingRates(): array
    {
        return [
            ['coin' => 'BTC', 'funding_rate' => '0.0000125'],
            ['coin' => 'ETH', 'funding_rate' => '-0.000025'],
        ];
    }
}
