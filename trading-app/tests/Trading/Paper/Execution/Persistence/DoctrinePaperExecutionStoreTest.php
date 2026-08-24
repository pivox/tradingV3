<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Persistence;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Persistence\DoctrinePaperExecutionStore;
use App\Trading\Paper\Execution\Persistence\PaperExecutionCellState;
use App\Trading\Paper\Execution\Persistence\PaperPendingEffect;
use App\Trading\Paper\Execution\Persistence\PaperSourceClaim;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyObservation;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyPreparationResult;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DoctrinePaperExecutionStore::class)]
#[CoversClass(PaperExecutionCellState::class)]
#[CoversClass(PaperCanonicalStrategyObservation::class)]
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
            self::markTestSkipped('Paper execution integration tests require a database ending in _paper_test.');
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
        if (!isset($this->connection)) {
            return;
        }

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

    public function testRecoveryIndexesIncludeTheJournalEventType(): void
    {
        $definitions = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT indexdef
FROM pg_indexes
WHERE schemaname = current_schema()
  AND indexname IN ('idx_paper_execution_event_typed_source', 'idx_paper_execution_event_typed_effect')
ORDER BY indexname
SQL);

        self::assertCount(2, $definitions);
        self::assertStringContainsString('(cell_id, event_type, effect_key)', $definitions[0]);
        self::assertStringContainsString('(cell_id, event_type, source_position)', $definitions[1]);
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

    public function testModernCellRegistrationPersistsAndComparesEveryExactIdentityField(): void
    {
        $modern = $this->modernCell();
        $this->store->registerCell($modern, PaperProfileEligibility::REFERENCE_ONLY);
        $stored = $this->connection->fetchAssociative('SELECT * FROM paper_execution_cell WHERE id = ?', [$modern->id]);

        self::assertIsArray($stored);
        self::assertSame('micro_scalping', $stored['mode_id']);
        self::assertSame('1.1.0', $stored['mode_version']);
        self::assertSame('micro_scalping.momentum_ofi.long', $stored['setup_id']);
        self::assertSame('1.1.0', $stored['setup_version']);
        self::assertSame('long', $stored['canonical_side']);
        self::assertSame($modern->modernIdentity?->configHash, $stored['canonical_config_hash']);
        self::assertSame($modern->modernIdentity?->conditionCatalogHash, $stored['condition_catalog_hash']);

        $this->connection->executeStatement(
            "UPDATE paper_execution_cell SET setup_version = '1.0.0' WHERE id = ?",
            [$modern->id],
        );
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_cell_identity_conflict');
        $this->store->inspectCell($modern, PaperProfileEligibility::REFERENCE_ONLY);
    }

    public function testModernCellCanBeMarkedBaselineEligibleAfterCanonicalBridge(): void
    {
        $modern = $this->modernCell();

        $this->store->registerCell($modern, PaperProfileEligibility::BASELINE_ELIGIBLE);

        self::assertSame(
            PaperProfileEligibility::BASELINE_ELIGIBLE->value,
            $this->connection->fetchOne('SELECT eligibility FROM paper_execution_cell WHERE id = ?', [$modern->id]),
        );
        self::assertTrue($this->store->inspectCell(
            $modern,
            PaperProfileEligibility::BASELINE_ELIGIBLE,
        )->registered);
    }

    public function testBaselineEvidenceBlocksDowngradeBeforeConstraintsAreChanged(): void
    {
        $this->store->registerCell($this->modernCell(), PaperProfileEligibility::BASELINE_ELIGIBLE);
        $migration = new Version20260823190000($this->connection, new NullLogger());
        $migration->down(new Schema());

        try {
            $this->connection->executeStatement($migration->getSql()[0]->getStatement());
            self::fail('Baseline-eligible evidence must block the downgrade.');
        } catch (\Doctrine\DBAL\Exception $failure) {
            self::assertStringContainsString('paper_baseline_eligible_evidence_blocks_downgrade', $failure->getMessage());
        }

        $definition = $this->connection->fetchOne(<<<'SQL'
SELECT pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conname = 'chk_paper_execution_cell_eligibility'
  AND connamespace = current_schema()::regnamespace
SQL);
        self::assertIsString($definition);
        self::assertStringContainsString('baseline_eligible', $definition);
    }

    public function testLegacyCellCannotBeMarkedBaselineEligible(): void
    {
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            $this->snapshot->id,
            'regular',
            'legacy-baseline-forbidden',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_cell_eligibility_conflict');
        $this->store->registerCell($legacy, PaperProfileEligibility::BASELINE_ELIGIBLE);
    }

    public function testDatasetIdentityIsBoundOnceAndCannotBeSubstitutedOnRestart(): void
    {
        $checksum = str_repeat('a', 64);
        $this->store->bindDataset($this->cell, 'dataset-original', $checksum);
        $this->store->bindDataset($this->cell, 'dataset-original', $checksum);
        self::assertSame(
            [
                'dataset_id' => 'dataset-original',
                'events_file_sha256' => $checksum,
                'source_build_version' => null,
            ],
            (new DoctrinePaperExecutionStore($this->connection))->datasetIdentity($this->cell),
        );
        $this->store->bindDataset($this->cell, 'dataset-original', $checksum, 'paper-dataset-recorder.v2');
        self::assertSame(
            'paper-dataset-recorder.v2',
            (new DoctrinePaperExecutionStore($this->connection))->datasetIdentity($this->cell)['source_build_version'],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_dataset_identity_conflict');
        $this->store->bindDataset($this->cell, 'dataset-substitute', str_repeat('b', 64));
    }

    public function testModernDatasetIdentityRequiresAndPersistsExactSourceBuildVersion(): void
    {
        $modern = $this->modernCell();
        $this->store->registerCell($modern, PaperProfileEligibility::REFERENCE_ONLY);

        try {
            $this->store->bindDataset($modern, 'dataset-modern', str_repeat('a', 64));
            self::fail('A modern dataset binding must require its source build version.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_execution_dataset_identity_invalid', $exception->getMessage());
        }

        $this->store->bindDataset(
            $modern,
            'dataset-modern',
            str_repeat('a', 64),
            'paper-dataset-recorder.v2',
        );
        $restarted = new DoctrinePaperExecutionStore($this->connection);
        self::assertSame([
            'dataset_id' => 'dataset-modern',
            'events_file_sha256' => str_repeat('a', 64),
            'source_build_version' => 'paper-dataset-recorder.v2',
        ], $restarted->datasetIdentity($modern));
        $restarted->bindDataset(
            $modern,
            'dataset-modern',
            str_repeat('a', 64),
            'paper-dataset-recorder.v2',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_dataset_identity_conflict');
        $restarted->bindDataset(
            $modern,
            'dataset-modern',
            str_repeat('a', 64),
            'forged-recorder.v2',
        );
    }

    public function testModernDatasetIdentityWithoutPersistedSourceBuildVersionFailsClosed(): void
    {
        $modern = $this->modernCell();
        $this->store->registerCell($modern, PaperProfileEligibility::REFERENCE_ONLY);
        $this->connection->executeStatement(
            'UPDATE paper_execution_cell SET dataset_id = ?, dataset_events_sha256 = ? WHERE id = ?',
            ['dataset-modern', str_repeat('a', 64), $modern->id],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_execution_dataset_identity_missing');
        $this->store->datasetIdentity($modern);
    }

    public function testReadOnlyCellInspectionExposesBindingAndKillState(): void
    {
        $initial = $this->store->inspectCell($this->cell, PaperProfileEligibility::REFERENCE_ONLY);
        self::assertTrue($initial->registered);
        self::assertFalse($initial->killed);
        self::assertNull($initial->datasetId);

        $this->store->bindDataset($this->cell, 'dataset-original', str_repeat('a', 64));
        $bound = $this->store->inspectCell($this->cell, PaperProfileEligibility::REFERENCE_ONLY);
        self::assertSame('dataset-original', $bound->datasetId);
        self::assertSame(str_repeat('a', 64), $bound->eventsFileSha256);

        $this->store->kill($this->cell);
        self::assertTrue($this->store->inspectCell($this->cell, PaperProfileEligibility::REFERENCE_ONLY)->killed);
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

    public function testStrategyObservationIsSourceLinkedIdempotentAndConflictSafe(): void
    {
        $cell = $this->modernCell();
        $event = $this->event(0);
        $this->store->registerCell($cell, PaperProfileEligibility::REFERENCE_ONLY);
        $this->store->claimSource($cell, 0, $event);
        $observation = PaperCanonicalStrategyObservation::fromPreparation(
            $cell,
            $event,
            PaperCanonicalStrategyPreparationResult::missingEvidence('paper_order_book_unavailable'),
        );

        $this->store->appendStrategyObservation($cell, 0, $observation);
        $this->store->appendStrategyObservation($cell, 0, $observation);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT source_position, source_event_id, payload::text AS payload FROM paper_execution_event WHERE cell_id = ? AND event_type = 'strategy_observed'",
            [$cell->id],
        );
        self::assertCount(1, $rows);
        self::assertSame(0, (int) $rows[0]['source_position']);
        self::assertSame($event->eventId, $rows[0]['source_event_id']);
        $payload = json_decode((string) $rows[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('paper-strategy-observation.v1', $payload['schema_version']);
        self::assertSame('missing_evidence', $payload['status']);
        self::assertSame('paper_order_book_unavailable', $payload['reason_code']);
        self::assertSame($cell->modernIdentity?->configHash, $payload['config_hash']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_strategy_observation_conflict');
        $this->store->appendStrategyObservation(
            $cell,
            0,
            PaperCanonicalStrategyObservation::fromPreparation(
                $cell,
                $event,
                PaperCanonicalStrategyPreparationResult::noTrade('micro_scalping_shadow_setup_filter_failed'),
            ),
        );
    }

    public function testStrategyObservationAcceptsCanonicalSourceDigestThatLooksEncoded(): void
    {
        $event = PaperMarketEvent::fromArray(json_decode(
            <<<'JSON'
{"channel":"public_trade","event_id":"4a2fa1c927ca195728949396d54b73d404a3ddab1214d05c9eac945fb144f77a","exchange_timestamp":"2026-08-24T06:25:53.889000Z","payload":{"block_time":"1787552753889","native_symbol":"BTC","origin":"ws_trades","price":"77421","side":"buy","size":"0.28776","trade_id":"545366555278346","transaction_hash_nibbles":[7,1,0,2,9,10,15,15,7,14,14,10,15,15,5,6,7,2,7,12,0,4,4,2,13,10,7,0,13,9,0,2,0,1,15,9,0,0,14,5,1,9,14,14,1,14,2,8,1,4,12,11,4,6,5,2,3,13,14,14,13,9,4,1]},"payload_hash":"146c301b882ed66d2bf632aa204ebb4ad8f970bfee59f501162218dc4de4470d","received_timestamp":"2026-08-24T06:25:54.228195Z","schema_version":2,"sequence":"111","source_network":"mainnet","source_venue":"hyperliquid","symbol":"BTCUSDT"}
JSON,
            true,
            512,
            JSON_THROW_ON_ERROR,
        ));

        $observation = PaperCanonicalStrategyObservation::fromPreparation(
            $this->modernCell(network: PaperMarketDataNetwork::MAINNET),
            $event,
            PaperCanonicalStrategyPreparationResult::missingEvidence('paper_indicator_projection_unavailable'),
        );

        self::assertSame($event->eventId, $observation->sourceEventId);
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

    private function modernCell(PaperMarketDataNetwork $network = PaperMarketDataNetwork::TESTNET): PaperExecutionCell
    {
        $conditionHash = 'sha256:' . str_repeat('c', 64);
        $payload = ['decision' => ['enabled' => true]];
        $layers = [];
        foreach (['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'] as $type) {
            $layers[] = ['type' => $type, 'name' => $type, 'path' => $type . '.yaml', 'required' => true];
        }
        $snapshot = new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest(
                'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0',
                'hyperliquid', $network->value, 'long', ShadowExecutionCapability::Paper,
            ),
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $conditionHash),
            $conditionHash,
            $layers,
            ['decision.enabled' => $layers[0]],
        );

        return PaperExecutionCell::createModern(
            $network,
            PaperMarketDataVenue::HYPERLIQUID,
            $this->snapshot->id,
            PaperModernStrategyIdentity::fromResolvedSnapshot(
                $network,
                PaperMarketDataVenue::HYPERLIQUID,
                $snapshot,
            ),
            'modern-run-002',
        );
    }
}
