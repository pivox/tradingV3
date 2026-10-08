<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\DoctrinePaperExecutionStore;
use App\Trading\Paper\Execution\Persistence\PaperPendingEffect;
use App\Trading\Paper\Execution\Persistence\PaperReplayBatchJournal;
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
use DoctrineMigrations\Version20260820170000;
use DoctrineMigrations\Version20260821030000;
use DoctrineMigrations\Version20260823190000;
use DoctrineMigrations\Version20260824123000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Batched replay sessions against a real PostgreSQL schema: what the session serves back
 * must be exactly what the per-event store serves (read-your-writes), and a resumed
 * session must rebuild its market state from the consumed prefix.
 */
#[CoversClass(DoctrinePaperExecutionStore::class)]
#[CoversClass(PaperReplayBatchJournal::class)]
final class DoctrinePaperExecutionStoreBatchTest extends TestCase
{
    private const EVENTS_SHA256 = '5555555555555555555555555555555555555555555555555555555555555555';

    private Connection $connection;
    private string $schemaName;
    private DoctrinePaperExecutionStore $store;
    private PaperConfigurationSnapshot $snapshot;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        $database = is_string($dsn) ? ltrim((string) parse_url($dsn, PHP_URL_PATH), '/') : '';
        if (!str_ends_with($database, '_paper_test')) {
            self::markTestSkipped('Paper execution integration tests require a database ending in _paper_test.');
        }

