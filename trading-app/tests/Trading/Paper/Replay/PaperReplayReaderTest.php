<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayCheckpoint;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperReplayReader::class)]
final class PaperReplayReaderTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-replay-reader-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create private replay reader test directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            self::fail('Unable to resolve replay reader test directory.');
        }
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testVerifiesCompleteDatasetBeforeYieldingAnything(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $clock = new PaperReplayClock(new \DateTimeImmutable('2026-07-19T09:00:00Z'));
        $reader = $this->reader($clock);
        $yielded = 0;

        try {
            foreach ($reader->read($recorder->datasetDirectory(), 'paper.worker-01') as $_event) {
                ++$yielded;
            }
            self::fail('A RECORDING dataset must be rejected before replay.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_complete', $exception->getMessage());
        }

        self::assertSame(0, $yielded);
        self::assertNull($reader->currentEventIndex());
        self::assertSame('2026-07-19T09:00:00.000000Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testSortsByExactBusinessKeyAndIgnoresReceivedTimestamp(): void
    {
        $timestamp = '2026-07-19T10:00:00.000000Z';
        $later = $this->event('BTCUSDT', PaperMarketDataChannel::CANDLE_1M, '1', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z');
        $topTen = $this->event('BTCUSDT', PaperMarketDataChannel::TOP_OF_BOOK, '10', $timestamp, '2026-07-19T10:00:00.100000Z');
        $topTwo = $this->event('ETHUSDT', PaperMarketDataChannel::TOP_OF_BOOK, '2', $timestamp, '2026-07-19T10:00:00.200000Z');
        $topNull = $this->event('BTCUSDT', PaperMarketDataChannel::TOP_OF_BOOK, null, $timestamp, '2026-07-19T10:00:00.000001Z', ['kind' => 'null']);
        $tradeOneBtc = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', $timestamp, '2026-07-19T10:00:00.900000Z');
        $tradeOneEth = $this->event('ETHUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', $timestamp, '2026-07-19T10:00:00.800000Z');
        $tradeTwo = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', $timestamp, '2026-07-19T10:00:00.000001Z');
        $candle = $this->event('ETHUSDT', PaperMarketDataChannel::CANDLE_1M, null, $timestamp, '2026-07-19T10:00:00.999999Z');
        $dataset = $this->completeDataset([
            $later,
            $topTen,
            $topTwo,
            $topNull,
            $tradeOneBtc,
            $tradeOneEth,
            $tradeTwo,
            $candle,
        ]);
        $clock = new PaperReplayClock(new \DateTimeImmutable($timestamp));
        $reader = $this->reader($clock);
        $actual = [];

        foreach ($reader->read($dataset['directory'], 'paper.worker-01') as $event) {
            self::assertEquals($event->exchangeTimestamp, $clock->now());
            self::assertSame(count($actual), $reader->currentEventIndex());
            $actual[] = $event->eventId;
        }

        $sequenceOne = [$tradeOneBtc->eventId, $tradeOneEth->eventId];
        sort($sequenceOne, SORT_STRING);
        self::assertSame([
            $candle->eventId,
            ...$sequenceOne,
            $tradeTwo->eventId,
            $topTwo->eventId,
            $topTen->eventId,
            $topNull->eventId,
            $later->eventId,
        ], $actual);
        self::assertSame(7, $reader->currentEventIndex());
    }

    public function testResumeSkipsExactlyThroughCheckpointAndYieldsTheFollowingEvent(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '3', '2026-07-19T10:00:02.000000Z', '2026-07-19T10:00:02.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $checkpoint = $this->checkpoint($dataset['manifest'], 'paper.worker-01', $events[1], 1);
        $store = new PaperReplayCheckpointStore();
        $store->save($dataset['directory'], $checkpoint);
        $clock = new PaperReplayClock($events[1]->exchangeTimestamp);
        $reader = new PaperReplayReader(new PaperDatasetVerifier(), $store, $clock);

        $yielded = iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);

        self::assertSame([$events[2]->eventId], array_map(
            static fn (PaperMarketEvent $event): string => $event->eventId,
            $yielded,
        ));
        self::assertSame(2, $reader->currentEventIndex());
        self::assertEquals($events[2]->exchangeTimestamp, $clock->now());
    }

    public function testRejectsCheckpointDatasetChecksumAndConsumerMismatches(): void
    {
        $event = $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z');
        $dataset = $this->completeDataset([$event]);
        $cases = [
            'paper_replay_checkpoint_dataset_mismatch' => new PaperReplayCheckpoint(
                'dataset-other-001', 'paper.worker-01', $event->eventId, 0, $event->exchangeTimestamp, $dataset['manifest']->eventsFileSha256 ?? ''
            ),
            'paper_replay_checkpoint_checksum_mismatch' => new PaperReplayCheckpoint(
                $dataset['manifest']->datasetId, 'paper.worker-01', $event->eventId, 0, $event->exchangeTimestamp, str_repeat('f', 64)
            ),
            'paper_replay_checkpoint_consumer_mismatch' => new PaperReplayCheckpoint(
                $dataset['manifest']->datasetId, 'paper.worker-02', $event->eventId, 0, $event->exchangeTimestamp, $dataset['manifest']->eventsFileSha256 ?? ''
            ),
        ];

        foreach ($cases as $error => $checkpoint) {
            $reader = $this->reader(new PaperReplayClock($event->exchangeTimestamp));
            try {
                iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01', $checkpoint), false);
                self::fail('Foreign checkpoints must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame($error, $exception->getMessage());
                self::assertNull($reader->currentEventIndex());
            }
        }
    }

    public function testRejectsCheckpointEventMissingOrAtAnIncoherentIndex(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $missing = new PaperReplayCheckpoint(
            $dataset['manifest']->datasetId,
            'paper.worker-01',
            str_repeat('f', 64),
            0,
            $events[0]->exchangeTimestamp,
            $dataset['manifest']->eventsFileSha256 ?? '',
        );
        $wrongIndex = $this->checkpoint($dataset['manifest'], 'paper.worker-01', $events[0], 1);

        foreach (['paper_replay_checkpoint_event_not_found' => $missing, 'paper_replay_checkpoint_event_mismatch' => $wrongIndex] as $error => $checkpoint) {
            $reader = $this->reader(new PaperReplayClock($events[0]->exchangeTimestamp));
            try {
                iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01', $checkpoint), false);
                self::fail('An incoherent checkpoint event must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame($error, $exception->getMessage());
            }
        }
    }

    public function testRejectsConfiguredEventLimitBeforeSortingOrYielding(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $clock = new PaperReplayClock($events[0]->exchangeTimestamp);
        $reader = new PaperReplayReader(new PaperDatasetVerifier(), new PaperReplayCheckpointStore(), $clock, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_replay_event_limit_exceeded');

        iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
    }

    public function testClockRegressionDoesNotExposeAnEventIndexThatWasNotYielded(): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $reader = $this->reader(new PaperReplayClock(new \DateTimeImmutable('2026-07-19T10:00:01Z')));

        try {
            iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
            self::fail('A clock initialized after the first event must reject replay regression.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_replay_clock_regression', $exception->getMessage());
        }

        self::assertNull($reader->currentEventIndex());
    }

    public function testRejectsEventsPathSubstitutionAfterVerifiedOpen(): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $eventsPath = $dataset['directory'] . '/events.ndjson';
        $replacementPath = $this->testRoot . '/replacement-events.ndjson';
        self::assertTrue(copy($eventsPath, $replacementPath));
        self::assertTrue(chmod($replacementPath, 0600));
        $filesystem = new EventsPathSwapFilesystem($eventsPath, $replacementPath);
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($event->exchangeTimestamp),
            PaperReplayReader::DEFAULT_EVENT_LIMIT,
            filesystem: $filesystem,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_symlink_rejected');

        iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
    }

    public function testRejectsDatasetDirectorySubstitutionBetweenVerifyAndReadBeforeYield(): void
    {
        $event = $this->event(
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            '1',
            '2026-07-19T10:00:00.000000Z',
            '2026-07-19T10:00:00.100000Z',
        );
        $dataset = $this->completeDataset([$event]);
        $filesystem = new DatasetDirectorySwapOnEventsOpenFilesystem(
            $dataset['directory'],
            $this->testRoot . '/displaced-before-read',
        );
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($event->exchangeTimestamp),
            filesystem: $filesystem,
        );

        try {
            iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
            self::fail('A substituted dataset directory must be rejected before any yield.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }

        self::assertNull($reader->currentEventIndex());
    }

    public function testRejectsDatasetDirectorySubstitutionImmediatelyAfterCheckpointLoad(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        (new PaperReplayCheckpointStore())->save(
            $dataset['directory'],
            $this->checkpoint($dataset['manifest'], 'paper.worker-01', $events[0], 0),
        );
        $filesystem = new DatasetDirectorySwapAtBoundaryFilesystem(
            $dataset['directory'],
            $this->testRoot . '/displaced-after-load',
            'paper_replay_dataset_after_checkpoint_load',
        );
        $reader = new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            new PaperReplayClock($events[0]->exchangeTimestamp),
            filesystem: $filesystem,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_directory_changed');

        iterator_to_array($reader->read($dataset['directory'], 'paper.worker-01'), false);
    }

    public function testRevalidatesPinnedDatasetBeforeEveryYieldAfterGeneratorResume(): void
    {
        $events = [
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '1', '2026-07-19T10:00:00.000000Z', '2026-07-19T10:00:00.100000Z'),
            $this->event('BTCUSDT', PaperMarketDataChannel::PUBLIC_TRADE, '2', '2026-07-19T10:00:01.000000Z', '2026-07-19T10:00:01.100000Z'),
        ];
        $dataset = $this->completeDataset($events);
        $reader = $this->reader(new PaperReplayClock($events[0]->exchangeTimestamp));
        $generator = $reader->read($dataset['directory'], 'paper.worker-01');

        self::assertSame($events[0]->eventId, $generator->current()->eventId);
        $displaced = $this->testRoot . '/displaced-after-yield';
        self::assertTrue(rename($dataset['directory'], $displaced));
        self::assertTrue(mkdir($dataset['directory'], 0700));

        try {
            $generator->next();
            self::fail('A dataset swap while the generator is suspended must block the next yield.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }

        self::assertFalse($generator->valid());
    }

    private function reader(PaperReplayClock $clock): PaperReplayReader
    {
        return new PaperReplayReader(
            new PaperDatasetVerifier(),
            new PaperReplayCheckpointStore(),
            $clock,
        );
    }

    /** @param list<PaperMarketEvent> $events
     *  @return array{directory: string, manifest: PaperDatasetManifest}
     */
    private function completeDataset(array $events): array
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        foreach ($events as $event) {
            $recorder->append($event);
        }

        return ['directory' => $recorder->datasetDirectory(), 'manifest' => $recorder->complete()];
    }

    private function manifest(): PaperDatasetManifest
    {
        return new PaperDatasetManifest(
            schemaVersion: 1,
            recorderVersion: '1.0.0',
            datasetId: 'dataset-okx-001',
            venue: PaperMarketDataVenue::OKX,
            symbols: ['BTCUSDT' => 'BTC-USDT-SWAP', 'ETHUSDT' => 'ETH-USDT-SWAP'],
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
        );
    }

    /** @param array<array-key, mixed> $payload */
    private function event(
        string $symbol,
        PaperMarketDataChannel $channel,
        ?string $sequence,
        string $exchangeTimestamp,
        string $receivedTimestamp,
        array $payload = ['price' => '30000.0'],
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            PaperMarketDataVenue::OKX,
            $symbol,
            $channel,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            $payload,
        );
    }

    private function checkpoint(
        PaperDatasetManifest $manifest,
        string $consumerId,
        PaperMarketEvent $event,
        int $index,
    ): PaperReplayCheckpoint {
        return new PaperReplayCheckpoint(
            datasetId: $manifest->datasetId,
            consumerId: $consumerId,
            eventId: $event->eventId,
            eventIndex: $index,
            exchangeTimestamp: $event->exchangeTimestamp,
            eventsFileSha256: $manifest->eventsFileSha256 ?? '',
        );
    }

    private function datasetRoot(): string
    {
        return $this->testRoot . '/paper-market-data';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

final class EventsPathSwapFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function __construct(
        private readonly string $eventsPath,
        private readonly string $replacementPath,
    ) {
    }

    /** @param resource $handle
     *  @return array<string, mixed>|false
     */
    public function stat($handle, string $operation): array|false
    {
        if ($operation === 'paper_replay_events_validation' && !$this->swapped) {
            $this->swapped = true;
            if (!unlink($this->eventsPath) || !symlink($this->replacementPath, $this->eventsPath)) {
                throw new \RuntimeException('Unable to inject events pathname substitution.');
            }
        }

        return parent::stat($handle, $operation);
    }
}

final class DatasetDirectorySwapOnEventsOpenFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function __construct(
        private readonly string $datasetDirectory,
        private readonly string $displacedDirectory,
    ) {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($operation === 'paper_replay_events_validation' && !$this->swapped) {
            $this->swapped = true;
            $this->swapWithPrivateCopy();
        }

        return parent::pathStat($path, $operation);
    }

    private function swapWithPrivateCopy(): void
    {
        if (!rename($this->datasetDirectory, $this->displacedDirectory)
            || !mkdir($this->datasetDirectory, 0700)
        ) {
            throw new \RuntimeException('Unable to inject dataset directory substitution.');
        }
        foreach (['manifest.json', 'events.ndjson'] as $filename) {
            $source = $this->displacedDirectory . '/' . $filename;
            $destination = $this->datasetDirectory . '/' . $filename;
            if (!copy($source, $destination) || !chmod($destination, 0600)) {
                throw new \RuntimeException('Unable to copy substituted dataset fixture.');
            }
        }
    }
}

final class DatasetDirectorySwapAtBoundaryFilesystem extends PaperDatasetRecorderFilesystem
{
    private bool $swapped = false;

    public function __construct(
        private readonly string $datasetDirectory,
        private readonly string $displacedDirectory,
        private readonly string $boundaryOperation,
    ) {
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($operation === $this->boundaryOperation && !$this->swapped) {
            $this->swapped = true;
            if (!rename($this->datasetDirectory, $this->displacedDirectory)
                || !mkdir($this->datasetDirectory, 0700)
            ) {
                throw new \RuntimeException('Unable to inject dataset boundary substitution.');
            }
        }

        return parent::pathStat($path, $operation);
    }
}
