<?php

declare(strict_types=1);

namespace App\Tests\Trading\View;

use App\Tests\Support\PostgresIntegrationDatabaseGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260622000000;
use DoctrineMigrations\Version20260623010000;
use DoctrineMigrations\Version20260625000000;
use DoctrineMigrations\Version20260625020000;
use DoctrineMigrations\Version20260626000000;
use DoctrineMigrations\Version20260719130000;
use DoctrineMigrations\Version20260808114000;
use DoctrineMigrations\Version20260811120000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OBS-003 — Test d'INTÉGRATION de la vue `position_trade_analysis_v2` sur un vrai PostgreSQL.
 *
 * Exécute le SQL RÉEL de la migration {@see Version20260622000000} (via `getSql()`),
 * puis la migration FIFO {@see Version20260623010000},
 * sur des tables minimales `trade_lifecycle_event` + `indicator_snapshots`, puis vérifie
 * le rapprochement par identifiants EXACTS (internal_trade_id, trade_id puis position_id,
 * jamais par symbole),
 * l'absence de duplication / de réutilisation de clôture, le lineage et la sémantique PnL.
 *
 * Skippé proprement si aucun PostgreSQL n'est disponible (DATABASE_URL non-postgres).
 */
#[CoversClass(Version20260622000000::class)]
#[CoversClass(Version20260623010000::class)]
#[CoversClass(Version20260625000000::class)]
#[CoversClass(Version20260625020000::class)]
#[CoversClass(Version20260626000000::class)]
#[CoversClass(Version20260719130000::class)]
#[CoversClass(Version20260808114000::class)]
#[CoversClass(Version20260811120000::class)]
final class PositionTradeAnalysisViewTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if (!is_string($dsn) || !preg_match('/^(postgres|postgresql|pdo-pgsql)/', $dsn)) {
            self::markTestSkipped('PostgreSQL DATABASE_URL required for the view integration test.');
        }

        PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase($dsn);

        try {
            $this->conn = DriverManager::getConnection(['url' => $dsn]);
            $this->conn->executeQuery('SELECT 1');
            $this->conn->executeStatement("SET TIME ZONE 'UTC'");
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }

        $this->createMinimalSchema();
        $this->applyViewMigration();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_ledger_aggregate_v1');
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2');
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2_legacy_source');
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis');
            $this->conn->executeStatement('DROP TABLE IF EXISTS fill_cost_ledger');
            $this->conn->executeStatement('DROP TABLE IF EXISTS trade_lifecycle_event');
            $this->conn->executeStatement('DROP TABLE IF EXISTS indicator_snapshots');
            $this->conn->close();
        }
    }

    public function testExactMatchingLineageAndPnlSemantics(): void
    {
        $run = 'run_dashA_20260617';

        // Entrée T1 (BTC, set s1, scalper) : clôture rapprochée par trade_id, net complet.
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', [
            'trade_id' => 'T1', 'r_multiple_final' => 1.5,
        ], '2026-06-17 08:30:00+00', 100);
        $this->close('BTCUSDT', $run, ['trade_id' => 'T1', 'pnl' => 12.0, 'pnl_R' => 1.5, 'mfe_pct' => 2.0,
            'mae_pct' => -0.5, 'holding_time_sec' => 100, 'fees' => 1.0, 'funding' => 0.2, 'slippage' => 0.1,
        ], null, '2026-06-17 08:40:00+00', 200);

        // Entrée T2 (BTC, set s2, regular) : cycle NORMAL — `order_submitted` n'a que le
        // trade_id, la clôture synchronisée n'a que le position_id. Le pont `position_opened`
        // (trade_id T2 -> position_id P2) permet le rapprochement par position_id (P1).
        $this->entry('BTCUSDT', $run, 's2', 'regular', 'bitmart', 'perpetual', [
            'trade_id' => 'T2',
        ], '2026-06-17 08:31:00+00', 101);
        $this->opened('BTCUSDT', $run, 'T2', 'P2', '2026-06-17 08:32:30+00', 150);
        $this->close('BTCUSDT', $run, ['pnl' => -4.0, 'pnl_R' => -1.0], 'P2', '2026-06-17 08:45:00+00', 201);

        // Entrée ETH (set s1, scalper) : aucune clôture -> unmatched / ouvert.
        $this->entry('ETHUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', [
            'trade_id' => 'T3',
        ], '2026-06-17 08:32:00+00', 102);

        // Clôture orpheline (aucune entrée correspondante) -> ne doit créer AUCUNE ligne.
        $this->close('SOLUSDT', $run, ['trade_id' => 'ORPHAN', 'pnl' => 99.0], 'PX', '2026-06-17 08:50:00+00', 999);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY entry_time',
            [$run]
        );

        // 3 entrées exactement (l'orphelin n'apparaît pas), aucune duplication.
        self::assertCount(3, $rows);
        $entryIds = array_column($rows, 'entry_event_id');
        self::assertSame($entryIds, array_unique($entryIds), 'une ligne par entrée, pas de duplication');

        // Aucune clôture réutilisée : chaque close_event_id non-null est unique.
        $closeIds = array_values(array_filter(array_column($rows, 'close_event_id'), static fn ($v) => $v !== null));
        self::assertSame($closeIds, array_unique($closeIds), 'aucune clôture réutilisée par deux entrées');

        $byTrade = [];
        foreach ($rows as $r) {
            $byTrade[$r['trade_id']] = $r;
        }

        // T1 : rapproché par trade_id, lineage complet, net complet.
        $t1 = $byTrade['T1'];
        self::assertSame('matched', $t1['close_match_status']);
        self::assertSame('matched_trade_id', $t1['close_matched_by']);
        self::assertSame('s1', $t1['set_id']);
        self::assertSame('scalper', $t1['mtf_profile']);
        self::assertSame('bitmart', $t1['exchange']);
        self::assertSame('perpetual', $t1['market_type']);
        self::assertSame($run, $t1['orchestration_run_id']);
        self::assertSame($run, $t1['correlation_run_id']);
        self::assertSame('matched_closed', $t1['analysis_status']);
        self::assertEqualsWithDelta(12.0, (float) $t1['recorded_pnl_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.2, (float) $t1['funding_usdt'], 1e-9);
        // Coûts présents mais contrat #190 incomplet (ni spread ni brut) => 'partial',
        // JAMAIS 'complete'. estimated_net est une ESTIMATION best-effort, pas un net certifié.
        self::assertSame('partial', $t1['cost_completeness']);
        self::assertEqualsWithDelta(10.7, (float) $t1['estimated_net_pnl_usdt'], 1e-9); // 12 - 1 - 0.2 - 0.1

        // T2 : rapproché par position_id, aucune composante de coût => 'unknown', sans estimation.
        $t2 = $byTrade['T2'];
        self::assertSame('matched', $t2['close_match_status']);
        self::assertSame('matched_position_id', $t2['close_matched_by']);
        self::assertSame('P2', $t2['position_id']);
        self::assertEqualsWithDelta(-4.0, (float) $t2['recorded_pnl_usdt'], 1e-9);
        self::assertSame('unknown', $t2['cost_completeness']);
        self::assertNull($t2['estimated_net_pnl_usdt']);

        // ETH : aucune clôture -> unmatched, état réel INCONNU (jamais "open confirmé"), pas de PnL.
        $eth = $byTrade['T3'];
        self::assertSame('unmatched', $eth['close_match_status']);
        self::assertSame('unmatched', $eth['close_matched_by']);
        self::assertSame('unmatched', $eth['analysis_status']);
        self::assertSame('not_applicable', $eth['cost_completeness']);
        self::assertNull($eth['close_event_id']);
        self::assertNull($eth['recorded_pnl_usdt']);
    }

    public function testStructuredCanonicalIdentityIsProjectedWithoutLegacyFallback(): void
    {
        $run = 'run-canonical-view';
        $this->entry('BTCUSDT', $run, 'legacy-set', 'scalper', 'fake', 'perpetual', [
            'trade_id' => 'trade-1', 'pnl_source' => 'legacy-extra-must-not-certify',
        ], '2026-08-08 10:00:00+00', 2700, 'fake');
        $this->close('BTCUSDT', $run, ['trade_id' => 'trade-1', 'pnl' => 1.0], 'position-1', '2026-08-08 10:01:00+00', 2701, 'fake', 'perpetual', 'fake');

        $contract = [
            'correlation_run_id' => 'correlation-1', 'orchestration_run_id' => 'orchestration-1',
            'orchestration_set_id' => 'set-1', 'orchestration_dashboard_id' => 'dashboard-1',
            'mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => 'scalping.pullback',
            'setup_version' => '1.0.0', 'config_hash' => 'config-hash',
            'condition_catalog_hash' => 'catalog-hash', 'side' => 'LONG', 'decision_id' => 'decision-1',
            'decision_key' => 'decision-key-1', 'intent_id' => 'intent-1', 'order_id' => 'order-1',
            'paper_network' => 'testnet',
        ];
        foreach ([2700, 2701] as $id) {
            $sets = implode(', ', array_map(static fn (string $field): string => "$field = :$field", array_keys($contract)));
            $this->conn->executeStatement("UPDATE trade_lifecycle_event SET $sets, trade_id = :trade_id WHERE id = :id", $contract + ['trade_id' => 'trade-1', 'id' => $id]);
        }
        $this->conn->executeStatement('UPDATE trade_lifecycle_event SET position_id = ? WHERE id = ?', ['position-1', 2700]);

        $row = $this->conn->fetchAssociative('SELECT * FROM position_trade_analysis_v2 WHERE entry_event_id = 2700');

        self::assertIsArray($row);
        self::assertSame('canonical', $row['lineage_classification']);
        self::assertSame('scalping', $row['mode_id']);
        self::assertSame('scalping.pullback', $row['setup_id']);
        self::assertSame('config-hash', $row['canonical_config_hash']);
        self::assertSame('LONG', $row['canonical_side']);
        self::assertSame('position-1', $row['canonical_position_id']);
        self::assertSame('trade-1', $row['canonical_trade_id']);

        $this->conn->executeStatement("UPDATE trade_lifecycle_event SET paper_network = 'mainnet' WHERE id = 2701");
        $crossNetwork = $this->conn->fetchAssociative('SELECT lineage_classification, canonical_net_pnl_usdt FROM position_trade_analysis_v2 WHERE entry_event_id = 2700');
        self::assertIsArray($crossNetwork);
        self::assertSame('incomplete', $crossNetwork['lineage_classification']);
        self::assertNull($crossNetwork['canonical_net_pnl_usdt']);
    }

    public function testMarketDataVenueIsProjectedAndScopesInternalTradeIdMatch(): void
    {
        $run = 'run_market_data_venue';

        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-venue-okx',
            'trade_id' => 'shared-external-trade',
            'position_id' => 'shared-external-position',
        ], '2026-07-19 13:00:00+00', 2300, 'okx');
        $this->close('BTCUSDT', $run, [
            'internal_trade_id' => 'itd-venue-okx',
            'trade_id' => 'shared-external-trade',
            'pnl' => 1.0,
        ], 'shared-external-position', '2026-07-19 13:01:00+00', 2301, 'fake', 'perpetual', 'okx');

        $this->entry('BTCUSDT', $run, 's2', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-venue-hyperliquid',
            'trade_id' => 'shared-external-trade',
            'position_id' => 'shared-external-position',
        ], '2026-07-19 13:02:00+00', 2302, 'hyperliquid');
        $this->close('BTCUSDT', $run, [
            'internal_trade_id' => 'itd-venue-hyperliquid',
            'trade_id' => 'shared-external-trade',
            'pnl' => 2.0,
        ], 'shared-external-position', '2026-07-19 13:03:00+00', 2303, 'fake', 'perpetual', 'hyperliquid');

        $rows = $this->conn->fetchAllAssociative(
            'SELECT market_data_venue, exchange, analysis_status
             FROM position_trade_analysis_v2
             WHERE run_id = ?
             ORDER BY market_data_venue',
            [$run],
        );

        self::assertSame([
            ['market_data_venue' => 'hyperliquid', 'exchange' => 'fake', 'analysis_status' => 'matched_closed'],
            ['market_data_venue' => 'okx', 'exchange' => 'fake', 'analysis_status' => 'matched_closed'],
        ], $rows);

        $mismatchRun = 'run_market_data_venue_mismatch';
        $this->entry('BTCUSDT', $mismatchRun, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-venue-mismatch',
            'trade_id' => 'mismatch-external-trade',
            'position_id' => 'mismatch-external-position',
        ], '2026-07-19 14:00:00+00', 2310, 'okx');
        $this->close('BTCUSDT', $mismatchRun, [
            'internal_trade_id' => 'itd-venue-mismatch',
            'trade_id' => 'mismatch-external-trade',
            'pnl' => 99.0,
        ], 'mismatch-external-position', '2026-07-19 14:01:00+00', 2311, 'fake', 'perpetual', 'hyperliquid');

        $mismatch = $this->conn->fetchAssociative(
            'SELECT market_data_venue, close_event_id, analysis_status, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$mismatchRun],
        );

        self::assertIsArray($mismatch);
        self::assertSame('okx', $mismatch['market_data_venue']);
        self::assertNull($mismatch['close_event_id']);
        self::assertSame('unmatched', $mismatch['analysis_status']);
        self::assertNull($mismatch['recorded_pnl_usdt']);
    }

    public function testMarketDataVenueScopesTradeIdMatchWithoutInternalTradeId(): void
    {
        $matchingRun = 'run_md_venue_trade_id_match';
        $this->entry('BTCUSDT', $matchingRun, 'trade-match', 'scalper', 'fake', 'perpetual', [
            'trade_id' => 'md-venue-trade-match',
        ], '2026-07-19 15:00:00+00', 2400, 'okx');
        $this->close('BTCUSDT', $matchingRun, [
            'trade_id' => 'md-venue-trade-match',
            'pnl' => 3.0,
        ], null, '2026-07-19 15:01:00+00', 2401, 'fake', 'perpetual', 'okx');

        $matching = $this->conn->fetchAssociative(
            'SELECT internal_trade_id, trade_id, close_event_id, close_matched_by, analysis_status
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$matchingRun],
        );

        self::assertIsArray($matching);
        self::assertNull($matching['internal_trade_id']);
        self::assertSame('md-venue-trade-match', $matching['trade_id']);
        self::assertSame(2401, (int) $matching['close_event_id']);
        self::assertSame('matched_trade_id', $matching['close_matched_by']);
        self::assertSame('matched_closed', $matching['analysis_status']);

        $mismatchRun = 'run_md_venue_trade_id_mismatch';
        $this->entry('BTCUSDT', $mismatchRun, 'trade-mismatch', 'scalper', 'fake', 'perpetual', [
            'trade_id' => 'md-venue-trade-mismatch',
        ], '2026-07-19 15:10:00+00', 2410, 'okx');
        $this->close('BTCUSDT', $mismatchRun, [
            'trade_id' => 'md-venue-trade-mismatch',
            'pnl' => 99.0,
        ], null, '2026-07-19 15:11:00+00', 2411, 'fake', 'perpetual', 'hyperliquid');

        $mismatch = $this->conn->fetchAssociative(
            'SELECT internal_trade_id, close_event_id, close_matched_by, analysis_status, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$mismatchRun],
        );

        self::assertIsArray($mismatch);
        self::assertNull($mismatch['internal_trade_id']);
        self::assertNull($mismatch['close_event_id']);
        self::assertSame('unmatched', $mismatch['close_matched_by']);
        self::assertSame('unmatched', $mismatch['analysis_status']);
        self::assertNull($mismatch['recorded_pnl_usdt']);
    }

    public function testMarketDataVenueScopesPositionIdMatchWithoutHigherPriorityIdentifiers(): void
    {
        $matchingRun = 'run_md_venue_position_id_match';
        $this->entry('ETHUSDT', $matchingRun, 'position-match', 'scalper', 'fake', 'perpetual', [
            'position_id' => 'md-venue-position-match',
        ], '2026-07-19 16:00:00+00', 2420, 'okx');
        $this->close('ETHUSDT', $matchingRun, [
            'pnl' => 4.0,
        ], 'md-venue-position-match', '2026-07-19 16:01:00+00', 2421, 'fake', 'perpetual', 'okx');

        $matching = $this->conn->fetchAssociative(
            'SELECT internal_trade_id, trade_id, position_id, close_event_id, close_matched_by, analysis_status
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$matchingRun],
        );

        self::assertIsArray($matching);
        self::assertNull($matching['internal_trade_id']);
        self::assertNull($matching['trade_id']);
        self::assertSame('md-venue-position-match', $matching['position_id']);
        self::assertSame(2421, (int) $matching['close_event_id']);
        self::assertSame('matched_position_id', $matching['close_matched_by']);
        self::assertSame('matched_closed', $matching['analysis_status']);

        $mismatchRun = 'run_md_venue_position_id_mismatch';
        $this->entry('ETHUSDT', $mismatchRun, 'position-mismatch', 'scalper', 'fake', 'perpetual', [
            'position_id' => 'md-venue-position-mismatch',
        ], '2026-07-19 16:10:00+00', 2430, 'okx');
        $this->close('ETHUSDT', $mismatchRun, [
            'pnl' => 99.0,
        ], 'md-venue-position-mismatch', '2026-07-19 16:11:00+00', 2431, 'fake', 'perpetual', 'hyperliquid');

        $mismatch = $this->conn->fetchAssociative(
            'SELECT internal_trade_id, trade_id, close_event_id, close_matched_by, analysis_status, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$mismatchRun],
        );

        self::assertIsArray($mismatch);
        self::assertNull($mismatch['internal_trade_id']);
        self::assertNull($mismatch['trade_id']);
        self::assertNull($mismatch['close_event_id']);
        self::assertSame('unmatched', $mismatch['close_matched_by']);
        self::assertSame('unmatched', $mismatch['analysis_status']);
        self::assertNull($mismatch['recorded_pnl_usdt']);
    }

    public function testMarketDataVenueScopesOpenedBridgeBeforePositionIdMatch(): void
    {
        $matchingRun = 'run_md_venue_opened_bridge_match';
        $this->entry('SOLUSDT', $matchingRun, 'bridge-match', 'scalper', 'fake', 'perpetual', [
            'trade_id' => 'md-venue-bridge-match',
        ], '2026-07-19 17:00:00+00', 2440, 'okx');
        $this->opened(
            'SOLUSDT',
            $matchingRun,
            'md-venue-bridge-match',
            'md-venue-bridge-position-match',
            '2026-07-19 17:00:30+00',
            2441,
            'fake',
            'perpetual',
            'okx',
        );
        $this->close('SOLUSDT', $matchingRun, [
            'pnl' => 5.0,
        ], 'md-venue-bridge-position-match', '2026-07-19 17:01:00+00', 2442, 'fake', 'perpetual', 'okx');

        $matching = $this->conn->fetchAssociative(
            'SELECT internal_trade_id, trade_id, position_id, close_event_id, close_matched_by, analysis_status, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$matchingRun],
        );

        self::assertIsArray($matching);
        self::assertNull($matching['internal_trade_id']);
        self::assertSame('md-venue-bridge-match', $matching['trade_id']);
        self::assertSame('md-venue-bridge-position-match', $matching['position_id']);
        self::assertSame(2442, (int) $matching['close_event_id']);
        self::assertSame('matched_position_id', $matching['close_matched_by']);
        self::assertSame('matched_closed', $matching['analysis_status']);

        $mismatchRun = 'run_md_venue_opened_bridge_mismatch';
        $this->entry('SOLUSDT', $mismatchRun, 'bridge-mismatch', 'scalper', 'fake', 'perpetual', [
            'trade_id' => 'md-venue-bridge-mismatch',
        ], '2026-07-19 17:10:00+00', 2450, 'okx');
        $this->opened(
            'SOLUSDT',
            $mismatchRun,
            'md-venue-bridge-mismatch',
            'md-venue-bridge-position-mismatch',
            '2026-07-19 17:10:30+00',
            2451,
            'fake',
            'perpetual',
            'hyperliquid',
        );
        $this->close('SOLUSDT', $mismatchRun, [
            'pnl' => 99.0,
        ], 'md-venue-bridge-position-mismatch', '2026-07-19 17:11:00+00', 2452, 'fake', 'perpetual', 'hyperliquid');

        $mismatch = $this->conn->fetchAssociative(
            'SELECT internal_trade_id, trade_id, position_id, close_event_id, close_matched_by, analysis_status, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$mismatchRun],
        );

        self::assertIsArray($mismatch);
        self::assertNull($mismatch['internal_trade_id']);
        self::assertSame('md-venue-bridge-mismatch', $mismatch['trade_id']);
        self::assertNull($mismatch['position_id'], 'a mismatched venue must not create the opened bridge');
        self::assertNull($mismatch['close_event_id']);
        self::assertSame('unmatched', $mismatch['close_matched_by']);
        self::assertSame('unmatched', $mismatch['analysis_status']);
        self::assertNull($mismatch['recorded_pnl_usdt']);
    }

    public function testCertifiedNetPnlRequiresExplicitCompleteFinancialContract(): void
    {
        $run = 'run_certified_net_contract';

        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-net-win',
            'risk_usdt' => 5.0,
            'initial_stop_price' => 95.0,
            'planned_r_multiple' => 1.5,
        ], '2026-06-25 10:00:00+00', 2100);
        $this->close('BTCUSDT', $run, [
            'internal_trade_id' => 'itd-net-win',
            'pnl' => 9.44,
            'gross_realized_pnl_usdt' => 9.6,
            'recorded_pnl_usdt' => 9.44,
            'entry_fee_usdt' => 0.05,
            'exit_fee_usdt' => 0.05,
            'other_trading_fees_usdt' => 0.01,
            'funding_usdt' => 0.20,
            'spread_cost_usdt' => 0.10,
            'slippage_cost_usdt' => 0.15,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_vwap' => 100.6,
            'entry_qty' => 1.0,
            'exit_vwap' => 110.2,
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
        ], null, '2026-06-25 10:15:00+00', 2101, 'fake', 'perpetual');

        $this->entry('ETHUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-net-missing-fee',
        ], '2026-06-25 11:00:00+00', 2110);
        $this->close('ETHUSDT', $run, [
            'internal_trade_id' => 'itd-net-missing-fee',
            'gross_realized_pnl_usdt' => -4.0,
            'exit_fee_usdt' => 0.02,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => -0.10,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 1.0,
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
        ], null, '2026-06-25 11:10:00+00', 2111, 'fake', 'perpetual');

        $this->entry('SOLUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-net-legacy-costs',
        ], '2026-06-25 12:00:00+00', 2120);
        $this->close('SOLUSDT', $run, [
            'internal_trade_id' => 'itd-net-legacy-costs',
            'pnl' => 4.70,
            'gross_realized_pnl_usdt' => 5.0,
            'entry_fee_usdt' => 0.10,
            'exit_fee_usdt' => 0.10,
            'other_trading_fees_usdt' => 0.0,
            'funding' => 0.05,
            'spread_cost_usdt' => 0.0,
            'slippage' => 0.10,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 1.0,
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'mixed_legacy_payload',
        ], null, '2026-06-25 12:10:00+00', 2121, 'fake', 'perpetual');

        $this->entry('XRPUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-net-missing-quantities',
        ], '2026-06-25 13:00:00+00', 2130);
        $this->close('XRPUSDT', $run, [
            'internal_trade_id' => 'itd-net-missing-quantities',
            'gross_realized_pnl_usdt' => 3.0,
            'entry_fee_usdt' => 0.01,
            'exit_fee_usdt' => 0.01,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
        ], null, '2026-06-25 13:10:00+00', 2131, 'fake', 'perpetual');

        $this->entry('LTCUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-net-cost-evidence-only',
        ], '2026-06-25 14:00:00+00', 2140);
        $this->close('LTCUSDT', $run, [
            'internal_trade_id' => 'itd-net-cost-evidence-only',
            'funding_usdt' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
        ], null, '2026-06-25 14:10:00+00', 2141, 'fake', 'perpetual');

        $this->entry('ADAUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-net-zero-quantities',
        ], '2026-06-25 15:00:00+00', 2150);
        $this->close('ADAUSDT', $run, [
            'internal_trade_id' => 'itd-net-zero-quantities',
            'gross_realized_pnl_usdt' => 0.0,
            'entry_fee_usdt' => 0.0,
            'exit_fee_usdt' => 0.0,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 0.0,
            'exit_qty' => 0.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
        ], null, '2026-06-25 15:10:00+00', 2151, 'fake', 'perpetual');

        $this->entry('DOGEUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-net-negative-cost',
        ], '2026-06-25 16:00:00+00', 2160);
        $this->close('DOGEUSDT', $run, [
            'internal_trade_id' => 'itd-net-negative-cost',
            'gross_realized_pnl_usdt' => 2.0,
            'entry_fee_usdt' => -0.01,
            'exit_fee_usdt' => 0.01,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 1.0,
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
        ], null, '2026-06-25 16:10:00+00', 2161, 'fake', 'perpetual');

        $rows = $this->conn->fetchAllAssociative(
            'SELECT symbol, gross_realized_pnl_usdt, entry_fee_usdt, exit_fee_usdt,
                    other_trading_fees_usdt, funding_usdt, spread_cost_usdt,
                    slippage_cost_usdt, borrow_cost_usdt, liquidation_fee_usdt,
                    total_known_cost_usdt, net_pnl_usdt, cost_completeness,
                    pnl_source, pnl_quality_flags, risk_usdt_at_entry,
                    realized_net_pnl_r, position_fully_closed
             FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY symbol',
            [$run],
        );

        self::assertCount(7, $rows);
        $bySymbol = [];
        foreach ($rows as $row) {
            $bySymbol[$row['symbol']] = $row;
        }

        self::assertSame('partial', $bySymbol['DOGEUSDT']['cost_completeness']);
        self::assertNull($bySymbol['DOGEUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('negative_cost_component', (string) $bySymbol['DOGEUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['ADAUSDT']['cost_completeness']);
        self::assertNull($bySymbol['ADAUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('quantity_mismatch', (string) $bySymbol['ADAUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['BTCUSDT']['cost_completeness']);
        self::assertSame('fake_paper_fill_ledger_v1', $bySymbol['BTCUSDT']['pnl_source']);
        self::assertEqualsWithDelta(9.6, (float) $bySymbol['BTCUSDT']['gross_realized_pnl_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $bySymbol['BTCUSDT']['entry_fee_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $bySymbol['BTCUSDT']['exit_fee_usdt'], 1e-9);
        self::assertNull($bySymbol['BTCUSDT']['total_known_cost_usdt']);
        self::assertNull($bySymbol['BTCUSDT']['net_pnl_usdt']);
        self::assertNull($bySymbol['BTCUSDT']['realized_net_pnl_r']);
        self::assertTrue(filter_var($bySymbol['BTCUSDT']['position_fully_closed'], FILTER_VALIDATE_BOOLEAN));
        self::assertStringContainsString('ledger_quantity_aggregate_missing', (string) $bySymbol['BTCUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['ETHUSDT']['cost_completeness']);
        self::assertNull($bySymbol['ETHUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('missing_entry_fee', (string) $bySymbol['ETHUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['SOLUSDT']['cost_completeness']);
        self::assertEqualsWithDelta(0.05, (float) $bySymbol['SOLUSDT']['funding_usdt'], 1e-9);
        self::assertNull($bySymbol['SOLUSDT']['slippage_cost_usdt']);
        self::assertNull($bySymbol['SOLUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('missing_funding', (string) $bySymbol['SOLUSDT']['pnl_quality_flags']);
        self::assertStringContainsString('missing_slippage_cost', (string) $bySymbol['SOLUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['XRPUSDT']['cost_completeness']);
        self::assertNull($bySymbol['XRPUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('quantity_mismatch', (string) $bySymbol['XRPUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['LTCUSDT']['cost_completeness']);
        self::assertNull($bySymbol['LTCUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('missing_gross_pnl', (string) $bySymbol['LTCUSDT']['pnl_quality_flags']);
    }

    public function testCanonicalLongLedgerAggregatesPartialFillsIntoCertifiedPnl(): void
    {
        $run = 'run_ledger_complete_long';
        $internalTradeId = 'itd-ledger-complete-long';
        $positionId = 'position-ledger-complete-long';

        $this->entry('BTCUSDT', $run, 'ledger-complete', 'scalper', 'fake', 'paper', [
            'internal_trade_id' => $internalTradeId,
        ], '2026-08-10 10:00:00+00', 2800, 'hyperliquid');
        $this->close('BTCUSDT', $run, [
            'internal_trade_id' => $internalTradeId,
            // Ces valeurs provider ne constituent pas le brut certifié : le ledger doit
            // recalculer 8.40, mais conserver le PnL enregistré séparément.
            'gross_realized_pnl_usdt' => 999.0,
            'recorded_pnl_usdt' => 7.77,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
        ], $positionId, '2026-08-10 10:20:00+00', 2801, 'fake', 'paper', 'hyperliquid');
        $this->canonicalLifecycle(2800, 2801, $internalTradeId, $positionId, 'trade-ledger-complete-long');
        $this->conn->executeStatement(
            'INSERT INTO indicator_snapshots (symbol, timeframe, exchange, market_data_venue, market_type, kline_time, values)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb)',
            ['BTCUSDT', '1m', 'fake', 'hyperliquid', 'paper', '2026-08-10 09:59:00+00', '{}'],
        );

        // Deux entrées partielles et deux sorties partielles : le PnL brut est 8.40 USDT
        // ((110 * .5 + 108 * .5) - (100 * .4 + 101 * .6)). Les coûts sont exclusivement
        // le ledger durable, avec les zéros explicitement documentés.
        $this->ledgerFill('BTCUSDT', $internalTradeId, $positionId, 'ledger-entry-a', 'entry', 100.0, 0.4, '2026-08-10 10:00:01+00', [
            'fee_usdt' => 0.02, 'spread_cost_usdt' => 0.01, 'slippage_cost_usdt' => 0.02,
            'funding_usdt' => 0.0, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);
        $this->ledgerFill('BTCUSDT', $internalTradeId, $positionId, 'ledger-entry-b', 'entry', 101.0, 0.6, '2026-08-10 10:00:20+00', [
            'fee_usdt' => 0.03, 'spread_cost_usdt' => 0.01, 'slippage_cost_usdt' => 0.03,
            'funding_usdt' => 0.0, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);
        $this->ledgerFill('BTCUSDT', $internalTradeId, $positionId, 'ledger-exit-tp1', 'exit', 110.0, 0.5, '2026-08-10 10:10:00+00', [
            'fee_usdt' => 0.04, 'spread_cost_usdt' => 0.02, 'slippage_cost_usdt' => 0.03,
            'funding_usdt' => 0.0, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);
        $this->ledgerFill('BTCUSDT', $internalTradeId, $positionId, 'ledger-exit-final', 'exit', 108.0, 0.5, '2026-08-10 10:20:00+00', [
            'fee_usdt' => 0.05, 'spread_cost_usdt' => 0.01, 'slippage_cost_usdt' => 0.02,
            'funding_usdt' => 0.0, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);
        self::assertSame(
            ['BUY', 'BUY', 'SELL', 'SELL'],
            $this->conn->fetchFirstColumn('SELECT side FROM fill_cost_ledger WHERE internal_trade_id = ? ORDER BY occurred_at', [$internalTradeId]),
        );

        $record = $this->conn->fetchAssociative(
            'SELECT to_jsonb(v) AS analysis
             FROM position_trade_analysis_v2 v WHERE entry_event_id = ?',
            [2800],
        );
        self::assertIsArray($record);
        $row = json_decode((string) $record['analysis'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        foreach (['entry_qty', 'entry_vwap', 'exit_qty', 'exit_vwap', 'remaining_qty', 'quantity_status'] as $field) {
            self::assertArrayHasKey($field, $row, "le ledger doit projeter `$field`");
        }
        self::assertSame('canonical', $row['lineage_classification']);
        self::assertSame('complete', $row['cost_completeness']);
        self::assertEqualsWithDelta(1.0, (float) $row['entry_qty'], 1e-9);
        self::assertEqualsWithDelta(100.6, (float) $row['entry_vwap'], 1e-9);
        self::assertEqualsWithDelta(1.0, (float) $row['exit_qty'], 1e-9);
        self::assertEqualsWithDelta(109.0, (float) $row['exit_vwap'], 1e-9);
        self::assertEqualsWithDelta(0.0, (float) $row['remaining_qty'], 1e-9);
        self::assertSame('complete', $row['quantity_status']);
        self::assertTrue(filter_var($row['position_fully_closed'], FILTER_VALIDATE_BOOLEAN));
        self::assertEqualsWithDelta(7.77, (float) $row['recorded_pnl_usdt'], 1e-9);
        self::assertEqualsWithDelta(8.4, (float) $row['gross_realized_pnl_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $row['entry_fee_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.09, (float) $row['exit_fee_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.0, (float) $row['funding_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $row['spread_cost_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.10, (float) $row['slippage_cost_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.0, (float) $row['borrow_cost_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.0, (float) $row['liquidation_fee_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.29, (float) $row['total_known_cost_usdt'], 1e-9);
        self::assertEqualsWithDelta(8.11, (float) $row['net_pnl_usdt'], 1e-9);
        self::assertEqualsWithDelta(8.11, (float) $row['canonical_net_pnl_usdt'], 1e-9);
        self::assertIsArray($row['pnl_quality_flags']);
        self::assertNotContains('ledger_quantity_aggregate_missing', $row['pnl_quality_flags']);
    }

    public function testLedgerAggregateHelperProjectsCompleteExactFillEvidence(): void
    {
        $internalTradeId = 'itd-helper-complete';

        $this->ledgerFill('BTCUSDT', $internalTradeId, 'position-helper-complete', 'helper-entry-a', 'entry', 100.0, 0.4, '2026-08-11 10:00:01+00', [
            'fee_usdt' => 0.02, 'funding_usdt' => 0.10, 'spread_cost_usdt' => 0.01,
            'slippage_cost_usdt' => 0.02, 'borrow_cost_usdt' => 0.0,
        ]);
        $this->ledgerFill('BTCUSDT', $internalTradeId, 'position-helper-complete', 'helper-entry-b', 'entry', 101.0, 0.6, '2026-08-11 10:00:20+00', [
            // Provider raw fee metadata is not a certification input once fee_usdt is normalized.
            'fee_amount' => -0.50, 'fee_currency' => 'USDC', 'fee_usdt' => 0.03,
            'funding_usdt' => -0.05, 'spread_cost_usdt' => 0.01,
            'slippage_cost_usdt' => 0.03, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);
        $this->ledgerFill('BTCUSDT', $internalTradeId, 'position-helper-complete', 'helper-exit-a', 'exit', 110.0, 0.5, '2026-08-11 10:10:00+00', [
            'fee_usdt' => 0.04, 'funding_usdt' => 0.0, 'spread_cost_usdt' => 0.02,
            'slippage_cost_usdt' => 0.03, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);
        $this->ledgerFill('BTCUSDT', $internalTradeId, 'position-helper-complete', 'helper-exit-b', 'exit', 108.0, 0.5, '2026-08-11 10:20:00+00', [
            'fee_usdt' => 0.05, 'funding_usdt' => 0.0, 'spread_cost_usdt' => 0.01,
            'slippage_cost_usdt' => 0.02, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);
        $this->ledgerFill('BTCUSDT', $internalTradeId, 'position-helper-complete', 'helper-funding', 'funding', null, null, '2026-08-11 10:20:30+00', [
            'funding_usdt' => -0.02,
        ]);
        foreach (['fill_cancelled', 'fill_corrected', 'fill_reversed', 'voided'] as $index => $excludedFlag) {
            $this->ledgerFill('BTCUSDT', $internalTradeId, 'position-helper-complete', 'helper-excluded-' . $excludedFlag, 'exit', 999.0, 99.0, '2026-08-11 10:2' . ($index + 1) . ':00+00', [
                'fee_usdt' => 99.0, 'quality_flags' => [$excludedFlag],
            ]);
        }

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM position_trade_ledger_aggregate_v1 WHERE internal_trade_id = ?',
            [$internalTradeId],
        );
        self::assertIsArray($row);
        self::assertSame(5, (int) $row['ledger_row_count']);
        self::assertTrue(filter_var($row['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame(2, (int) $row['entry_fill_count']);
        self::assertSame(2, (int) $row['entry_valid_fill_count']);
        self::assertStringContainsString('10:00:01', (string) $row['entry_first_fill_at']);
        self::assertStringContainsString('10:00:20', (string) $row['entry_last_fill_at']);
        self::assertEqualsWithDelta(1.0, (float) $row['entry_qty'], 1e-9);
        self::assertEqualsWithDelta(100.6, (float) $row['entry_notional'], 1e-9);
        self::assertEqualsWithDelta(100.6, (float) $row['entry_vwap'], 1e-9);
        self::assertSame(2, (int) $row['exit_fill_count']);
        self::assertSame(2, (int) $row['exit_valid_fill_count']);
        self::assertStringContainsString('10:10:00', (string) $row['exit_first_fill_at']);
        self::assertStringContainsString('10:20:00', (string) $row['exit_last_fill_at']);
        self::assertEqualsWithDelta(1.0, (float) $row['exit_qty'], 1e-9);
        self::assertEqualsWithDelta(109.0, (float) $row['exit_notional'], 1e-9);
        self::assertEqualsWithDelta(109.0, (float) $row['exit_vwap'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $row['entry_fee_usdt'], 1e-9);
        self::assertTrue(filter_var($row['entry_fee_usdt_complete'], FILTER_VALIDATE_BOOLEAN));
        self::assertEqualsWithDelta(0.09, (float) $row['exit_fee_usdt'], 1e-9);
        self::assertTrue(filter_var($row['exit_fee_usdt_complete'], FILTER_VALIDATE_BOOLEAN));
        self::assertEqualsWithDelta(0.14, (float) $row['fee_usdt'], 1e-9);
        self::assertTrue(filter_var($row['fee_usdt_complete'], FILTER_VALIDATE_BOOLEAN), 'non-fill funding rows do not make trade fees incomplete');
        self::assertSame(4, (int) $row['spread_cost_explicit_count']);
        self::assertEqualsWithDelta(0.05, (float) $row['spread_cost_usdt'], 1e-9);
        self::assertSame(4, (int) $row['slippage_cost_explicit_count']);
        self::assertEqualsWithDelta(0.10, (float) $row['slippage_cost_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.03, (float) $row['funding_usdt'], 1e-9, 'separate funding rows remain signed and aggregated');
        self::assertEqualsWithDelta(0.0, (float) $row['borrow_cost_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.0, (float) $row['liquidation_fee_usdt'], 1e-9);
        self::assertSame(1, (int) $row['entry_side_cardinality']);
        self::assertSame('{BUY}', $row['entry_sides']);
        self::assertSame(1, (int) $row['exit_side_cardinality']);
        self::assertSame('{SELL}', $row['exit_sides']);
        self::assertEqualsWithDelta(0.0, (float) $row['remaining_qty'], 1e-9);
        self::assertSame('complete', $row['quantity_status']);
        self::assertTrue(filter_var($row['position_fully_closed'], FILTER_VALIDATE_BOOLEAN));
    }

    public function testLedgerAggregateHelperPreservesMissingExitAndCostApplicability(): void
    {
        $internalTradeId = 'itd-helper-open';
        $this->ledgerFill('ETHUSDT', $internalTradeId, 'position-helper-open', 'helper-open-entry', 'entry', 100.0, 1.0, '2026-08-11 11:00:01+00');

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM position_trade_ledger_aggregate_v1 WHERE internal_trade_id = ?',
            [$internalTradeId],
        );
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['entry_fill_count']);
        self::assertSame(0, (int) $row['exit_fill_count']);
        self::assertNull($row['exit_first_fill_at']);
        self::assertNull($row['exit_qty']);
        self::assertNull($row['exit_vwap']);
        self::assertFalse(filter_var($row['entry_fee_usdt_complete'], FILTER_VALIDATE_BOOLEAN));
        self::assertNull($row['entry_fee_usdt']);
        self::assertNull($row['exit_fee_usdt_complete']);
        self::assertNull($row['exit_fee_usdt']);
        self::assertNull($row['spread_cost_usdt']);
        self::assertNull($row['slippage_cost_usdt']);
        self::assertNull($row['funding_usdt']);
        self::assertNull($row['borrow_cost_usdt']);
        self::assertNull($row['liquidation_fee_usdt']);
        self::assertEqualsWithDelta(1.0, (float) $row['remaining_qty'], 1e-9);
        self::assertSame('open_position', $row['quantity_status']);
        self::assertFalse(filter_var($row['position_fully_closed'], FILTER_VALIDATE_BOOLEAN));
    }

    public function testLedgerAggregateHelperRejectsInvalidQuantitativeAndCostEvidence(): void
    {
        $this->ledgerFill('ADAUSDT', 'itd-helper-invalid-null-price', 'position-invalid-null-price', 'invalid-null-price-entry', 'entry', null, 1.0, '2026-08-11 11:10:00+00', [
            'notional' => 100.0, 'fee_usdt' => 0.01,
        ]);
        $this->ledgerFill('ADAUSDT', 'itd-helper-invalid-null-price', 'position-invalid-null-price', 'invalid-null-price-exit', 'exit', 101.0, 1.0, '2026-08-11 11:11:00+00', ['fee_usdt' => 0.01]);

        $this->ledgerFill('XRPUSDT', 'itd-helper-invalid-zero-price', 'position-invalid-zero-price', 'invalid-zero-price-entry', 'entry', 100.0, 1.0, '2026-08-11 11:20:00+00', ['fee_usdt' => 0.01]);
        $this->ledgerFill('XRPUSDT', 'itd-helper-invalid-zero-price', 'position-invalid-zero-price', 'invalid-zero-price-exit', 'exit', 0.0, 1.0, '2026-08-11 11:21:00+00', [
            'notional' => 101.0, 'fee_usdt' => 0.01,
        ]);

        $this->ledgerFill('DOGEUSDT', 'itd-helper-invalid-cost', 'position-invalid-cost', 'invalid-cost-entry', 'entry', 100.0, 1.0, '2026-08-11 11:30:00+00', [
            'fee_amount' => -0.10, 'fee_currency' => 'USDT', 'fee_usdt' => -0.10,
            'spread_cost_usdt' => -0.20, 'slippage_cost_usdt' => 0.03,
        ]);
        $this->ledgerFill('DOGEUSDT', 'itd-helper-invalid-cost', 'position-invalid-cost', 'invalid-cost-exit', 'exit', 101.0, 1.0, '2026-08-11 11:31:00+00', [
            'fee_usdt' => 0.01, 'spread_cost_usdt' => 0.05, 'slippage_cost_usdt' => -0.10,
        ]);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM position_trade_ledger_aggregate_v1
             WHERE internal_trade_id LIKE ? ORDER BY internal_trade_id',
            ['itd-helper-invalid-%'],
        );
        $byTrade = [];
        foreach ($rows as $row) {
            $byTrade[$row['internal_trade_id']] = $row;
        }

        self::assertSame('invalid_fill_quantity', $byTrade['itd-helper-invalid-null-price']['quantity_status']);
        self::assertSame(0, (int) $byTrade['itd-helper-invalid-null-price']['entry_valid_fill_count']);
        self::assertNull($byTrade['itd-helper-invalid-null-price']['entry_qty']);
        self::assertNull($byTrade['itd-helper-invalid-null-price']['entry_notional']);
        self::assertNull($byTrade['itd-helper-invalid-null-price']['entry_vwap']);

        self::assertSame('invalid_fill_quantity', $byTrade['itd-helper-invalid-zero-price']['quantity_status']);
        self::assertSame(0, (int) $byTrade['itd-helper-invalid-zero-price']['exit_valid_fill_count']);
        self::assertNull($byTrade['itd-helper-invalid-zero-price']['exit_qty']);
        self::assertNull($byTrade['itd-helper-invalid-zero-price']['exit_notional']);
        self::assertNull($byTrade['itd-helper-invalid-zero-price']['exit_vwap']);

        $cost = $byTrade['itd-helper-invalid-cost'];
        self::assertFalse(filter_var($cost['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame(1, (int) $cost['spread_cost_explicit_count']);
        self::assertEqualsWithDelta(0.05, (float) $cost['spread_cost_usdt'], 1e-9);
        self::assertSame(1, (int) $cost['slippage_cost_explicit_count']);
        self::assertEqualsWithDelta(0.03, (float) $cost['slippage_cost_usdt'], 1e-9);
        self::assertSame(0, (int) $cost['entry_fee_usdt_count']);
        self::assertNull($cost['entry_fee_usdt']);
        self::assertFalse(filter_var($cost['entry_fee_usdt_complete'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame(1, (int) $cost['fee_usdt_count']);
        self::assertEqualsWithDelta(0.01, (float) $cost['fee_usdt'], 1e-9);
        self::assertFalse(filter_var($cost['fee_usdt_complete'], FILTER_VALIDATE_BOOLEAN));
    }

    public function testLedgerAggregateHelperUsesExactQuantityStatusPrecedenceAndTolerance(): void
    {
        $this->ledgerFill('BTCUSDT', 'itd-helper-status-missing-entry', 'position-status-missing-entry', 'status-missing-entry-exit', 'exit', 101.0, 1.0, '2026-08-11 11:40:00+00', ['fee_usdt' => 0.01]);

        $this->ledgerFill('ETHUSDT', 'itd-helper-status-open', 'position-status-open', 'status-open-invalid-entry', 'entry', null, 1.0, '2026-08-11 11:41:00+00', ['fee_usdt' => 0.01]);

        $this->ledgerFill('SOLUSDT', 'itd-helper-status-invalid', 'position-status-invalid', 'status-invalid-entry', 'entry', 100.0, 1.0, '2026-08-11 11:42:00+00', ['fee_usdt' => 0.01]);
        $this->ledgerFill('SOLUSDT', 'itd-helper-status-invalid', 'position-status-invalid', 'status-invalid-exit', 'exit', null, 1.0, '2026-08-11 11:43:00+00', ['fee_usdt' => 0.01]);

        $this->ledgerFill('XRPUSDT', 'itd-helper-status-mismatch', 'position-status-mismatch', 'status-mismatch-entry', 'entry', 100.0, 1.0, '2026-08-11 11:44:00+00', ['fee_usdt' => 0.01]);
        $this->ledgerFill('XRPUSDT', 'itd-helper-status-mismatch', 'position-status-mismatch', 'status-mismatch-exit', 'exit', 101.0, 1.00000002, '2026-08-11 11:45:00+00', ['fee_usdt' => 0.01]);

        $this->ledgerFill('ADAUSDT', 'itd-helper-status-boundary', 'position-status-boundary', 'status-boundary-entry', 'entry', 100.0, 1.0, '2026-08-11 11:46:00+00', ['fee_usdt' => 0.01]);
        $this->ledgerFill('ADAUSDT', 'itd-helper-status-boundary', 'position-status-boundary', 'status-boundary-exit', 'exit', 101.0, 1.00000001, '2026-08-11 11:47:00+00', ['fee_usdt' => 0.01]);

        $this->ledgerFill('DOGEUSDT', 'itd-helper-status-partial', 'position-status-partial', 'status-partial-entry', 'entry', 100.0, 1.0, '2026-08-11 11:48:00+00', ['fee_usdt' => 0.01]);
        $this->ledgerFill('DOGEUSDT', 'itd-helper-status-partial', 'position-status-partial', 'status-partial-exit', 'exit', 101.0, 0.4, '2026-08-11 11:49:00+00', ['fee_usdt' => 0.01]);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT internal_trade_id, quantity_status, remaining_qty, position_fully_closed
             FROM position_trade_ledger_aggregate_v1 WHERE internal_trade_id LIKE ?',
            ['itd-helper-status-%'],
        );
        $byTrade = [];
        foreach ($rows as $row) {
            $byTrade[$row['internal_trade_id']] = $row;
        }

        self::assertSame('missing_entry_fill', $byTrade['itd-helper-status-missing-entry']['quantity_status']);
        self::assertSame('open_position', $byTrade['itd-helper-status-open']['quantity_status']);
        self::assertSame('invalid_fill_quantity', $byTrade['itd-helper-status-invalid']['quantity_status']);
        self::assertSame('quantity_mismatch', $byTrade['itd-helper-status-mismatch']['quantity_status']);
        self::assertSame('complete', $byTrade['itd-helper-status-boundary']['quantity_status']);
        self::assertEqualsWithDelta(0.0, (float) $byTrade['itd-helper-status-boundary']['remaining_qty'], 1e-12);
        self::assertTrue(filter_var($byTrade['itd-helper-status-boundary']['position_fully_closed'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame('partial_exit', $byTrade['itd-helper-status-partial']['quantity_status']);
        self::assertEqualsWithDelta(0.6, (float) $byTrade['itd-helper-status-partial']['remaining_qty'], 1e-12);
        self::assertFalse(filter_var($byTrade['itd-helper-status-partial']['position_fully_closed'], FILTER_VALIDATE_BOOLEAN));
    }

    public function testLedgerAggregateHelperRejectsNanQuantitiesAndFinancialEvidence(): void
    {
        foreach (['price', 'quantity', 'notional'] as $index => $field) {
            $internalTradeId = 'itd-helper-nan-' . $field;
            $this->ledgerFill('NANUSDT', $internalTradeId, 'position-nan-' . $field, 'nan-' . $field . '-entry', 'entry', 100.0, 1.0, '2026-08-11 13:0' . $index . ':00+00', [
                $field => 'NaN', 'fee_usdt' => 0.01,
            ]);
            $this->ledgerFill('NANUSDT', $internalTradeId, 'position-nan-' . $field, 'nan-' . $field . '-exit', 'exit', 101.0, 1.0, '2026-08-11 13:1' . $index . ':00+00', ['fee_usdt' => 0.01]);
        }

        $this->ledgerFill('NANUSDT', 'itd-helper-nan-costs', 'position-nan-costs', 'nan-costs-entry', 'entry', 100.0, 1.0, '2026-08-11 13:20:00+00', [
            'fee_usdt' => 'NaN', 'spread_cost_usdt' => 'NaN', 'borrow_cost_usdt' => 'NaN', 'funding_usdt' => 'NaN',
        ]);
        $this->ledgerFill('NANUSDT', 'itd-helper-nan-costs', 'position-nan-costs', 'nan-costs-exit', 'exit', 101.0, 1.0, '2026-08-11 13:21:00+00', [
            'fee_usdt' => 0.01, 'slippage_cost_usdt' => 'NaN', 'liquidation_fee_usdt' => 'NaN',
        ]);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM position_trade_ledger_aggregate_v1 WHERE internal_trade_id LIKE ?',
            ['itd-helper-nan-%'],
        );
        $byTrade = [];
        foreach ($rows as $row) {
            $byTrade[$row['internal_trade_id']] = $row;
        }

        foreach (['price', 'quantity', 'notional'] as $field) {
            $row = $byTrade['itd-helper-nan-' . $field];
            self::assertSame('invalid_fill_quantity', $row['quantity_status']);
            self::assertSame(0, (int) $row['entry_valid_fill_count']);
            self::assertNull($row['entry_qty']);
            self::assertNull($row['entry_notional']);
            self::assertNull($row['entry_vwap']);
        }

        $costs = $byTrade['itd-helper-nan-costs'];
        self::assertFalse(filter_var($costs['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame(0, (int) $costs['entry_fee_usdt_count']);
        self::assertNull($costs['entry_fee_usdt']);
        self::assertFalse(filter_var($costs['entry_fee_usdt_complete'], FILTER_VALIDATE_BOOLEAN));
        foreach (['spread_cost', 'slippage_cost', 'borrow_cost', 'liquidation_fee', 'funding'] as $component) {
            self::assertSame(0, (int) $costs[$component . '_explicit_count']);
            self::assertNull($costs[$component . '_usdt']);
        }
    }

    public function testLedgerAggregateHelperExcludesNegativeBorrowAndLiquidationEvidence(): void
    {
        $internalTradeId = 'itd-helper-negative-non-signed-costs';
        $this->ledgerFill('BNBUSDT', $internalTradeId, 'position-negative-costs', 'negative-cost-entry', 'entry', 100.0, 1.0, '2026-08-11 13:30:00+00', [
            'fee_usdt' => 0.01, 'borrow_cost_usdt' => -0.20, 'liquidation_fee_usdt' => 0.10,
        ]);
        $this->ledgerFill('BNBUSDT', $internalTradeId, 'position-negative-costs', 'negative-cost-exit', 'exit', 101.0, 1.0, '2026-08-11 13:31:00+00', [
            'fee_usdt' => 0.01, 'borrow_cost_usdt' => 0.05, 'liquidation_fee_usdt' => -0.30,
        ]);

        $row = $this->conn->fetchAssociative(
            'SELECT * FROM position_trade_ledger_aggregate_v1 WHERE internal_trade_id = ?',
            [$internalTradeId],
        );
        self::assertIsArray($row);
        self::assertFalse(filter_var($row['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame(1, (int) $row['borrow_cost_explicit_count']);
        self::assertEqualsWithDelta(0.05, (float) $row['borrow_cost_usdt'], 1e-9);
        self::assertSame(1, (int) $row['liquidation_fee_explicit_count']);
        self::assertEqualsWithDelta(0.10, (float) $row['liquidation_fee_usdt'], 1e-9);
    }

    public function testLedgerAggregateHelperFailsClosedForMalformedQualityFlags(): void
    {
        $qualityCases = [
            'null' => null,
            'object' => ['unexpected' => true],
            'string' => 'unexpected',
        ];
        $index = 0;
        foreach ($qualityCases as $suffix => $qualityFlags) {
            $internalTradeId = 'itd-helper-quality-' . $suffix;
            $this->ledgerFill('AVAXUSDT', $internalTradeId, 'position-quality-' . $suffix, 'quality-' . $suffix . '-entry', 'entry', 100.0, 1.0, '2026-08-11 13:4' . $index . ':00+00', [
                'fee_usdt' => 0.01, 'quality_flags' => $qualityFlags,
            ]);
            $this->ledgerFill('AVAXUSDT', $internalTradeId, 'position-quality-' . $suffix, 'quality-' . $suffix . '-exit', 'exit', 101.0, 1.0, '2026-08-11 13:5' . $index . ':00+00', ['fee_usdt' => 0.01]);
            ++$index;
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT internal_trade_id, ledger_row_count, ledger_quality_flagged_count, ledger_quality_valid
             FROM position_trade_ledger_aggregate_v1 WHERE internal_trade_id LIKE ?',
            ['itd-helper-quality-%'],
        );
        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertSame(2, (int) $row['ledger_row_count'], 'malformed flags fail closed instead of silently excluding evidence');
            self::assertSame(1, (int) $row['ledger_quality_flagged_count']);
            self::assertFalse(filter_var($row['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN));
        }
    }

    public function testLedgerAggregateHelperKeepsMismatchedProvenanceInSeparateIdentityGroups(): void
    {
        $internalTradeId = 'itd-helper-provenance';
        foreach ([['entry', 100.0], ['exit', 102.0]] as [$role, $price]) {
            $this->ledgerFill('SOLUSDT', $internalTradeId, 'position-helper-provenance', 'helper-canonical-' . $role, $role, $price, 1.0, '2026-08-11 12:0' . ($role === 'entry' ? '1' : '4') . ':00+00', ['fee_usdt' => 0.01]);
            $this->ledgerFill('SOLUSDT', $internalTradeId, 'position-helper-provenance', 'helper-wrong-venue-' . $role, $role, $price, 1.0, '2026-08-11 12:1' . ($role === 'entry' ? '1' : '4') . ':00+00', [
                'market_data_venue' => 'okx', 'fee_usdt' => 0.01,
            ]);
            $this->ledgerFill('SOLUSDT', $internalTradeId, 'position-helper-provenance', 'helper-wrong-cell-' . $role, $role, $price, 1.0, '2026-08-11 12:2' . ($role === 'entry' ? '1' : '4') . ':00+00', [
                'paper_execution_cell_id' => 'sha256:' . str_repeat('f', 64), 'fee_usdt' => 0.01,
                'quality_flags' => $role === 'entry' ? ['manual_review_required'] : [],
            ]);
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT market_data_venue, paper_execution_cell_id, ledger_row_count, ledger_quality_valid, quantity_status
             FROM position_trade_ledger_aggregate_v1 WHERE internal_trade_id = ?
             ORDER BY market_data_venue, paper_execution_cell_id',
            [$internalTradeId],
        );
        self::assertCount(3, $rows);
        self::assertSame(['hyperliquid', 'hyperliquid', 'okx'], array_column($rows, 'market_data_venue'));
        self::assertSame('sha256:' . str_repeat('a', 64), $rows[0]['paper_execution_cell_id']);
        self::assertSame('sha256:' . str_repeat('f', 64), $rows[1]['paper_execution_cell_id']);
        self::assertSame('sha256:' . str_repeat('a', 64), $rows[2]['paper_execution_cell_id']);
        self::assertSame([2, 2, 2], array_map('intval', array_column($rows, 'ledger_row_count')));
        self::assertTrue(filter_var($rows[0]['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN));
        self::assertFalse(filter_var($rows[1]['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN), 'any other non-empty quality flag blocks ledger validity');
        self::assertTrue(filter_var($rows[2]['ledger_quality_valid'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame(['complete', 'complete', 'complete'], array_column($rows, 'quantity_status'));
    }

    public function testLedgerCertificationFailsClosedWhenExitEvidenceIsMissing(): void
    {
        $run = 'run_ledger_missing_exit';

        $this->entry('ETHUSDT', $run, 'ledger-no-exit', 'scalper', 'fake', 'paper', ['internal_trade_id' => 'itd-ledger-no-exit'], '2026-08-10 11:00:00+00', 2810, 'hyperliquid');
        $this->close('ETHUSDT', $run, [
            'gross_realized_pnl_usdt' => 1.0,
            'recorded_pnl_usdt' => 1.0,
            'entry_fee_usdt' => 0.01,
            'exit_fee_usdt' => 0.0,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 1.0,
            // Le provider prétend à tort que la position est close : la vue doit
            // privilégier le ledger, qui ne contient aucune sortie durable.
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fill_cost_ledger_v1',
        ], 'position-ledger-no-exit', '2026-08-10 11:05:00+00', 2811, 'fake', 'paper', 'hyperliquid');
        $this->canonicalLifecycle(2810, 2811, 'itd-ledger-no-exit', 'position-ledger-no-exit', 'trade-ledger-no-exit');
        $this->ledgerFill('ETHUSDT', 'itd-ledger-no-exit', 'position-ledger-no-exit', 'missing-exit-entry', 'entry', 100.0, 1.0, '2026-08-10 11:00:01+00', [
            'fee_usdt' => 0.01, 'funding_usdt' => 0.0, 'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
        ]);

        $record = $this->conn->fetchAssociative(
            'SELECT to_jsonb(v) AS analysis
             FROM position_trade_analysis_v2 v WHERE entry_event_id = ?',
            [2810],
        );
        self::assertIsArray($record);
        $row = json_decode((string) $record['analysis'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        self::assertNotSame('complete', $row['cost_completeness']);
        self::assertNull($row['net_pnl_usdt']);
        self::assertNull($row['canonical_net_pnl_usdt']);
        self::assertArrayHasKey('quantity_status', $row);
        self::assertSame('open_position', $row['quantity_status']);
        self::assertIsArray($row['pnl_quality_flags']);
        self::assertContains('missing_exit_fill', $row['pnl_quality_flags']);
    }

    public function testLedgerCertificationFailsClosedForVenueAndPaperCellMismatch(): void
    {
        $run = 'run_ledger_provenance_mismatch';

        $this->entry('SOLUSDT', $run, 'ledger-wrong-provenance', 'scalper', 'fake', 'paper', ['internal_trade_id' => 'itd-ledger-wrong-provenance'], '2026-08-10 12:00:00+00', 2820, 'hyperliquid');
        $this->close('SOLUSDT', $run, $this->completeCloseContract(2.0), 'position-ledger-wrong-provenance', '2026-08-10 12:05:00+00', 2821, 'fake', 'paper', 'hyperliquid');
        $this->canonicalLifecycle(2820, 2821, 'itd-ledger-wrong-provenance', 'position-ledger-wrong-provenance', 'trade-ledger-wrong-provenance');
        foreach ([['entry', 100.0, 1.0], ['exit', 102.0, 1.0]] as [$role, $price, $quantity]) {
            $this->ledgerFill('SOLUSDT', 'itd-ledger-wrong-provenance', 'position-ledger-wrong-provenance', 'wrong-provenance-' . $role, $role, $price, $quantity, '2026-08-10 12:0' . ($role === 'entry' ? '1' : '4') . ':00+00', [
                'fee_usdt' => 0.01, 'funding_usdt' => 0.0, 'spread_cost_usdt' => 0.0,
                'slippage_cost_usdt' => 0.0, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
                'market_data_venue' => 'okx',
            ]);
        }

        $this->entry('XRPUSDT', $run, 'ledger-wrong-cell', 'scalper', 'fake', 'paper', ['internal_trade_id' => 'itd-ledger-wrong-cell'], '2026-08-10 13:00:00+00', 2830, 'hyperliquid');
        $this->close('XRPUSDT', $run, $this->completeCloseContract(2.0), 'position-ledger-wrong-cell', '2026-08-10 13:05:00+00', 2831, 'fake', 'paper', 'hyperliquid');
        $this->canonicalLifecycle(2830, 2831, 'itd-ledger-wrong-cell', 'position-ledger-wrong-cell', 'trade-ledger-wrong-cell');
        foreach ([['entry', 100.0, 1.0], ['exit', 102.0, 1.0]] as [$role, $price, $quantity]) {
            $this->ledgerFill('XRPUSDT', 'itd-ledger-wrong-cell', 'position-ledger-wrong-cell', 'wrong-cell-' . $role, $role, $price, $quantity, '2026-08-10 13:0' . ($role === 'entry' ? '1' : '4') . ':00+00', [
                'fee_usdt' => 0.01, 'funding_usdt' => 0.0, 'spread_cost_usdt' => 0.0,
                'slippage_cost_usdt' => 0.0, 'borrow_cost_usdt' => 0.0, 'liquidation_fee_usdt' => 0.0,
                'paper_execution_cell_id' => 'sha256:' . str_repeat('f', 64),
            ]);
        }

        // Deux motifs distincts : la venue doit correspondre avant toute agrégation,
        // puis la cellule Paper. Aucune ligne de ledger ne satisfait chaque contrat.
        self::assertSame(0, $this->exactLedgerAggregateMatchCount(2820));
        self::assertSame(2, $this->ledgerRowsMismatchingCanonicalField(2820, 'market_data_venue'));
        self::assertSame(0, $this->exactLedgerAggregateMatchCount(2830));
        self::assertSame(2, $this->ledgerRowsMismatchingCanonicalField(2830, 'paper_execution_cell_id'));

        $rows = $this->conn->fetchAllAssociative(
            'SELECT symbol, to_jsonb(v) AS analysis
             FROM position_trade_analysis_v2 v WHERE run_id = ? ORDER BY symbol',
            [$run],
        );
        self::assertSame(['SOLUSDT', 'XRPUSDT'], array_column($rows, 'symbol'));
        $bySymbol = [];
        foreach ($rows as $row) {
            $bySymbol[$row['symbol']] = json_decode((string) $row['analysis'], true, 512, JSON_THROW_ON_ERROR);
        }
        foreach ($bySymbol as $row) {
            self::assertIsArray($row);
            self::assertNotSame('complete', $row['cost_completeness']);
            self::assertNull($row['net_pnl_usdt']);
            self::assertNull($row['canonical_net_pnl_usdt']);
        }
        self::assertIsArray($bySymbol['SOLUSDT']['pnl_quality_flags']);
        self::assertContains('ledger_market_identity_mismatch', $bySymbol['SOLUSDT']['pnl_quality_flags']);
        self::assertIsArray($bySymbol['XRPUSDT']['pnl_quality_flags']);
        self::assertContains('ledger_paper_provenance_mismatch', $bySymbol['XRPUSDT']['pnl_quality_flags']);
    }

    public function testMalformedLegacyFinancialValuesDoNotBreakViewRead(): void
    {
        $run = 'run_malformed_financials';

        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-malformed-financials',
            'risk_usdt' => '1e1000000',
        ], '2026-06-25 17:00:00+00', 2170);
        $this->close('BTCUSDT', $run, [
            'internal_trade_id' => 'itd-malformed-financials',
            'gross_realized_pnl_usdt' => '9e999999',
            'entry_fee_usdt' => 'bad-fee',
            'exit_fee_usdt' => 0.01,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 1.0,
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'legacy_malformed_payload',
            'mfe_at' => '2026-99-99T10:00:00+00:00',
            'mae_at' => 'not-a-timestamp',
        ], null, '2026-06-25 17:10:00+00', 2171, 'fake', 'perpetual');

        $row = $this->conn->fetchAssociative(
            'SELECT risk_usdt_at_entry, gross_realized_pnl_usdt, entry_fee_usdt,
                    mfe_at, mae_at, net_pnl_usdt, cost_completeness, pnl_quality_flags
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run],
        );

        self::assertIsArray($row);
        self::assertNull($row['risk_usdt_at_entry']);
        self::assertNull($row['gross_realized_pnl_usdt']);
        self::assertNull($row['entry_fee_usdt']);
        self::assertNull($row['mfe_at']);
        self::assertNull($row['mae_at']);
        self::assertNull($row['net_pnl_usdt']);
        self::assertSame('partial', $row['cost_completeness']);
        self::assertStringContainsString('missing_gross_pnl', (string) $row['pnl_quality_flags']);
        self::assertStringContainsString('missing_entry_fee', (string) $row['pnl_quality_flags']);
    }

    public function testRiskGrossPnlRAndMfeMaeCertificationFieldsAreExposedConservatively(): void
    {
        $run = 'run_risk_mfe_mae_contract';

        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-risk-mfe-mae',
            'risk_usdt_at_entry' => 5.0,
            'risk_usdt' => 99.0,
            'initial_stop_price' => 95.0,
            'stop_final_price' => 80.0,
            'planned_r_multiple' => 1.8,
            'r_multiple_final' => 2.5,
            'entry_price' => 100.0,
            'notional_usdt' => 100.0,
        ], '2026-06-26 10:00:00+00', 2180);
        $this->close('BTCUSDT', $run, [
            'internal_trade_id' => 'itd-risk-mfe-mae',
            'gross_realized_pnl_usdt' => 10.0,
            'entry_fee_usdt' => 0.01,
            'exit_fee_usdt' => 0.01,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 1.0,
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fake_paper_fill_ledger_v1',
            'max_favorable_price' => 108.0,
            'max_adverse_price' => 97.0,
            'mfe_pct' => 0.08,
            'mae_pct' => 0.03,
            'mfe_at' => '2026-06-26T10:03:00+00:00',
            'mae_at' => '2026-06-26T10:01:00+00:00',
            'mfe_mae_source' => 'kline_1m_high_low',
            'mfe_mae_timeframe' => '1m',
            'mfe_mae_window_start' => '2026-06-26T10:00:00+00:00',
            'mfe_mae_window_end' => '2026-06-26T10:10:00+00:00',
            'mfe_mae_data_quality' => 'complete',
        ], null, '2026-06-26 10:10:00+00', 2181, 'fake', 'perpetual');

        $row = $this->conn->fetchAssociative(
            'SELECT risk_usdt_at_entry, initial_stop_price, stop_distance_pct,
                    planned_r_multiple, realized_gross_pnl_r, realized_net_pnl_r,
                    mfe_price, mae_price, mfe_at, mae_at, mfe_r, mae_r,
                    mfe_mae_data_quality, pnl_quality_flags
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run],
        );

        self::assertIsArray($row);
        self::assertEqualsWithDelta(5.0, (float) $row['risk_usdt_at_entry'], 1e-9);
        self::assertEqualsWithDelta(95.0, (float) $row['initial_stop_price'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $row['stop_distance_pct'], 1e-9);
        self::assertEqualsWithDelta(1.8, (float) $row['planned_r_multiple'], 1e-9);
        self::assertEqualsWithDelta(2.0, (float) $row['realized_gross_pnl_r'], 1e-9);
        self::assertNull($row['realized_net_pnl_r'], 'net R reste nul tant que le net PnL n est pas certifie');
        self::assertEqualsWithDelta(108.0, (float) $row['mfe_price'], 1e-9);
        self::assertEqualsWithDelta(97.0, (float) $row['mae_price'], 1e-9);
        self::assertSame(
            '2026-06-26T10:03:00+00:00',
            (new \DateTimeImmutable((string) $row['mfe_at']))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
        self::assertSame(
            '2026-06-26T10:01:00+00:00',
            (new \DateTimeImmutable((string) $row['mae_at']))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
        );
        self::assertEqualsWithDelta(1.6, (float) $row['mfe_r'], 1e-9);
        self::assertEqualsWithDelta(-0.6, (float) $row['mae_r'], 1e-9);
        self::assertSame('complete', $row['mfe_mae_data_quality']);
        self::assertStringContainsString('ledger_quantity_aggregate_missing', (string) $row['pnl_quality_flags']);
    }

    public function testNegativeRuntimeMfeDoesNotBecomePositiveR(): void
    {
        $run = 'run_negative_mfe_contract';

        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'fake', 'perpetual', [
            'internal_trade_id' => 'itd-negative-mfe',
            'risk_usdt_at_entry' => 5.0,
            'entry_price' => 100.0,
            'notional_usdt' => 100.0,
        ], '2026-06-26 11:00:00+00', 2190);
        $this->close('BTCUSDT', $run, [
            'internal_trade_id' => 'itd-negative-mfe',
            'gross_realized_pnl_usdt' => -2.0,
            'max_favorable_price' => 99.0,
            'max_adverse_price' => 101.0,
            'mfe_pct' => -0.01,
            'mae_pct' => -0.01,
            'mfe_mae_source' => 'kline_1m_high_low',
            'mfe_mae_data_quality' => 'complete',
        ], null, '2026-06-26 11:10:00+00', 2191, 'fake', 'perpetual');

        $row = $this->conn->fetchAssociative(
            'SELECT mfe_r, mae_r FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run],
        );

        self::assertIsArray($row);
        self::assertEqualsWithDelta(0.0, (float) $row['mfe_r'], 1e-9);
        self::assertEqualsWithDelta(0.0, (float) $row['mae_r'], 1e-9);
    }

    public function testCloseIsNotReusedAcrossEntriesSharingAPositionId(): void
    {
        $run = 'run_reuse';
        // 2 entrées + 2 clôtures partageant le même position_id : appariement 1-pour-1
        // par FIFO, jamais de réutilisation ni de multiplication de lignes.
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['position_id' => 'PSHARED'], '2026-06-17 09:00:00+00', 300);
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['position_id' => 'PSHARED'], '2026-06-17 09:01:00+00', 301);
        $this->close('BTCUSDT', $run, ['pnl' => 1.0], 'PSHARED', '2026-06-17 09:10:00+00', 400);
        $this->close('BTCUSDT', $run, ['pnl' => 2.0], 'PSHARED', '2026-06-17 09:11:00+00', 401);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT entry_event_id, close_event_id FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY entry_time',
            [$run]
        );

        self::assertCount(2, $rows, 'exactement 2 lignes (pas de produit cartésien)');
        $closeIds = array_column($rows, 'close_event_id');
        self::assertNotContains(null, $closeIds, 'les deux entrées sont rapprochées');
        self::assertSame($closeIds, array_unique($closeIds), 'chaque clôture sert une seule entrée');
    }

    public function testFifoSkipsExcessCloseBetweenTwoEntriesSharingTheSamePositionId(): void
    {
        $run = 'run_fifo_excess_close';

        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['position_id' => 'PFIFO'], '2026-06-17 09:00:00+00', 500);
        $this->close('BTCUSDT', $run, ['pnl' => 1.0], 'PFIFO', '2026-06-17 09:10:00+00', 501);
        // Clôture excédentaire entre deux entrées : elle ne doit pas voler le rang de E2.
        $this->close('BTCUSDT', $run, ['pnl' => 999.0], 'PFIFO', '2026-06-17 09:20:00+00', 502);
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['position_id' => 'PFIFO'], '2026-06-17 09:30:00+00', 503);
        $this->close('BTCUSDT', $run, ['pnl' => 3.0], 'PFIFO', '2026-06-17 09:40:00+00', 504);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT entry_event_id, close_event_id, close_match_status, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY entry_time',
            [$run]
        );

        self::assertCount(2, $rows, 'la clôture excédentaire ne crée aucune ligne entry-based');
        self::assertSame(500, (int) $rows[0]['entry_event_id']);
        self::assertSame(501, (int) $rows[0]['close_event_id']);
        self::assertEqualsWithDelta(1.0, (float) $rows[0]['recorded_pnl_usdt'], 1e-9);

        self::assertSame(503, (int) $rows[1]['entry_event_id']);
        self::assertSame('matched', $rows[1]['close_match_status']);
        self::assertSame(504, (int) $rows[1]['close_event_id'], 'E2 doit matcher C3, jamais Cx');
        self::assertEqualsWithDelta(3.0, (float) $rows[1]['recorded_pnl_usdt'], 1e-9);
    }

    public function testStaleReusedPositionIdIsNotAttributedToCurrentEntry(): void
    {
        $run = 'run_stale';
        // Une clôture ANCIENNE (08:00) porte un position_id `PR` qui sera réutilisé.
        $this->close('BTCUSDT', $run, ['pnl' => 999.0], 'PR', '2026-06-17 08:00:00+00', 700);
        // Nouvelle entrée (09:00) réutilisant `PR` via le pont : la clôture périmée
        // (antérieure à l'entrée) ne doit PAS lui être attribuée -> unmatched, pas de PnL.
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TS'], '2026-06-17 09:00:00+00', 701);
        $this->opened('BTCUSDT', $run, 'TS', 'PR', '2026-06-17 09:00:30+00', 702);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT trade_id, close_match_status, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run]
        );

        self::assertCount(1, $rows, 'une seule entrée (la clôture orpheline ne crée pas de ligne)');
        self::assertSame('TS', $rows[0]['trade_id']);
        self::assertSame('unmatched', $rows[0]['close_match_status']);
        self::assertNull($rows[0]['close_event_id']);
        self::assertNull($rows[0]['recorded_pnl_usdt'], 'aucun vieux PnL attribué au run courant');
    }

    public function testRealCloseAfterEntryMatchesDespiteAnOrphanStaleCloseBefore(): void
    {
        $run = 'run_orphan_then_real';
        // Clôture orpheline/périmée AVANT l'entrée (position_id réutilisé `PO`, aucune
        // entrée antérieure) — elle ne doit pas voler le rang à la vraie clôture.
        $this->close('BTCUSDT', $run, ['pnl' => 999.0], 'PO', '2026-06-17 08:00:00+00', 800);
        // Entrée réelle (trade TO) + pont vers `PO` + VRAIE clôture postérieure.
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TO'], '2026-06-17 09:00:00+00', 801);
        $this->opened('BTCUSDT', $run, 'TO', 'PO', '2026-06-17 09:00:30+00', 802);
        $this->close('BTCUSDT', $run, ['pnl' => 7.0, 'pnl_R' => 1.0], 'PO', '2026-06-17 10:00:00+00', 803);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT trade_id, close_match_status, close_matched_by, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run]
        );

        self::assertCount(1, $rows, 'une seule entrée (la clôture orpheline ne crée pas de ligne)');
        self::assertSame('TO', $rows[0]['trade_id']);
        // La vraie clôture postérieure est rapprochée (pas laissée unmatched par la périmée).
        self::assertSame('matched', $rows[0]['close_match_status']);
        self::assertSame('matched_position_id', $rows[0]['close_matched_by']);
        self::assertSame(803, (int) $rows[0]['close_event_id']);
        self::assertEqualsWithDelta(7.0, (float) $rows[0]['recorded_pnl_usdt'], 1e-9);
    }

    public function testReusedPositionIdAcrossTwoSymbolsMatchesEachOnItsOwnClose(): void
    {
        $run = 'run_xsym';
        // Même position_id `PX` réutilisé sur BTC et ETH. L'entrée BTC est la première,
        // mais la clôture ETH arrive AVANT la clôture BTC : sans partition par symbole,
        // les rangs se croiseraient et le garde de symbole rejetterait les deux clôtures.
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TB'], '2026-06-17 09:00:00+00', 900);
        $this->opened('BTCUSDT', $run, 'TB', 'PX', '2026-06-17 09:00:30+00', 901);
        $this->entry('ETHUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TE'], '2026-06-17 09:10:00+00', 902);
        $this->opened('ETHUSDT', $run, 'TE', 'PX', '2026-06-17 09:10:30+00', 903);
        $this->close('ETHUSDT', $run, ['pnl' => 3.0], 'PX', '2026-06-17 09:20:00+00', 904);
        $this->close('BTCUSDT', $run, ['pnl' => 5.0], 'PX', '2026-06-17 09:30:00+00', 905);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT symbol, trade_id, close_match_status, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY symbol',
            [$run]
        );

        self::assertCount(2, $rows);
        $bySymbol = [];
        foreach ($rows as $r) {
            $bySymbol[$r['symbol']] = $r;
        }

        // Chaque symbole est rapproché de SA propre clôture (pas de croisement de rangs).
        self::assertSame('matched', $bySymbol['BTCUSDT']['close_match_status']);
        self::assertSame(905, (int) $bySymbol['BTCUSDT']['close_event_id']);
        self::assertEqualsWithDelta(5.0, (float) $bySymbol['BTCUSDT']['recorded_pnl_usdt'], 1e-9);

        self::assertSame('matched', $bySymbol['ETHUSDT']['close_match_status']);
        self::assertSame(904, (int) $bySymbol['ETHUSDT']['close_event_id']);
        self::assertEqualsWithDelta(3.0, (float) $bySymbol['ETHUSDT']['recorded_pnl_usdt'], 1e-9);
    }

    public function testReusedPositionIdAcrossVenuesDoesNotCrossMatch(): void
    {
        $run = 'run_venue';
        // Même symbole BTC + même position_id `PV`, mais deux venues (bitmart / okx).
        // Une clôture OKX ne doit pas être appariée à l'entrée bitmart (PnL au mauvais
        // bucket by_exchange) et inversement.
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TBB'], '2026-06-17 09:00:00+00', 920);
        $this->opened('BTCUSDT', $run, 'TBB', 'PV', '2026-06-17 09:00:30+00', 921, 'bitmart', 'perpetual');
        $this->entry('BTCUSDT', $run, 's2', 'scalper', 'okx', 'perpetual', ['trade_id' => 'TBO'], '2026-06-17 09:05:00+00', 922);
        $this->opened('BTCUSDT', $run, 'TBO', 'PV', '2026-06-17 09:05:30+00', 923, 'okx', 'perpetual');
        $this->close('BTCUSDT', $run, ['pnl' => 5.0], 'PV', '2026-06-17 09:30:00+00', 924, 'bitmart', 'perpetual');
        $this->close('BTCUSDT', $run, ['pnl' => 8.0], 'PV', '2026-06-17 09:35:00+00', 925, 'okx', 'perpetual');

        $rows = $this->conn->fetchAllAssociative(
            'SELECT exchange, trade_id, close_match_status, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY exchange',
            [$run]
        );

        self::assertCount(2, $rows);
        $byExchange = [];
        foreach ($rows as $r) {
            $byExchange[$r['exchange']] = $r;
        }

        // Chaque venue est rapprochée de SA propre clôture, jamais en cross-venue.
        self::assertSame('matched', $byExchange['bitmart']['close_match_status']);
        self::assertSame(924, (int) $byExchange['bitmart']['close_event_id']);
        self::assertEqualsWithDelta(5.0, (float) $byExchange['bitmart']['recorded_pnl_usdt'], 1e-9);

        self::assertSame('matched', $byExchange['okx']['close_match_status']);
        self::assertSame(925, (int) $byExchange['okx']['close_event_id']);
        self::assertEqualsWithDelta(8.0, (float) $byExchange['okx']['recorded_pnl_usdt'], 1e-9);
    }

    public function testSameTradeIdAcrossVenuesMatchesEachOnItsOwnVenue(): void
    {
        $run = 'run_tid_venue';
        // Même trade_id `TX` émis par DEUX venues (bitmart / okx) — possible car le
        // trade_id n'est unique que par (exchange, market_type, trade_id). L'entrée
        // bitmart est la première, mais la clôture OKX arrive AVANT la clôture bitmart :
        // sans périmètre venue dans le passage trade_id, les rangs se croiseraient et
        // l'entrée bitmart hériterait du PnL OKX (cross-venue swap).
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TX'], '2026-06-17 09:00:00+00', 1200);
        $this->entry('BTCUSDT', $run, 's2', 'scalper', 'okx', 'perpetual', ['trade_id' => 'TX'], '2026-06-17 09:05:00+00', 1201);
        $this->close('BTCUSDT', $run, ['trade_id' => 'TX', 'pnl' => 8.0], null, '2026-06-17 09:20:00+00', 1202, 'okx', 'perpetual');
        $this->close('BTCUSDT', $run, ['trade_id' => 'TX', 'pnl' => 5.0], null, '2026-06-17 09:30:00+00', 1203, 'bitmart', 'perpetual');

        $rows = $this->conn->fetchAllAssociative(
            'SELECT exchange, trade_id, close_match_status, close_matched_by, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY exchange',
            [$run]
        );

        self::assertCount(2, $rows);
        $byExchange = [];
        foreach ($rows as $r) {
            $byExchange[$r['exchange']] = $r;
        }

        // Rapprochement PAR trade_id (pas position_id) mais borné à la venue : chaque
        // entrée hérite du PnL de SA propre clôture, jamais en cross-venue.
        self::assertSame('matched', $byExchange['bitmart']['close_match_status']);
        self::assertSame('matched_trade_id', $byExchange['bitmart']['close_matched_by']);
        self::assertSame(1203, (int) $byExchange['bitmart']['close_event_id']);
        self::assertEqualsWithDelta(5.0, (float) $byExchange['bitmart']['recorded_pnl_usdt'], 1e-9);

        self::assertSame('matched', $byExchange['okx']['close_match_status']);
        self::assertSame('matched_trade_id', $byExchange['okx']['close_matched_by']);
        self::assertSame(1202, (int) $byExchange['okx']['close_event_id']);
        self::assertEqualsWithDelta(8.0, (float) $byExchange['okx']['recorded_pnl_usdt'], 1e-9);
    }

    public function testOpenedBridgeIsScopedByVenue(): void
    {
        $run = 'run_bridge_venue';
        // Même trade_id `TG` émis par DEUX venues, chacune avec son `position_opened`
        // (le pont) résolvant un position_id DISTINCT, puis sa clôture par position_id.
        // Sans périmètre venue dans le pont, l'entrée okx pourrait hériter du position_id
        // bitmart (rang croisé) et matcher la mauvaise venue (mauvais bucket by_exchange).
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TG'], '2026-06-17 09:00:00+00', 1300);
        $this->opened('BTCUSDT', $run, 'TG', 'PGB', '2026-06-17 09:00:30+00', 1301, 'bitmart', 'perpetual');
        $this->entry('BTCUSDT', $run, 's2', 'scalper', 'okx', 'perpetual', ['trade_id' => 'TG'], '2026-06-17 09:05:00+00', 1302);
        $this->opened('BTCUSDT', $run, 'TG', 'PGO', '2026-06-17 09:05:30+00', 1303, 'okx', 'perpetual');
        $this->close('BTCUSDT', $run, ['pnl' => 8.0], 'PGO', '2026-06-17 09:20:00+00', 1304, 'okx', 'perpetual');
        $this->close('BTCUSDT', $run, ['pnl' => 5.0], 'PGB', '2026-06-17 09:30:00+00', 1305, 'bitmart', 'perpetual');

        $rows = $this->conn->fetchAllAssociative(
            'SELECT exchange, position_id, close_match_status, close_matched_by, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY exchange',
            [$run]
        );

        self::assertCount(2, $rows);
        $byExchange = [];
        foreach ($rows as $r) {
            $byExchange[$r['exchange']] = $r;
        }

        // Chaque entrée résout le position_id de SA venue via le pont, puis matche SA clôture.
        self::assertSame('PGB', $byExchange['bitmart']['position_id']);
        self::assertSame('matched', $byExchange['bitmart']['close_match_status']);
        self::assertSame('matched_position_id', $byExchange['bitmart']['close_matched_by']);
        self::assertSame(1305, (int) $byExchange['bitmart']['close_event_id']);
        self::assertEqualsWithDelta(5.0, (float) $byExchange['bitmart']['recorded_pnl_usdt'], 1e-9);

        self::assertSame('PGO', $byExchange['okx']['position_id']);
        self::assertSame('matched', $byExchange['okx']['close_match_status']);
        self::assertSame('matched_position_id', $byExchange['okx']['close_matched_by']);
        self::assertSame(1304, (int) $byExchange['okx']['close_event_id']);
        self::assertEqualsWithDelta(8.0, (float) $byExchange['okx']['recorded_pnl_usdt'], 1e-9);
    }

    public function testCloseMatchingIsScopedByRun(): void
    {
        // (a) Anti cross-run : entrée runA + clôture TAGUÉE runB (même trade_id + venue).
        // La clôture d'un AUTRE run ne doit pas être consommée par l'entrée runA (sinon son
        // PnL serait attribué à runA, que l'API filtre par run APRÈS l'appariement).
        $this->entry('BTCUSDT', 'runA', 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TR'], '2026-06-17 09:00:00+00', 1400);
        $this->close('BTCUSDT', 'runB', ['trade_id' => 'TR', 'pnl' => 8.0], null, '2026-06-17 09:30:00+00', 1401, 'bitmart', 'perpetual');

        $rowsA = $this->conn->fetchAllAssociative(
            'SELECT trade_id, close_match_status, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            ['runA']
        );
        self::assertCount(1, $rowsA);
        self::assertSame('unmatched', $rowsA[0]['close_match_status']);
        self::assertNull($rowsA[0]['close_event_id']);
        self::assertNull($rowsA[0]['recorded_pnl_usdt'], 'aucune clôture d\'un autre run attribuée');

        // (b) Chemin LIVE : la synchro émet les clôtures SANS run_id (run_id NULL) — elles
        // restent rapprochables par le run de l'entrée (le garde est permissif sur NULL).
        $this->entry('ETHUSDT', 'runC', 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TN'], '2026-06-17 09:00:00+00', 1410);
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, exchange, market_type, extra, happened_at)
             VALUES (?, ?, \'position_closed\', NULL, NULL, ?, ?, ?::jsonb, ?)',
            [1411, 'ETHUSDT', 'bitmart', 'perpetual', json_encode(['trade_id' => 'TN', 'pnl' => 3.0], JSON_THROW_ON_ERROR), '2026-06-17 09:30:00+00']
        );

        $rowsC = $this->conn->fetchAllAssociative(
            'SELECT trade_id, close_match_status, close_matched_by, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            ['runC']
        );
        self::assertCount(1, $rowsC);
        self::assertSame('matched', $rowsC[0]['close_match_status']);
        self::assertSame('matched_trade_id', $rowsC[0]['close_matched_by']);
        self::assertEqualsWithDelta(3.0, (float) $rowsC[0]['recorded_pnl_usdt'], 1e-9);
    }

    public function testInternalTradeIdHasPriorityOverLegacyPositionId(): void
    {
        $run = 'run_internal_priority';

        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', [
            'internal_trade_id' => 'itd-first',
            'position_id' => 'P-REUSED',
        ], '2026-06-17 09:00:00+00', 1500);
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', [
            'internal_trade_id' => 'itd-second',
            'position_id' => 'P-REUSED',
        ], '2026-06-17 09:01:00+00', 1501);
        $this->close('BTCUSDT', $run, ['internal_trade_id' => 'itd-second', 'pnl' => 2.0], 'P-REUSED', '2026-06-17 09:10:00+00', 1502);
        $this->close('BTCUSDT', $run, ['internal_trade_id' => 'itd-first', 'pnl' => 1.0], 'P-REUSED', '2026-06-17 09:11:00+00', 1503);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT internal_trade_id, close_event_id, close_matched_by, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY entry_time',
            [$run]
        );

        self::assertCount(2, $rows);
        self::assertSame('itd-first', $rows[0]['internal_trade_id']);
        self::assertSame('matched_internal_trade_id', $rows[0]['close_matched_by']);
        self::assertSame(1503, (int) $rows[0]['close_event_id']);
        self::assertEqualsWithDelta(1.0, (float) $rows[0]['recorded_pnl_usdt'], 1e-9);

        self::assertSame('itd-second', $rows[1]['internal_trade_id']);
        self::assertSame('matched_internal_trade_id', $rows[1]['close_matched_by']);
        self::assertSame(1502, (int) $rows[1]['close_event_id']);
        self::assertEqualsWithDelta(2.0, (float) $rows[1]['recorded_pnl_usdt'], 1e-9);
    }

    public function testStaleCloseDetectedViaRealCloseTimeNotLogTime(): void
    {
        $run = 'run_realtime';
        // Entrée réelle à 09:00 (trade TCT, pont -> PCT).
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TCT'], '2026-06-17 09:00:00+00', 1000);
        $this->opened('BTCUSDT', $run, 'TCT', 'PCT', '2026-06-17 09:00:30+00', 1001);
        // Clôture périmée d'un ancien trade au MÊME position_id : LOGGÉE tardivement
        // (happened_at 10:00, après l'entrée) mais close_time RÉEL à 08:00 (avant l'entrée).
        // Le temps réel doit primer => non éligible => entrée unmatched, aucun PnL attribué.
        $this->close('BTCUSDT', $run, ['pnl' => 999.0, 'close_time' => '2026-06-17 08:00:00'], 'PCT', '2026-06-17 10:00:00+00', 1002);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT trade_id, close_match_status, close_event_id, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run]
        );

        self::assertCount(1, $rows);
        self::assertSame('TCT', $rows[0]['trade_id']);
        self::assertSame('unmatched', $rows[0]['close_match_status']);
        self::assertNull($rows[0]['close_event_id']);
        self::assertNull($rows[0]['recorded_pnl_usdt'], 'aucun PnL périmé attribué via le log time');
    }

    public function testMatchedCloseExposesRealCloseTime(): void
    {
        $run = 'run_realtime_ok';
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['trade_id' => 'TMC'], '2026-06-17 09:00:00+00', 1100);
        $this->opened('BTCUSDT', $run, 'TMC', 'PMC', '2026-06-17 09:00:30+00', 1101);
        // Clôture réelle à 10:00 mais loggée à 10:05 : effective_close_time = close_time réel.
        $this->close('BTCUSDT', $run, ['pnl' => 5.0, 'close_time' => '2026-06-17 10:00:00'], 'PMC', '2026-06-17 10:05:00+00', 1102);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT close_match_status, close_event_id, close_time, recorded_pnl_usdt
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run]
        );

        self::assertCount(1, $rows);
        self::assertSame('matched', $rows[0]['close_match_status']);
        self::assertSame(1102, (int) $rows[0]['close_event_id']);
        self::assertEqualsWithDelta(5.0, (float) $rows[0]['recorded_pnl_usdt'], 1e-9);
        // close_time exposé = heure RÉELLE de clôture (10:00), pas le log time (10:05).
        self::assertStringContainsString('10:00:00', (string) $rows[0]['close_time']);
    }

    public function testFifoHandlesThousandsOfEventsWithoutReusingOrMultiplyingCloses(): void
    {
        $run = 'run_fifo_bulk';
        $base = new \DateTimeImmutable('2026-06-17 00:00:00', new \DateTimeZone('UTC'));
        $entryCount = 1200;
        $nextId = 10000;

        for ($i = 0; $i < $entryCount; $i++) {
            $entryTime = $base->modify(sprintf('+%d minutes', $i * 3))->format('Y-m-d H:i:sP');
            $closeTime = $base->modify(sprintf('+%d minutes', ($i * 3) + 1))->format('Y-m-d H:i:sP');
            $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['position_id' => 'PBULK'], $entryTime, $nextId++);
            $this->close('BTCUSDT', $run, ['pnl' => 1.0], 'PBULK', $closeTime, $nextId++);

            if ($i % 100 === 0) {
                $orphanCloseTime = $base->modify(sprintf('+%d minutes', ($i * 3) + 2))->format('Y-m-d H:i:sP');
                $this->close('BTCUSDT', $run, ['pnl' => 999.0], 'PBULK', $orphanCloseTime, $nextId++);
            }
        }

        $summary = $this->conn->fetchAssociative(
            'SELECT COUNT(*) AS rows_count,
                    COUNT(close_event_id) AS matched_count,
                    COUNT(DISTINCT close_event_id) AS distinct_close_count,
                    SUM(recorded_pnl_usdt) AS recorded_pnl
             FROM position_trade_analysis_v2 WHERE run_id = ?',
            [$run]
        );
        self::assertIsArray($summary);
        self::assertSame($entryCount, (int) $summary['rows_count']);
        self::assertSame($entryCount, (int) $summary['matched_count']);
        self::assertSame($entryCount, (int) $summary['distinct_close_count']);
        self::assertEqualsWithDelta((float) $entryCount, (float) $summary['recorded_pnl'], 1e-9);

        $plan = $this->conn->fetchFirstColumn(
            'EXPLAIN (ANALYZE, BUFFERS)
             SELECT * FROM position_trade_analysis_v2 WHERE run_id = ? ORDER BY entry_time',
            [$run]
        );
        self::assertNotSame([], $plan, 'EXPLAIN doit produire un plan PostgreSQL exploitable.');
    }

    private function createMinimalSchema(): void
    {
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_ledger_aggregate_v1');
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2_legacy_source');
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis');
        $this->conn->executeStatement('DROP TABLE IF EXISTS fill_cost_ledger');
        $this->conn->executeStatement('DROP TABLE IF EXISTS trade_lifecycle_event');
        $this->conn->executeStatement('DROP TABLE IF EXISTS indicator_snapshots');

        $this->conn->executeStatement(<<<'SQL'
CREATE TABLE trade_lifecycle_event (
    id BIGSERIAL PRIMARY KEY,
    symbol VARCHAR(50) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    run_id VARCHAR(64),
    order_id VARCHAR(64),
    internal_trade_id VARCHAR(96),
    position_id VARCHAR(64),
    trade_id VARCHAR(96),
    correlation_run_id VARCHAR(96),
    orchestration_run_id VARCHAR(255),
    orchestration_set_id VARCHAR(96),
    orchestration_dashboard_id VARCHAR(96),
    mode_id VARCHAR(64),
    mode_version VARCHAR(32),
    setup_id VARCHAR(160),
    setup_version VARCHAR(32),
    config_hash VARCHAR(128),
    condition_catalog_hash VARCHAR(128),
    side VARCHAR(16),
    decision_id VARCHAR(96),
    decision_key VARCHAR(160),
    intent_id VARCHAR(96),
    paper_network VARCHAR(16),
    paper_execution_cell_id VARCHAR(71),
    configuration_snapshot_id VARCHAR(71),
    paper_eligibility VARCHAR(32),
    timeframe VARCHAR(8),
    config_profile VARCHAR(64),
    exchange VARCHAR(32) DEFAULT 'bitmart',
    market_data_venue VARCHAR(32),
    market_type VARCHAR(32) DEFAULT 'perpetual',
    extra JSONB,
    happened_at TIMESTAMPTZ NOT NULL
)
SQL);

        $this->conn->executeStatement(<<<'SQL'
CREATE TABLE indicator_snapshots (
    id BIGSERIAL PRIMARY KEY,
    symbol VARCHAR(50) NOT NULL,
    timeframe VARCHAR(8) NOT NULL,
    exchange VARCHAR(32),
    market_data_venue VARCHAR(32),
    market_type VARCHAR(32),
    kline_time TIMESTAMPTZ NOT NULL,
    values JSONB
)
SQL);

        // DATA-002 + provenance Paper: la fixture reproduit le contrat durable que #190
        // doit lire, sans se replier sur les valeurs JSON de `position_closed`.
        $this->conn->executeStatement(<<<'SQL'
CREATE TABLE fill_cost_ledger (
    id BIGSERIAL PRIMARY KEY,
    idempotency_key VARCHAR(255) NOT NULL,
    payload_hash VARCHAR(64) NOT NULL,
    internal_trade_id VARCHAR(96),
    internal_position_id VARCHAR(96),
    position_id VARCHAR(96),
    exchange VARCHAR(32) NOT NULL,
    market_data_venue VARCHAR(32),
    market_type VARCHAR(32) NOT NULL,
    symbol VARCHAR(50) NOT NULL,
    side VARCHAR(16),
    fill_id VARCHAR(128) NOT NULL,
    exchange_fill_id VARCHAR(128),
    exchange_order_id VARCHAR(96),
    client_order_id VARCHAR(96),
    order_intent_id BIGINT,
    fill_role VARCHAR(24) NOT NULL,
    liquidity_role VARCHAR(24) NOT NULL,
    price NUMERIC(30, 12),
    quantity NUMERIC(30, 12),
    notional NUMERIC(30, 12),
    fee_amount NUMERIC(30, 12),
    fee_currency VARCHAR(20),
    fee_usdt NUMERIC(30, 12),
    funding_usdt NUMERIC(30, 12),
    spread_cost_usdt NUMERIC(30, 12),
    slippage_cost_usdt NUMERIC(30, 12),
    borrow_cost_usdt NUMERIC(30, 12),
    liquidation_fee_usdt NUMERIC(30, 12),
    paper_network VARCHAR(16),
    paper_execution_cell_id VARCHAR(71),
    configuration_snapshot_id VARCHAR(71),
    paper_eligibility VARCHAR(32),
    occurred_at TIMESTAMPTZ NOT NULL,
    source VARCHAR(64) NOT NULL,
    source_version VARCHAR(64) NOT NULL,
    quality_flags JSONB NOT NULL,
    raw_reference JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
)
SQL);
    }

    private function applyViewMigration(): void
    {
        // Les migrations ne sont pas dans l'autoload Composer (chargées par Doctrine).
        if (!class_exists(Version20260622000000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260622000000.php';
        }
        if (!class_exists(Version20260623010000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260623010000.php';
        }
        if (!class_exists(Version20260625000000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260625000000.php';
        }
        if (!class_exists(Version20260625020000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260625020000.php';
        }
        if (!class_exists(Version20260626000000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260626000000.php';
        }
        if (!class_exists(Version20260719130000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260719130000.php';
        }
        if (!class_exists(Version20260808114000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260808114000.php';
        }
        if (!class_exists(Version20260811120000::class, false)) {
            require_once \dirname(__DIR__, 3) . '/migrations/Version20260811120000.php';
        }

        foreach ([Version20260622000000::class, Version20260623010000::class, Version20260625000000::class, Version20260625020000::class, Version20260626000000::class, Version20260719130000::class, Version20260808114000::class, Version20260811120000::class] as $migrationClass) {
            $migration = new $migrationClass($this->conn, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->conn->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function entry(
        string $symbol,
        string $runId,
        string $setId,
        string $profile,
        string $exchange,
        string $marketType,
        array $extra,
        string $happenedAt,
        int $forcedId,
        ?string $marketDataVenue = null,
    ): void {
        $extra += [
            'orchestration_run_id' => $runId,
            'orchestration_dashboard_id' => 'dashA',
            'orchestration_set_id' => $setId,
        ];

        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, config_profile, exchange, market_data_venue, market_type, extra, happened_at)
             VALUES (?, ?, \'order_submitted\', ?, ?, ?, ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $profile, $exchange, $marketDataVenue, $marketType, json_encode($extra, JSON_THROW_ON_ERROR), $happenedAt]
        );
    }

    /**
     * Événement `position_opened` du flux MTF : porte À LA FOIS le `trade_id` (contexte
     * lifecycle) et le `position_id` (métadonnée d'ordre) — c'est le pont OBS-003 v2.
     */
    private function opened(
        string $symbol,
        string $runId,
        string $tradeId,
        string $positionId,
        string $happenedAt,
        int $forcedId,
        string $exchange = 'bitmart',
        string $marketType = 'perpetual',
        ?string $marketDataVenue = null,
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, exchange, market_data_venue, market_type, extra, happened_at)
             VALUES (?, ?, \'position_opened\', ?, ?, ?, ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $positionId, $exchange, $marketDataVenue, $marketType, json_encode(['trade_id' => $tradeId], JSON_THROW_ON_ERROR), $happenedAt]
        );
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function close(
        string $symbol,
        string $runId,
        array $extra,
        ?string $positionId,
        string $happenedAt,
        int $forcedId,
        string $exchange = 'bitmart',
        string $marketType = 'perpetual',
        ?string $marketDataVenue = null,
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, exchange, market_data_venue, market_type, extra, happened_at)
             VALUES (?, ?, \'position_closed\', ?, ?, ?, ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $positionId, $exchange, $marketDataVenue, $marketType, json_encode($extra, JSON_THROW_ON_ERROR), $happenedAt]
        );
    }

    private function canonicalLifecycle(
        int $entryEventId,
        int $closeEventId,
        string $internalTradeId,
        string $positionId,
        string $tradeId,
    ): void {
        $identity = [
            'internal_trade_id' => $internalTradeId,
            'position_id' => $positionId,
            'trade_id' => $tradeId,
            'correlation_run_id' => 'correlation-' . $tradeId,
            'orchestration_run_id' => 'orchestration-' . $tradeId,
            'orchestration_set_id' => 'set-' . $tradeId,
            'orchestration_dashboard_id' => 'dashboard-' . $tradeId,
            'mode_id' => 'scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'scalping.pullback',
            'setup_version' => '1.0.0',
            'config_hash' => hash('sha256', 'config-' . $tradeId),
            'condition_catalog_hash' => hash('sha256', 'catalog-' . $tradeId),
            'side' => 'LONG',
            'decision_id' => 'decision-' . $tradeId,
            'decision_key' => 'decision-key-' . $tradeId,
            'intent_id' => 'intent-' . $tradeId,
            'order_id' => 'order-' . $tradeId,
            'paper_network' => 'testnet',
            'paper_execution_cell_id' => 'sha256:' . str_repeat('a', 64),
            'configuration_snapshot_id' => 'sha256:' . str_repeat('b', 64),
            'paper_eligibility' => 'reference_only',
        ];
        $sets = implode(', ', array_map(static fn (string $field): string => "$field = :$field", array_keys($identity)));

        foreach ([$entryEventId, $closeEventId] as $eventId) {
            $this->conn->executeStatement(
                "UPDATE trade_lifecycle_event SET $sets WHERE id = :id",
                $identity + ['id' => $eventId],
            );
        }
    }

    /**
     * @return array<string,float|bool|string>
     */
    private function completeCloseContract(float $grossRealizedPnlUsdt): array
    {
        return [
            'gross_realized_pnl_usdt' => $grossRealizedPnlUsdt,
            'recorded_pnl_usdt' => $grossRealizedPnlUsdt,
            'entry_fee_usdt' => 0.01,
            'exit_fee_usdt' => 0.01,
            'other_trading_fees_usdt' => 0.0,
            'funding_usdt' => 0.0,
            'spread_cost_usdt' => 0.0,
            'slippage_cost_usdt' => 0.0,
            'borrow_cost_usdt' => 0.0,
            'liquidation_fee_usdt' => 0.0,
            'entry_qty' => 1.0,
            'exit_qty' => 1.0,
            'remaining_qty' => 0.0,
            'position_fully_closed' => true,
            'fills_complete' => true,
            'quantity_coherent' => true,
            'lineage_sufficient' => true,
            'identifier_conflict' => false,
            'pnl_source' => 'fill_cost_ledger_v1',
        ];
    }

    private function exactLedgerAggregateMatchCount(int $entryEventId): int
    {
        return (int) $this->conn->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM fill_cost_ledger ledger
JOIN trade_lifecycle_event entry_event ON entry_event.id = :entry_event_id
WHERE ledger.internal_trade_id IS NOT DISTINCT FROM entry_event.internal_trade_id
  AND ledger.position_id IS NOT DISTINCT FROM entry_event.position_id
  AND ledger.exchange IS NOT DISTINCT FROM entry_event.exchange
  AND ledger.market_type IS NOT DISTINCT FROM entry_event.market_type
  AND ledger.symbol IS NOT DISTINCT FROM entry_event.symbol
  AND ledger.market_data_venue IS NOT DISTINCT FROM entry_event.market_data_venue
  AND ledger.paper_network IS NOT DISTINCT FROM entry_event.paper_network
  AND ledger.paper_execution_cell_id IS NOT DISTINCT FROM entry_event.paper_execution_cell_id
  AND ledger.configuration_snapshot_id IS NOT DISTINCT FROM entry_event.configuration_snapshot_id
  AND ledger.paper_eligibility IS NOT DISTINCT FROM entry_event.paper_eligibility
SQL, ['entry_event_id' => $entryEventId]);
    }

    private function ledgerRowsMismatchingCanonicalField(int $entryEventId, string $field): int
    {
        if (!in_array($field, ['market_data_venue', 'paper_execution_cell_id'], true)) {
            throw new \InvalidArgumentException('unsupported_ledger_provenance_field');
        }

        return (int) $this->conn->fetchOne(<<<SQL
SELECT COUNT(*)
FROM fill_cost_ledger ledger
JOIN trade_lifecycle_event entry_event ON entry_event.id = :entry_event_id
WHERE ledger.internal_trade_id IS NOT DISTINCT FROM entry_event.internal_trade_id
  AND ledger.position_id IS NOT DISTINCT FROM entry_event.position_id
  AND ledger.exchange IS NOT DISTINCT FROM entry_event.exchange
  AND ledger.market_type IS NOT DISTINCT FROM entry_event.market_type
  AND ledger.symbol IS NOT DISTINCT FROM entry_event.symbol
  AND ledger.$field IS DISTINCT FROM entry_event.$field
SQL, ['entry_event_id' => $entryEventId]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function ledgerFill(
        string $symbol,
        string $internalTradeId,
        string $positionId,
        string $fillId,
        string $fillRole,
        ?float $price,
        ?float $quantity,
        string $occurredAt,
        array $overrides = [],
    ): void {
        $feeUsdt = $overrides['fee_usdt'] ?? null;
        $values = [
            'idempotency_key' => 'test:' . $internalTradeId . ':' . $fillId,
            'payload_hash' => hash('sha256', $internalTradeId . ':' . $fillId),
            'internal_trade_id' => $internalTradeId,
            'internal_position_id' => 'internal-' . $positionId,
            'position_id' => $positionId,
            'exchange' => 'fake',
            'market_data_venue' => 'hyperliquid',
            'market_type' => 'paper',
            'symbol' => $symbol,
            'side' => $fillRole === 'entry' ? 'BUY' : ($fillRole === 'exit' ? 'SELL' : null),
            'fill_id' => $fillId,
            'exchange_fill_id' => 'exchange-' . $fillId,
            'exchange_order_id' => 'order-' . $internalTradeId,
            'client_order_id' => 'client-' . $internalTradeId,
            'order_intent_id' => null,
            'fill_role' => $fillRole,
            'liquidity_role' => 'taker',
            'price' => $price,
            'quantity' => $quantity,
            'notional' => $price !== null && $quantity !== null ? $price * $quantity : null,
            'fee_amount' => $feeUsdt,
            'fee_currency' => $feeUsdt !== null ? 'USDT' : null,
            'fee_usdt' => $feeUsdt,
            'funding_usdt' => null,
            'spread_cost_usdt' => null,
            'slippage_cost_usdt' => null,
            'borrow_cost_usdt' => null,
            'liquidation_fee_usdt' => null,
            'paper_network' => 'testnet',
            'paper_execution_cell_id' => 'sha256:' . str_repeat('a', 64),
            'configuration_snapshot_id' => 'sha256:' . str_repeat('b', 64),
            'paper_eligibility' => 'reference_only',
            'occurred_at' => $occurredAt,
            'source' => 'fake_paper',
            'source_version' => 'fill_cost_ledger_v1',
            'quality_flags' => json_encode([], JSON_THROW_ON_ERROR),
            'raw_reference' => json_encode(['fixture' => $fillId], JSON_THROW_ON_ERROR),
            'created_at' => $occurredAt,
        ];
        foreach ($overrides as $field => $value) {
            $values[$field] = in_array($field, ['quality_flags', 'raw_reference'], true)
                ? json_encode($value, JSON_THROW_ON_ERROR)
                : $value;
        }

        $columns = array_keys($values);
        $placeholders = array_map(
            static fn (string $field): string => in_array($field, ['quality_flags', 'raw_reference'], true) ? ":$field::jsonb" : ":$field",
            $columns,
        );
        $this->conn->executeStatement(
            sprintf('INSERT INTO fill_cost_ledger (%s) VALUES (%s)', implode(', ', $columns), implode(', ', $placeholders)),
            $values,
        );
    }
}
