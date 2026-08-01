<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\DoctrinePaperExecutionStore;
use App\Trading\Paper\Execution\Persistence\PaperPendingEffect;
use App\Trading\Paper\Execution\Persistence\PaperSourceClaim;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260801120000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DoctrinePaperExecutionStore::class)]
final class DoctrinePaperExecutionStoreTest extends TestCase
{
    private Connection $connection;
    private string $schemaName;
    private DoctrinePaperExecutionStore $store;
    private PaperConfigurationSnapshot $snapshot;
    private PaperExecutionCell $cell;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        $database = is_string($dsn) ? ltrim((string) parse_url($dsn, PHP_URL_PATH), '/') : '';
        if (!str_ends_with($database, '_paper_test')) {
            throw new \LogicException('Paper execution integration tests require a database ending in _paper_test.');
        }

        $this->connection = DriverManager::getConnection(['url' => $dsn]);
        $this->schemaName = sprintf('paper_store_%d_%s', getmypid(), bin2hex(random_bytes(4)));
        $quoted = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schemaName);
        $this->connection->executeStatement('CREATE SCHEMA ' . $quoted);
        $this->connection->executeStatement('SET search_path TO ' . $quoted . ', public');
        foreach (['order_intent', 'trade_lineage', 'trade_lifecycle_event', 'fill_cost_ledger', 'trade_zone_events'] as $table) {
            $this->connection->executeStatement(sprintf('CREATE TABLE %s (id BIGSERIAL PRIMARY KEY)', $table));
        }
        $this->executeMigration();

        $this->store = new DoctrinePaperExecutionStore($this->connection);
        $this->snapshot = (new PaperConfigurationSnapshotFactory())->create([
            'strategy' => ['profile' => 'scalper_micro'],
            'risk' => ['max_notional' => '1000'],
        ]);
        $this->cell = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $this->snapshot->id,
            'scalper_micro',
            'run-001',
        );
        $this->store->registerSnapshot($this->snapshot);
        $this->store->registerCell($this->cell, PaperProfileEligibility::REFERENCE_ONLY);
    }

    protected function tearDown(): void
    {
        $quoted = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schemaName);
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $quoted . ' CASCADE');
        $this->connection->close();
    }

    public function testSnapshotAndCellRegistrationAreIdempotentAndConflictsFailClosed(): void
    {
        $this->store->registerSnapshot($this->snapshot);
        $this->store->registerCell($this->cell, PaperProfileEligibility::REFERENCE_ONLY);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM paper_configuration_snapshot'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM paper_execution_cell'));

        $this->connection->executeStatement("UPDATE paper_configuration_snapshot SET canonical_json = '{\"corrupt\":true}'::jsonb WHERE id = ?", [$this->snapshot->id]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_configuration_snapshot_conflict');
        $this->store->registerSnapshot($this->snapshot);
    }

    public function testRunIdCannotBeReusedForAnotherCellTuple(): void
    {
        $other = PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $this->snapshot->id,
            'regular',
            'run-001',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_cell_identity_conflict');
        $this->store->registerCell($other, PaperProfileEligibility::REFERENCE_ONLY);
    }

    public function testDatasetIdentityIsBoundOnceAndCannotBeSubstitutedOnRestart(): void
    {
        $checksum = str_repeat('a', 64);
        $this->store->bindDataset($this->cell, 'dataset-original', $checksum);
        $this->store->bindDataset($this->cell, 'dataset-original', $checksum);
        self::assertSame(
            ['dataset_id' => 'dataset-original', 'events_file_sha256' => $checksum],
            (new DoctrinePaperExecutionStore($this->connection))->datasetIdentity($this->cell),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_dataset_identity_conflict');
        $this->store->bindDataset($this->cell, 'dataset-substitute', str_repeat('b', 64));
    }

    public function testExactSourceOrderingReplayAndConflictingDuplicate(): void
    {
        $event = $this->event(0, ['bid' => '999', 'ask' => '1001']);
        self::assertSame(PaperSourceClaim::ACCEPTED, $this->store->claimSource($this->cell, 0, $event)->status);
        self::assertSame(PaperSourceClaim::REPLAYED, $this->store->claimSource($this->cell, 0, $event)->status);

        try {
            $this->store->claimSource($this->cell, 0, $this->event(0, ['bid' => '998', 'ask' => '1002']));
            self::fail('Conflicting duplicate was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        try {
            $this->store->claimSource($this->cell, 0, $this->event(9, ['bid' => '999', 'ask' => '1001']));
            self::fail('Old unknown event was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_execution_source_out_of_order', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_source_gap');
        $this->store->claimSource($this->cell, 2, $this->event(2));
    }

    public function testPendingEffectsAreOrderedIdempotentAndBlockFurtherSourceClaims(): void
    {
        $this->store->claimSource($this->cell, 0, $this->event(0));
        $key1 = 'sha256:' . str_repeat('1', 64);
        $key2 = 'sha256:' . str_repeat('2', 64);
        $this->store->appendEffect($this->cell, 0, $key1, ['order' => 1]);
        $this->store->appendEffect($this->cell, 0, $key1, ['order' => 1]);
        $this->store->appendEffect($this->cell, 0, $key2, ['order' => 2]);

        $pending = $this->store->pendingEffects($this->cell);
        self::assertCount(2, $pending);
        self::assertSame([], $this->store->acknowledgedSources($this->cell));
        self::assertContainsOnlyInstancesOf(PaperPendingEffect::class, $pending);
        self::assertSame([$key1, $key2], array_map(static fn (PaperPendingEffect $effect): string => $effect->effectKey, $pending));
        $this->store->recordEffectRetry($this->cell, 0, $key1);
        $this->store->recordEffectFailure($this->cell, 0, $key1, 'fake_effect_dispatch_failed');
        self::assertSame(1, $this->store->journalEventCounts($this->cell)['effect_retried']);
        self::assertSame(1, $this->store->journalEventCounts($this->cell)['effect_failed']);

        try {
            $this->store->claimSource($this->cell, 1, $this->event(1));
            self::fail('Source advanced past a pending effect.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_execution_effect_pending', $exception->getMessage());
        }

        $this->store->acknowledge($this->cell, 0, $key1, ['order_id' => 'fake-1'], 1);
        self::assertSame([$key2], array_map(static fn (PaperPendingEffect $effect): string => $effect->effectKey, $this->store->pendingEffects($this->cell)));
        self::assertSame([], $this->store->acknowledgedSources($this->cell));
        $this->store->acknowledge($this->cell, 0, $key2, ['order_id' => 'fake-2'], 2);
        self::assertSame([], $this->store->pendingEffects($this->cell));
        self::assertSame([$this->event(0)->eventId], array_map(static fn (PaperMarketEvent $event): string => $event->eventId, $this->store->acknowledgedSources($this->cell)));
        self::assertSame(PaperSourceClaim::ACCEPTED, $this->store->claimSource($this->cell, 1, $this->event(1))->status);
        self::assertSame(2, $this->store->checkpoint($this->cell)->fakeEventCursor);
    }

    public function testCheckpointCorruptionIsDetectedOnRestart(): void
    {
        $this->store->claimSource($this->cell, 0, $this->event(0));
        $this->connection->executeStatement('UPDATE paper_execution_checkpoint SET journal_checksum = ? WHERE cell_id = ?', [str_repeat('f', 64), $this->cell->id]);

        $restarted = new DoctrinePaperExecutionStore($this->connection);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_checkpoint_corrupt');
        $restarted->checkpoint($this->cell);
    }

    #[DataProvider('derivedCheckpointColumnProvider')]
    public function testDerivedCheckpointColumnCorruptionIsDetectedOnRestart(string $column, string $value): void
    {
        $this->store->claimSource($this->cell, 0, $this->event(0));
        $key = 'sha256:' . str_repeat('1', 64);
        $this->store->appendEffect($this->cell, 0, $key, ['order' => 1]);
        $this->store->acknowledge($this->cell, 0, $key, ['order_id' => 'fake-1'], 1);
        $this->connection->executeStatement(
            sprintf('UPDATE paper_execution_checkpoint SET %s = %s WHERE cell_id = ?', $column, $value),
            [$this->cell->id],
        );

        $restarted = new DoctrinePaperExecutionStore($this->connection);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_checkpoint_corrupt');
        $restarted->checkpoint($this->cell);
    }

    /** @return iterable<string, array{string, string}> */
    public static function derivedCheckpointColumnProvider(): iterable
    {
        yield 'source position' => ['next_source_position', '9'];
        yield 'fake cursor' => ['fake_event_cursor', '9'];
        yield 'kill state' => ['killed', 'TRUE'];
    }

    public function testKillPersistsAcrossRestartAndResumeIsExplicit(): void
    {
        $this->store->kill($this->cell);
        $restarted = new DoctrinePaperExecutionStore($this->connection);
        self::assertTrue($restarted->checkpoint($this->cell)->killed);

        try {
            $restarted->claimSource($this->cell, 0, $this->event(0));
            self::fail('Killed cell consumed a source event.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_execution_cell_killed', $exception->getMessage());
        }

        $restarted->resume($this->cell);
        self::assertFalse($restarted->checkpoint($this->cell)->killed);
        self::assertSame(PaperSourceClaim::ACCEPTED, $restarted->claimSource($this->cell, 0, $this->event(0))->status);
    }

    /** @param array<string, mixed> $payload */
    private function event(int $second, array $payload = ['bid' => '999', 'ask' => '1001']): PaperMarketEvent
    {
        $timestamp = new \DateTimeImmutable(sprintf('2026-08-01T10:00:%02d+00:00', $second));

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $timestamp,
            $timestamp,
            '42',
            $payload,
        );
    }

    private function executeMigration(): void
    {
        require_once __DIR__ . '/../../../../../migrations/Version20260801120000.php';
        /** @var AbstractMigration $migration */
        $migration = new Version20260801120000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement());
        }
    }
}
