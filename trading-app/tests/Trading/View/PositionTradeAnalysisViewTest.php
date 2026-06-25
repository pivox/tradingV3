<?php

declare(strict_types=1);

namespace App\Tests\Trading\View;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260622000000;
use DoctrineMigrations\Version20260623010000;
use DoctrineMigrations\Version20260625000000;
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
final class PositionTradeAnalysisViewTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if (!is_string($dsn) || !preg_match('/^(postgres|postgresql|pdo-pgsql)/', $dsn)) {
            self::markTestSkipped('PostgreSQL DATABASE_URL required for the view integration test.');
        }

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
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2');
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

        self::assertCount(3, $rows);
        $bySymbol = [];
        foreach ($rows as $row) {
            $bySymbol[$row['symbol']] = $row;
        }

        self::assertSame('complete', $bySymbol['BTCUSDT']['cost_completeness']);
        self::assertSame('fake_paper_fill_ledger_v1', $bySymbol['BTCUSDT']['pnl_source']);
        self::assertEqualsWithDelta(9.6, (float) $bySymbol['BTCUSDT']['gross_realized_pnl_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $bySymbol['BTCUSDT']['entry_fee_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.05, (float) $bySymbol['BTCUSDT']['exit_fee_usdt'], 1e-9);
        self::assertEqualsWithDelta(0.16, (float) $bySymbol['BTCUSDT']['total_known_cost_usdt'], 1e-9);
        self::assertEqualsWithDelta(9.44, (float) $bySymbol['BTCUSDT']['net_pnl_usdt'], 1e-9);
        self::assertEqualsWithDelta(1.888, (float) $bySymbol['BTCUSDT']['realized_net_pnl_r'], 1e-9);
        self::assertTrue(filter_var($bySymbol['BTCUSDT']['position_fully_closed'], FILTER_VALIDATE_BOOLEAN));
        self::assertSame('[]', (string) $bySymbol['BTCUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['ETHUSDT']['cost_completeness']);
        self::assertNull($bySymbol['ETHUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('missing_entry_fee', (string) $bySymbol['ETHUSDT']['pnl_quality_flags']);

        self::assertSame('partial', $bySymbol['SOLUSDT']['cost_completeness']);
        self::assertNull($bySymbol['SOLUSDT']['funding_usdt']);
        self::assertNull($bySymbol['SOLUSDT']['slippage_cost_usdt']);
        self::assertNull($bySymbol['SOLUSDT']['net_pnl_usdt']);
        self::assertStringContainsString('missing_funding', (string) $bySymbol['SOLUSDT']['pnl_quality_flags']);
        self::assertStringContainsString('missing_slippage_cost', (string) $bySymbol['SOLUSDT']['pnl_quality_flags']);
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
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->conn->executeStatement('DROP TABLE IF EXISTS trade_lifecycle_event');
        $this->conn->executeStatement('DROP TABLE IF EXISTS indicator_snapshots');

        $this->conn->executeStatement(<<<'SQL'
CREATE TABLE trade_lifecycle_event (
    id BIGSERIAL PRIMARY KEY,
    symbol VARCHAR(50) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    run_id VARCHAR(64),
    internal_trade_id VARCHAR(96),
    position_id VARCHAR(64),
    timeframe VARCHAR(8),
    config_profile VARCHAR(64),
    exchange VARCHAR(32) DEFAULT 'bitmart',
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
    kline_time TIMESTAMPTZ NOT NULL,
    values JSONB
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

        foreach ([Version20260622000000::class, Version20260623010000::class, Version20260625000000::class] as $migrationClass) {
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
    ): void {
        $extra += [
            'orchestration_run_id' => $runId,
            'orchestration_dashboard_id' => 'dashA',
            'orchestration_set_id' => $setId,
        ];

        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, config_profile, exchange, market_type, extra, happened_at)
             VALUES (?, ?, \'order_submitted\', ?, ?, ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $profile, $exchange, $marketType, json_encode($extra, JSON_THROW_ON_ERROR), $happenedAt]
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
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, exchange, market_type, extra, happened_at)
             VALUES (?, ?, \'position_opened\', ?, ?, ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $positionId, $exchange, $marketType, json_encode(['trade_id' => $tradeId], JSON_THROW_ON_ERROR), $happenedAt]
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
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, exchange, market_type, extra, happened_at)
             VALUES (?, ?, \'position_closed\', ?, ?, ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $positionId, $exchange, $marketType, json_encode($extra, JSON_THROW_ON_ERROR), $happenedAt]
        );
    }
}
