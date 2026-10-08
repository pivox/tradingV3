<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperLiveCheckpoint::class)]
#[CoversClass(HyperliquidPaperLiveCheckpointStore::class)]
final class HyperliquidPaperLiveCheckpointStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->directory = $temporaryRoot . '/hyperliquid-live-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testFreshRoundTripPinsTheCanonicalShapeAndNetwork(): void
    {
        $checkpoint = self::fresh();

        self::assertSame(8, $checkpoint->policyVersion);
        self::assertSame([
            'schema_version', 'policy_version', 'dataset_id', 'network',
            'configuration_sha256', 'phase', 'failure_reason', 'continuity',
            'connection_epoch', 'source_epoch', 'subscriptions',
            'ordinal_state', 'pending_event', 'pending_continuation',
            'current_candles', 'finalized_candle_frontiers',
            'initial_candle_window_ends',
            'acknowledged_identities', 'trade_identity_history',
            'reconnect_attempt',
            'heartbeat', 'healthy_stop', 'rotation',
        ], array_keys($checkpoint->toArray()));
        self::assertNull($checkpoint->rotation);
        self::assertSame(
            $checkpoint->toArray(),
            HyperliquidPaperLiveCheckpoint::fromArray($checkpoint->toArray())->toArray(),
        );
        self::assertSame(PaperMarketDataNetwork::MAINNET, $checkpoint->network);
        self::assertCount(12, $checkpoint->subscriptions);
        self::assertSame(
            ['BTC' => null, 'ETH' => null],
            $checkpoint->initialCandleWindowEnds,
        );
    }

    public function testInitialCandleWindowEndsAreExactAndImmutable(): void
    {
        $fresh = self::fresh();
        $pinned = $fresh->withInitialCandleWindowEnds([
            'BTC' => '1785290399999',
            'ETH' => '1785290399999',
        ]);

        self::assertSame([
            'BTC' => '1785290399999',
            'ETH' => '1785290399999',
        ], $pinned->initialCandleWindowEnds);
        self::assertSame($pinned->toArray(), $pinned->withInitialCandleWindowEnds(
            $pinned->initialCandleWindowEnds,
        )->toArray());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_live_checkpoint_invalid');
        $pinned->withInitialCandleWindowEnds([
            'BTC' => '1785293999999',
            'ETH' => '1785290399999',
        ]);
    }

    public function testLegacyRawHashPayloadPolicyCannotResumeIntoNibbleLineage(): void
    {
        $legacy = self::fresh()->toArray();
        $legacy['policy_version'] = 1;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_live_checkpoint_invalid');

        HyperliquidPaperLiveCheckpoint::fromArray($legacy);
    }

    public function testPendingReplayAcknowledgementAndCandleStateAreImmutable(): void
    {
        $checkpoint = self::fresh();
        $event = (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            clock: new MockClock('2026-07-29T10:00:00Z'),
        ))->liveTrade([
            'coin' => 'BTC',
            'side' => 'B',
            'px' => '65000',
            'sz' => '0.01',
            'hash' => '0xabc',
            'time' => 1_000,
            'tid' => 42,
            'users' => ['0xa', '0xb'],
        ]);

        $pending = $checkpoint->withPending($event, ['kind' => 'trade']);
        self::assertNull($checkpoint->pendingEvent);
        self::assertSame($event->toArray(), $pending->pendingEvent?->toArray());
        self::assertSame($pending->toArray(), $pending->withPending(
            $event,
            ['kind' => 'trade'],
        )->toArray());

        $current = $pending->withCurrentCandle('BTC/1m', self::candle(0));
        $finalized = $current->finalizeCandle('BTC/1m', 0);
        self::assertSame(0, $finalized->finalizedCandleFrontiers['BTC/1m']);
        self::assertArrayNotHasKey('BTC/1m', $finalized->currentCandles);

        $acknowledged = $finalized->acknowledge($event->eventId);
        self::assertNull($acknowledged->pendingEvent);
        self::assertContains($event->eventId, $acknowledged->acknowledgedIdentities);
    }

    public function testTradeIdentityHistoryIsDurableBoundedAndConflictSensitive(): void
    {
        $identity = hash('sha256', 'mainnet|BTC|1000|42');
        $digest = hash('sha256', 'canonical-trade');
        $checkpoint = self::fresh()->rememberTradeIdentity($identity, $digest);

        self::assertSame([
            [
                'identity_hash' => $identity,
                'assignment_digest' => $digest,
            ],
        ], $checkpoint->tradeIdentityHistory);
        self::assertSame(
            $checkpoint->toArray(),
            $checkpoint->rememberTradeIdentity($identity, $digest)->toArray(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_paper_natural_identity_conflict');
        $checkpoint->rememberTradeIdentity(
            $identity,
            hash('sha256', 'conflicting-trade'),
        );
    }

    public function testConfiguredIdentityWindowsFitTogetherInTheCheckpointBound(): void
    {
        self::assertSame(
            512,
            HyperliquidPaperLiveCheckpoint::MAXIMUM_ACKNOWLEDGED_IDENTITIES,
        );
        self::assertSame(
            2 * HyperliquidPaperLivePolicy::MAX_ACKNOWLEDGED_IDENTITIES_PER_STREAM,
            HyperliquidPaperLiveCheckpoint::MAXIMUM_TRADE_IDENTITIES,
        );
        $state = self::fresh()->toArray();
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

        $checkpoint = HyperliquidPaperLiveCheckpoint::fromArray($state);

        self::assertCount(
            HyperliquidPaperLiveCheckpoint::MAXIMUM_ACKNOWLEDGED_IDENTITIES,
            $checkpoint->acknowledgedIdentities,
        );
        self::assertCount(
            HyperliquidPaperLiveCheckpoint::MAXIMUM_TRADE_IDENTITIES,
            $checkpoint->tradeIdentityHistory,
        );
        self::assertLessThanOrEqual(
            250_000,
            strlen(CanonicalJson::encode($checkpoint->toArray())),
        );
    }

    /**
     * The largest state a book batch makes pending: full identity windows, the eight current
     * candles, every ordinal scope holding its largest event, and a full batch of books (the
     * first pending, the others in the continuation). It saves and reloads through the
     * store's canonical budget, where the node count binds first, with room to spare; and a
     * full batch stays one recorder batch.
     */
    public function testAFullBookBatchFitsThePendingCheckpointAtTheIdentityBounds(): void
    {
        $state = self::fresh()->toArray();
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
        $checkpoint = HyperliquidPaperLiveCheckpoint::fromArray($state);
        $ordinals = HyperliquidPaperSourceOrdinal::restore($checkpoint->ordinalState);
        $normalizer = new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            $ordinals,
            new MockClock('2026-07-29T10:00:00Z'),
        );
        foreach (['BTC' => 0, 'ETH' => 1] as $coin => $assetId) {
            $normalizer->instrumentMetadata(
                ['asset_id' => $assetId, 'coin' => $coin, 'max_leverage' => 50, 'sz_decimals' => 5],
                1,
            );
            $normalizer->fundingRate(['coin' => $coin, 'funding_rate' => '0.0000125'], 1);
            $normalizer->snapshotBoundary($coin, 'initial', 1);
            $normalizer->liveTrade([
                'coin' => $coin,
                'side' => 'B',
                'px' => '65000.5',
                'sz' => '0.00123',
                'hash' => '0x' . hash('sha256', 'block-' . $coin),
                'time' => 1_785_319_199_000,
                'tid' => 999_999_999_999_999,
                'users' => ['0x' . str_repeat('a', 40), '0x' . str_repeat('b', 40)],
            ]);
            foreach (['1m', '5m', '15m', '1h'] as $interval) {
                $row = self::candle(0, $coin, $interval);
                $checkpoint = $checkpoint->withCurrentCandle($coin . '/' . $interval, $row);
                $normalizer->closedLiveCandle(HyperliquidCandle::fromApiRow($row, $coin, $interval));
            }
        }
        $books = [];
        for ($index = 0; $index < HyperliquidPaperLivePolicy::MAX_BOOK_BATCH_EVENTS; ++$index) {
            $books[] = $normalizer->liveTopOfBookFromBbo([
                'bbo' => [
                    ['n' => 12, 'px' => '65000.5', 'sz' => '12.34567'],
                    ['n' => 34, 'px' => '65001.5', 'sz' => '76.54321'],
                ],
                'coin' => $index % 2 === 0 ? 'BTC' : 'ETH',
                'time' => 1_785_319_199_000 + $index,
            ], 1);
        }
        $first = array_shift($books);
        $pending = $checkpoint->withOrdinals($ordinals)->withPending($first, [
            'remaining_events' => array_map(static fn (PaperMarketEvent $book): array => $book->toArray(), $books),
            'after_ack' => null,
            'durable_batch' => true,
        ]);

        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $store->loadOrCreate('paper-hyperliquid-live-mainnet', PaperMarketDataNetwork::MAINNET, str_repeat('a', 64));
        $saved = $store->save($pending);
        $reloaded = (new HyperliquidPaperLiveCheckpointStore($this->directory))->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );

        self::assertSame(CanonicalJson::encode($saved->toArray()), CanonicalJson::encode($reloaded->toArray()));
        self::assertCount(18, $reloaded->ordinalState['scopes'] ?? []);
        self::assertCount(8, $reloaded->currentCandles);
        self::assertCount(
            HyperliquidPaperLivePolicy::MAX_BOOK_BATCH_EVENTS - 1,
            $reloaded->pendingContinuation['remaining_events'] ?? [],
        );
        // Measured: 15 756 nodes, 5 656 keys, 332 427 bytes.
        self::assertLessThan(0.85 * CanonicalJson::MAX_NODES, self::canonicalNodes($reloaded->toArray()));
        self::assertLessThan(0.85 * CanonicalJson::MAX_KEYS, self::canonicalKeys($reloaded->toArray()));
        self::assertLessThan(0.5 * HyperliquidPaperLiveCheckpoint::MAXIMUM_BYTES, strlen(CanonicalJson::encode($reloaded->toArray())));
        self::assertLessThanOrEqual(
            (new \ReflectionClassConstant(PaperDatasetRecorder::class, 'MAX_APPEND_BATCH_EVENTS'))->getValue(),
            HyperliquidPaperLivePolicy::MAX_BOOK_BATCH_EVENTS,
        );
    }

    public function testStorePublishesCanonicalChecksummedStateAndReloads(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $store->save($checkpoint->withCurrentCandle('ETH/5m', self::candle(0, 'ETH', '5m')));

        $reloaded = (new HyperliquidPaperLiveCheckpointStore($this->directory))
            ->loadOrCreate(
                'paper-hyperliquid-live-mainnet',
                PaperMarketDataNetwork::MAINNET,
                str_repeat('a', 64),
            );
        self::assertArrayHasKey('ETH/5m', $reloaded->currentCandles);

        $path = $this->directory . '/checkpoints/hyperliquid-live.json';
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $document = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(
            hash('sha256', CanonicalJson::encode($document['state'])),
            $document['sha256'],
        );
        self::assertSame(CanonicalJson::encode($document) . "\n", $contents);
        self::assertSame([], glob($this->directory . '/checkpoints/.hyperliquid-live-*.tmp'));
    }

    public function testMismatchCorruptionAndUnsafeFilesFailClosed(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );

        foreach ([
            ['paper-hyperliquid-live-testnet', PaperMarketDataNetwork::MAINNET, str_repeat('a', 64)],
            ['paper-hyperliquid-live-mainnet', PaperMarketDataNetwork::TESTNET, str_repeat('a', 64)],
            ['paper-hyperliquid-live-mainnet', PaperMarketDataNetwork::MAINNET, str_repeat('b', 64)],
        ] as [$dataset, $network, $configuration]) {
            try {
                (new HyperliquidPaperLiveCheckpointStore($this->directory))->loadOrCreate(
                    $dataset,
                    $network,
                    $configuration,
                );
                self::fail('Expected checkpoint mismatch.');
            } catch (\RuntimeException $exception) {
                self::assertSame('hyperliquid_paper_live_checkpoint_invalid', $exception->getMessage());
            }
        }

        $path = $this->directory . '/checkpoints/hyperliquid-live.json';
        file_put_contents($path, '{"sha256":"broken"');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_paper_live_checkpoint_invalid');
        (new HyperliquidPaperLiveCheckpointStore($this->directory))->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
    }

    public function testInvalidStateAndDuplicateConflictFailClosed(): void
    {
        $state = self::fresh()->toArray();
        foreach ([
            ['phase', 'unknown'],
            ['connection_epoch', 0],
            ['network', 'legacy_unknown'],
        ] as [$key, $value]) {
            $invalid = $state;
            $invalid[$key] = $value;
            try {
                HyperliquidPaperLiveCheckpoint::fromArray($invalid);
                self::fail('Expected invalid checkpoint state.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'hyperliquid_paper_live_checkpoint_invalid',
                    $exception->getMessage(),
                );
            }
        }

        $event = (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            clock: new MockClock('2026-07-29T10:00:00Z'),
        ))->liveTrade([
            'coin' => 'BTC', 'side' => 'B', 'px' => '1', 'sz' => '1',
            'hash' => '0x1', 'time' => 1, 'tid' => 1, 'users' => ['0xa', '0xb'],
        ]);
        $other = (new HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            clock: new MockClock('2026-07-29T10:00:00Z'),
        ))->liveTrade([
            'coin' => 'BTC', 'side' => 'B', 'px' => '2', 'sz' => '1',
            'hash' => '0x2', 'time' => 2, 'tid' => 2, 'users' => ['0xa', '0xb'],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_live_checkpoint_invalid');
        self::fresh()->withPending($event, ['kind' => 'trade'])
            ->withPending($other, ['kind' => 'trade']);
    }

    public function testChecksumOversizeSymlinkAndHistoryBoundsFailClosed(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $path = $this->directory . '/checkpoints/hyperliquid-live.json';
        $document = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $document['sha256'] = str_repeat('0', 64);
        file_put_contents($path, CanonicalJson::encode($document) . "\n");
        self::assertCheckpointLoadFails($this->directory);

        unlink($path);
        file_put_contents(
            $path,
            str_repeat('x', HyperliquidPaperLiveCheckpoint::MAXIMUM_BYTES + 257),
        );
        chmod($path, 0600);
        self::assertCheckpointLoadFails($this->directory);

        unlink($path);
        $target = $this->directory . '/target';
        file_put_contents($target, '{}');
        self::assertTrue(symlink($target, $path));
        self::assertCheckpointLoadFails($this->directory);

        $state = self::fresh()->toArray();
        $state['acknowledged_identities'] = array_map(
            static fn (int $index): string => hash('sha256', (string) $index),
            range(0, HyperliquidPaperLiveCheckpoint::MAXIMUM_ACKNOWLEDGED_IDENTITIES),
        );
        try {
            HyperliquidPaperLiveCheckpoint::fromArray($state);
            self::fail('Expected bounded identity history.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'hyperliquid_paper_live_checkpoint_invalid',
                $exception->getMessage(),
            );
        }
    }

    public function testStalePrivateTemporaryFileIsRemoved(): void
    {
        $checkpoints = $this->directory . '/checkpoints';
        self::assertTrue(mkdir($checkpoints, 0700));
        $temporary = $checkpoints . '/.hyperliquid-live-' . str_repeat('a', 32) . '.tmp';
        file_put_contents($temporary, 'stale');
        chmod($temporary, 0600);

        new HyperliquidPaperLiveCheckpointStore($this->directory);

        self::assertFileDoesNotExist($temporary);
    }

    /** Nodes counted by CanonicalJson: every array and every scalar. */
    private static function canonicalNodes(mixed $value): int
    {
        if (!\is_array($value)) {
            return 1;
        }
        $nodes = 1;
        foreach ($value as $item) {
            $nodes += self::canonicalNodes($item);
        }

        return $nodes;
    }

    /** Keys counted by CanonicalJson: the keys of every map. */
    private static function canonicalKeys(mixed $value): int
    {
        if (!\is_array($value)) {
            return 0;
        }
        $keys = array_is_list($value) ? 0 : \count($value);
        foreach ($value as $item) {
            $keys += self::canonicalKeys($item);
        }

        return $keys;
    }

    /** @return array<string, mixed> */
    private static function candle(
        int $start,
        string $coin = 'BTC',
        string $interval = '1m',
    ): array {
        $duration = ['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000][$interval];

        return [
            'T' => $start + $duration - 1,
            'c' => '2',
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

    private static function assertCheckpointLoadFails(string $directory): void
    {
        try {
            (new HyperliquidPaperLiveCheckpointStore($directory))->loadOrCreate(
                'paper-hyperliquid-live-mainnet',
                PaperMarketDataNetwork::MAINNET,
                str_repeat('a', 64),
            );
            self::fail('Expected invalid durable checkpoint.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'hyperliquid_paper_live_checkpoint_invalid',
                $exception->getMessage(),
            );
        }
    }
}
