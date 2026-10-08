<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperLiveCheckpoint::class)]
#[CoversClass(HyperliquidPaperLiveCheckpointStore::class)]
final class HyperliquidPaperLiveCheckpointTransitionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->directory = $temporaryRoot . '/hyperliquid-transition-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testDerivedTransitionsEqualFullyValidatedStatesThroughBoundedWindows(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            $ordinals,
            new MockClock('2026-07-29T10:00:00Z'),
        );
        $checkpoint = self::fresh()->withPhase('streaming');
        self::assertCanonical($checkpoint);

        // Past both identity windows so trimming is exercised: 512 acknowledgements and
        // 1,000 remembered trade identities.
        for ($tradeId = 1; $tradeId <= 1_050; ++$tradeId) {
            $row = self::tradeRow($tradeId);
            $event = $normalizer->liveTrade($row);
            $fingerprint = $normalizer->liveTradeFingerprint($row);
            $checkpoint = $checkpoint->withOrdinals($ordinals)->withPending($event, [
                'remaining_trade_rows' => [],
                'after_ack' => ['remember_trade_identity' => $fingerprint],
            ]);
            if ($tradeId % 150 === 0) {
                self::assertCanonical($checkpoint);
            }
            $checkpoint = $checkpoint
                ->acknowledge($event->eventId)
                ->rememberTradeIdentity(
                    $fingerprint['identity_hash'],
                    $fingerprint['assignment_digest'],
                );
            if ($tradeId % 150 === 0) {
                self::assertCanonical($checkpoint);
            }
        }
        self::assertCount(
            HyperliquidPaperLiveCheckpoint::MAXIMUM_ACKNOWLEDGED_IDENTITIES,
            $checkpoint->acknowledgedIdentities,
        );
        self::assertCount(
            HyperliquidPaperLiveCheckpoint::MAXIMUM_TRADE_IDENTITIES,
            $checkpoint->tradeIdentityHistory,
        );
        self::assertCanonical($checkpoint);

        $checkpoint = $checkpoint->withCurrentCandle('BTC/1m', self::candle(60_000));
        self::assertCanonical($checkpoint);
        $checkpoint = $checkpoint->withCurrentCandle('BTC/1m', self::candle(60_000, '3'));
        $checkpoint = $checkpoint->finalizeCandle('BTC/1m', 60_000);
        self::assertArrayNotHasKey('BTC/1m', $checkpoint->currentCandles);
        self::assertCanonical($checkpoint);

        $checkpoint = $checkpoint->withHeartbeat(
            '2026-07-29T10:00:01.000000Z',
            '2026-07-29T10:00:02.000000Z',
            '2026-07-29T10:00:12.000000Z',
        );
        self::assertCanonical($checkpoint);

        $reconnecting = $checkpoint->beginReconnect('hyperliquid_paper_public_connection_closed');
        self::assertSame(2, $reconnecting->connectionEpoch);
        self::assertFalse($reconnecting->continuity);
        self::assertCanonical($reconnecting);

        $stopping = $checkpoint->requestHealthyStop();
        self::assertCanonical($stopping);
        $complete = $stopping->completeHealthyStop();
        self::assertSame('complete', $complete->phase);
        self::assertCanonical($complete);

        $failed = $checkpoint->loseContinuity('hyperliquid_public_trade_gap_unrecoverable')
            ->fail('hyperliquid_public_trade_gap_unrecoverable');
        self::assertSame('failed', $failed->phase);
        self::assertCanonical($failed);
    }

    public function testLiveOrdinalCursorIsRecordedExactlyAsItsValidatedSnapshot(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            $ordinals,
            new MockClock('2026-07-29T10:00:00Z'),
        );
        $normalizer->liveTrade(self::tradeRow(7));
        $normalizer->liveTopOfBook([
            'coin' => 'ETH',
            'levels' => [
                [['px' => '2499', 'sz' => '1', 'n' => 1]],
                [['px' => '2501', 'sz' => '2', 'n' => 1]],
            ],
            'time' => 1_001,
        ], 1);
        $checkpoint = self::fresh();

        self::assertSame(
            $checkpoint->withOrdinalState($ordinals->snapshot())->toArray(),
            $checkpoint->withOrdinals($ordinals)->toArray(),
        );
        self::assertSame(
            $ordinals->snapshot(),
            HyperliquidPaperSourceOrdinal::restore($ordinals->snapshot())->snapshot(),
        );
    }

    public function testDerivedTransitionsStillRejectInvalidState(): void
    {
        $checkpoint = self::fresh();
        $event = (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            clock: new MockClock('2026-07-29T10:00:00Z'),
        ))->liveTrade(self::tradeRow(1));
        $pending = $checkpoint->withPending($event, ['kind' => 'trade']);
        /** @var array<string, mixed> $listContinuation decoded runtime data of the wrong shape */
        $listContinuation = json_decode('["trade"]', true, 512, \JSON_THROW_ON_ERROR);

        foreach ([
            'unknown phase' => static fn () => $checkpoint->withPhase('unknown'),
            'unparseable heartbeat' => static fn () => $checkpoint->withHeartbeat('yesterday', null, null),
            'list continuation' => static fn () => $checkpoint->withPending($event, $listContinuation),
            'unencodable continuation' => static fn () => $checkpoint->withPending($event, [
                'kind' => \NAN,
            ]),
            'invalid ordinal state' => static fn () => $checkpoint->withOrdinalState([
                'schema_version' => 2,
                'scopes' => ['mainnet/hyperliquid/BTCUSDT/public_trade' => []],
            ]),
            'completion without a requested healthy stop' => static fn () => $checkpoint
                ->withPhase('streaming')
                ->completeHealthyStop(),
            'completion with a pending event' => static fn () => $pending
                ->requestHealthyStop()
                ->completeHealthyStop(),
            'acknowledging another event' => static fn () => $pending->acknowledge(str_repeat('0', 64)),
        ] as $case => $transition) {
            try {
                $transition();
                self::fail('Expected invalid transition: ' . $case);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'hyperliquid_paper_live_checkpoint_invalid',
                    $exception->getMessage(),
                    $case,
                );
            }
        }
    }

    public function testStoreWritesTheCanonicalDocumentBytesForAFullWindowCheckpoint(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $state = $checkpoint->toArray();
        $state['phase'] = 'streaming';
        $state['acknowledged_identities'] = array_map(
            static fn (int $index): string => hash('sha256', 'ack-' . $index),
            range(1, HyperliquidPaperLiveCheckpoint::MAXIMUM_ACKNOWLEDGED_IDENTITIES),
        );
        $state['trade_identity_history'] = array_map(
            static fn (int $index): array => [
                'identity_hash' => hash('sha256', 'identity-' . $index),
                'assignment_digest' => hash('sha256', 'assignment-' . $index),
            ],
            range(1, HyperliquidPaperLiveCheckpoint::MAXIMUM_TRADE_IDENTITIES),
        );
        $full = HyperliquidPaperLiveCheckpoint::fromArray($state)
            ->withCurrentCandle('ETH/5m', self::candle(300_000, '2', 'ETH', '5m'));

        $store->save($full);

        $contents = file_get_contents($this->directory . '/checkpoints/hyperliquid-live.json');
        self::assertSame(
            CanonicalJson::encode([
                'sha256' => hash('sha256', CanonicalJson::encode($full->toArray())),
                'state' => $full->toArray(),
            ]) . "\n",
            $contents,
        );
        $reloaded = (new HyperliquidPaperLiveCheckpointStore($this->directory))->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        // Durable maps come back in canonical key order, so compare canonical encodings.
        self::assertSame(
            CanonicalJson::encode($full->toArray()),
            CanonicalJson::encode($reloaded->toArray()),
        );
    }

    public function testOversizedDerivedStateIsRejectedBeforeItBecomesDurable(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $state = $checkpoint->toArray();
        $state['trade_identity_history'] = array_map(
            static fn (int $index): array => [
                'identity_hash' => hash('sha256', 'identity-' . $index),
                'assignment_digest' => hash('sha256', 'assignment-' . $index),
            ],
            range(1, HyperliquidPaperLiveCheckpoint::MAXIMUM_TRADE_IDENTITIES),
        );
        $event = (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            clock: new MockClock('2026-07-29T10:00:00Z'),
        ))->liveTrade(self::tradeRow(1));
        // Each piece is individually encodable, but together they exceed the durable bound.
        $oversized = HyperliquidPaperLiveCheckpoint::fromArray($state)->withPending($event, [
            'blob' => str_repeat('a', 900_000),
        ]);
        $path = $this->directory . '/checkpoints/hyperliquid-live.json';
        $before = file_get_contents($path);

        try {
            $store->save($oversized);
            self::fail('An oversized checkpoint must never be persisted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_live_checkpoint_invalid', $exception->getMessage());
        }
        self::assertSame($before, file_get_contents($path));
        try {
            HyperliquidPaperLiveCheckpoint::fromArray($oversized->toArray());
            self::fail('Full validation must reject the same oversized state.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_paper_live_checkpoint_invalid', $exception->getMessage());
        }
    }

    private static function assertCanonical(HyperliquidPaperLiveCheckpoint $checkpoint): void
    {
        self::assertSame(
            $checkpoint->toArray(),
            HyperliquidPaperLiveCheckpoint::fromArray($checkpoint->toArray())->toArray(),
        );
    }

    /** @return array<string, mixed> */
    private static function tradeRow(int $tradeId): array
    {
        return [
            'coin' => $tradeId % 2 === 0 ? 'BTC' : 'ETH',
            'side' => $tradeId % 3 === 0 ? 'A' : 'B',
            'px' => $tradeId % 2 === 0 ? '65000' : '2500',
            'sz' => '0.01',
            'hash' => '0x' . hash('sha256', 'trade-' . $tradeId),
            'time' => 1_000 + $tradeId,
            'tid' => $tradeId,
            'users' => ['0xa', '0xb'],
        ];
    }

    /** @return array<string, mixed> */
    private static function candle(
        int $start,
        string $close = '2',
        string $coin = 'BTC',
        string $interval = '1m',
    ): array {
        $duration = $interval === '5m' ? 300_000 : 60_000;

        return [
            'T' => $start + $duration - 1,
            'c' => $close,
            'h' => '3',
            'i' => $interval,
            'l' => '0.5',
            'n' => 5,
            'o' => '1',
            's' => $coin,
            't' => $start,
            'v' => '4',
        ];
    }

    private static function fresh(): HyperliquidPaperLiveCheckpoint
    {
        return HyperliquidPaperLiveCheckpoint::fresh(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
    }
}