        $this->connection = DriverManager::getConnection(['url' => $dsn]);
        $this->schemaName = sprintf('paper_batch_%d_%s', getmypid(), bin2hex(random_bytes(4)));
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
        $this->store->registerSnapshot($this->snapshot);
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection)) {
            return;
        }
        if ($this->store->replayBatchActive()) {
            $this->store->abortReplayBatch();
        }
        $quoted = $this->connection->getDatabasePlatform()->quoteSingleIdentifier($this->schemaName);
        $this->connection->executeStatement('SET search_path TO public');
        $this->connection->executeStatement('DROP SCHEMA IF EXISTS ' . $quoted . ' CASCADE');
        $this->connection->close();
    }

    public function testPendingEffectsOfABatchReadBackExactlyLikeThePerEventStore(): void
    {
        // Keys out of jsonb order: the database returns them shorter-first, then in byte order,
        // and order-sensitive consumers (the legacy effect codec key checks) see exactly that.
        $payload = [
            'plan' => ['take_profit' => 30340.0, 'entry' => 30300.5, 'size' => 1, 'contract_size' => 1.0, 'nested' => ['zz' => true, 'a' => null]],
            'decision_key' => 'decision-1',
            'id' => 7,
        ];
        $perEvent = $this->cell('run-per-event');
        $this->store->claimSource($perEvent, 0, $this->event(0));
        $this->store->appendEffect($perEvent, 0, 'sha256:' . str_repeat('1', 64), $payload);
        $fromDatabase = $this->store->pendingEffects($perEvent)[0]->payload;

        $batched = $this->cell('run-batched');
        $this->store->beginReplayBatch($batched, static fn (): iterable => []);
        $this->store->claimSource($batched, 0, $this->event(0));
        $this->store->appendEffect($batched, 0, 'sha256:' . str_repeat('1', 64), $payload);
        $fromBatch = $this->store->pendingEffects($batched);
        $fromBatchAt = $this->store->pendingEffectsAt($batched, 0);

        self::assertSame($fromDatabase, $fromBatch[0]->payload);
        self::assertSame($fromDatabase, $fromBatchAt[0]->payload);
        self::assertSame(['id', 'plan', 'decision_key'], array_keys($fromBatch[0]->payload));
        self::assertSame(['size', 'entry', 'nested', 'take_profit', 'contract_size'], array_keys($fromBatch[0]->payload['plan']));
        self::assertSame(30340.0, $fromBatch[0]->payload['plan']['take_profit']);
        self::assertSame(PaperReplayBatchJournal::asStoredJson($payload), $fromDatabase);
    }

    public function testAResumedBatchServesTheWholeConsumedPrefixAsAcknowledgedSources(): void
    {
        $cell = $this->cell('run-resume');
        $events = [$this->event(0), $this->event(1), $this->event(2)];
        $this->consumeBatch($cell, $events);
        self::assertSame(['position' => 2, 'event_id' => $events[2]->eventId, 'exchange_timestamp' => '2026-08-01T10:00:02.000000Z'], $this->store->lastSourceIdentity($cell));

        $resumed = $this->store->beginReplayBatch($cell, static fn (): iterable => $events);
        $sources = iterator_to_array($this->store->acknowledgedSources($cell), true);

        self::assertSame(3, $resumed['next_source_position']);
        self::assertNotNull($resumed['snapshot']);
        self::assertSame([0, 1, 2], array_keys($sources), 'The prefix is yielded, not returned from the generator.');
        self::assertSame(
            array_map(static fn (PaperMarketEvent $event): string => $event->eventId, $events),
            array_map(static fn (PaperMarketEvent $event): string => $event->eventId, $sources),
        );
    }

    public function testACommittedBatchIsOneLightRowPlusTheDurableSnapshotRow(): void
    {
        $cell = $this->cell('run-rows');
        $committed = $this->consumeBatch($cell, [$this->event(0), $this->event(1), $this->event(2)]);

        self::assertSame(3, $committed['next_source_position']);
        self::assertSame(str_repeat('a', 64), $committed['snapshot_sha256']);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_type, source_position, payload::text AS payload FROM paper_execution_event WHERE cell_id = ? ORDER BY journal_ordinal',
            [$cell->id],
        );
        self::assertSame(['replay_batch', 'fake_state_snapshot'], array_column($rows, 'event_type'));
        $batch = json_decode($rows[0]['payload'], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame([0, 3, 3], [$batch['from_position'], $batch['to_position'], $batch['event_count']]);
        self::assertSame([[null, null, 3]], $batch['observations']);
        self::assertSame(3, $this->store->checkpoint($cell)->nextSourcePosition);
    }

    public function testAnAbortedBatchLeavesNoRowAndTheCheckpointUntouched(): void
    {
        $cell = $this->cell('run-abort');
        $this->store->beginReplayBatch($cell, static fn (): iterable => []);
        $this->store->claimSource($cell, 0, $this->event(0));
        $this->store->abortReplayBatch();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM paper_execution_event WHERE cell_id = ?', [$cell->id]));
        self::assertSame(0, $this->store->checkpoint($cell)->nextSourcePosition);
    }

    /**
     * @param list<PaperMarketEvent> $events
     * @return array<string, mixed>
     */
    private function consumeBatch(PaperExecutionCell $cell, array $events): array
    {
        $this->store->beginReplayBatch($cell, static fn (): iterable => []);
        foreach ($events as $position => $event) {
            $key = 'sha256:' . hash('sha256', 'market-' . $position);
            $this->store->claimSource($cell, $position, $event);
            $this->store->appendEffect($cell, $position, $key, ['effect_type' => 'market_event', 'payload' => ['position' => $position]]);
            $this->store->acknowledge($cell, $position, $key, ['effect_type' => 'market_event', 'event_types' => []], 0);
            $this->store->recordMarketEffectMateriality($cell, $position, $key, false);
        }
        $committed = $this->store->flushReplayBatch(static fn (int $ordinal): array => [
            'sha256' => str_repeat('a', 64),
            'bytes' => 10,
            'state_revision' => 1,
        ]);
        $this->store->endReplayBatch();

        return $committed;
    }

    private function cell(string $runId): PaperExecutionCell
    {
        $cell = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $this->snapshot->id,
            'scalper_micro',
            $runId,
        );
        $this->store->registerCell($cell, PaperProfileEligibility::REFERENCE_ONLY);
        $this->store->bindDataset($cell, 'dataset-batch', self::EVENTS_SHA256, 'paper-recorder.v2');

        return $cell;
    }

    private function event(int $second): PaperMarketEvent
    {
        $timestamp = new \DateTimeImmutable(sprintf('2026-08-01T10:00:%02d+00:00', $second));

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $timestamp,
            $timestamp,
            (string) (42 + $second),
            ['bid' => '999', 'ask' => '1001'],
        );
    }

    private function executeMigration(): void
    {
        require_once __DIR__ . '/../../../../../migrations/Version20260801120000.php';
        require_once __DIR__ . '/../../../../../migrations/Version20260820170000.php';
        require_once __DIR__ . '/../../../../../migrations/Version20260821030000.php';
        require_once __DIR__ . '/../../../../../migrations/Version20260823190000.php';
        require_once __DIR__ . '/../../../../../migrations/Version20260824123000.php';
        foreach ([Version20260801120000::class, Version20260820170000::class, Version20260821030000::class, Version20260823190000::class, Version20260824123000::class] as $class) {
            /** @var AbstractMigration $migration */
            $migration = new $class($this->connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->connection->executeStatement($query->getStatement());
            }
        }
    }
}
