<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
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

        self::assertSame(3, $checkpoint->policyVersion);
        self::assertSame([
            'schema_version', 'policy_version', 'dataset_id', 'network',
            'configuration_sha256', 'phase', 'failure_reason', 'continuity',
            'connection_epoch', 'source_epoch', 'subscriptions',
            'ordinal_state', 'pending_event', 'pending_continuation',
            'current_candles', 'finalized_candle_frontiers',
            'initial_candle_window_ends',
            'acknowledged_identities', 'trade_identity_history',
            'reconnect_attempt',
            'heartbeat', 'healthy_stop',
        ], array_keys($checkpoint->toArray()));
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

    /** @return array<string, mixed> */
    private static function candle(
        int $start,
        string $coin = 'BTC',
        string $interval = '1m',
    ): array {
        $duration = $interval === '5m' ? 300_000 : 60_000;

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
