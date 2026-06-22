<?php

declare(strict_types=1);

namespace App\Tests\Trading\View;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260622000000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OBS-003 — Test d'INTÉGRATION de la vue `position_trade_analysis` sur un vrai PostgreSQL.
 *
 * Exécute le SQL RÉEL de la migration {@see Version20260622000000} (via `getSql()`),
 * sur des tables minimales `trade_lifecycle_event` + `indicator_snapshots`, puis vérifie
 * le rapprochement par identifiants EXACTS (trade_id puis position_id, jamais par symbole),
 * l'absence de duplication / de réutilisation de clôture, le lineage et la sémantique PnL.
 *
 * Skippé proprement si aucun PostgreSQL n'est disponible (DATABASE_URL non-postgres).
 */
#[CoversClass(Version20260622000000::class)]
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
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }

        $this->createMinimalSchema();
        $this->applyViewMigration();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis');
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
            'SELECT * FROM position_trade_analysis WHERE run_id = ? ORDER BY entry_time',
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
        self::assertEqualsWithDelta(12.0, (float) $t1['recorded_pnl_usdt'], 1e-9);
        self::assertTrue($this->asBool($t1['net_pnl_complete']));
        self::assertEqualsWithDelta(10.7, (float) $t1['net_pnl_usdt'], 1e-9); // 12 - 1 - 0.2 - 0.1

        // T2 : rapproché par position_id, net INCOMPLET (pas de fees/funding/slippage).
        $t2 = $byTrade['T2'];
        self::assertSame('matched', $t2['close_match_status']);
        self::assertSame('matched_position_id', $t2['close_matched_by']);
        self::assertSame('P2', $t2['position_id']);
        self::assertEqualsWithDelta(-4.0, (float) $t2['recorded_pnl_usdt'], 1e-9);
        self::assertFalse($this->asBool($t2['net_pnl_complete']));
        self::assertNull($t2['net_pnl_usdt']);

        // ETH : aucune clôture -> unmatched / ouvert, pas de PnL.
        $eth = $byTrade['T3'];
        self::assertSame('unmatched', $eth['close_match_status']);
        self::assertSame('unmatched', $eth['close_matched_by']);
        self::assertNull($eth['close_event_id']);
        self::assertNull($eth['recorded_pnl_usdt']);
    }

    public function testCloseIsNotReusedAcrossEntriesSharingAPositionId(): void
    {
        $run = 'run_reuse';
        // 2 entrées + 2 clôtures partageant le même position_id : appariement 1-pour-1
        // par ROW_NUMBER, jamais de réutilisation ni de multiplication de lignes.
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['position_id' => 'PSHARED'], '2026-06-17 09:00:00+00', 300);
        $this->entry('BTCUSDT', $run, 's1', 'scalper', 'bitmart', 'perpetual', ['position_id' => 'PSHARED'], '2026-06-17 09:01:00+00', 301);
        $this->close('BTCUSDT', $run, ['pnl' => 1.0], 'PSHARED', '2026-06-17 09:10:00+00', 400);
        $this->close('BTCUSDT', $run, ['pnl' => 2.0], 'PSHARED', '2026-06-17 09:11:00+00', 401);

        $rows = $this->conn->fetchAllAssociative(
            'SELECT entry_event_id, close_event_id FROM position_trade_analysis WHERE run_id = ? ORDER BY entry_time',
            [$run]
        );

        self::assertCount(2, $rows, 'exactement 2 lignes (pas de produit cartésien)');
        $closeIds = array_column($rows, 'close_event_id');
        self::assertNotContains(null, $closeIds, 'les deux entrées sont rapprochées');
        self::assertSame($closeIds, array_unique($closeIds), 'chaque clôture sert une seule entrée');
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
             FROM position_trade_analysis WHERE run_id = ?',
            [$run]
        );

        self::assertCount(1, $rows, 'une seule entrée (la clôture orpheline ne crée pas de ligne)');
        self::assertSame('TS', $rows[0]['trade_id']);
        self::assertSame('unmatched', $rows[0]['close_match_status']);
        self::assertNull($rows[0]['close_event_id']);
        self::assertNull($rows[0]['recorded_pnl_usdt'], 'aucun vieux PnL attribué au run courant');
    }

    private function createMinimalSchema(): void
    {
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis');
        $this->conn->executeStatement('DROP TABLE IF EXISTS trade_lifecycle_event');
        $this->conn->executeStatement('DROP TABLE IF EXISTS indicator_snapshots');

        $this->conn->executeStatement(<<<'SQL'
CREATE TABLE trade_lifecycle_event (
    id BIGSERIAL PRIMARY KEY,
    symbol VARCHAR(50) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    run_id VARCHAR(64),
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

        $migration = new Version20260622000000($this->conn, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->conn->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
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
    private function opened(string $symbol, string $runId, string $tradeId, string $positionId, string $happenedAt, int $forcedId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, extra, happened_at)
             VALUES (?, ?, \'position_opened\', ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $positionId, json_encode(['trade_id' => $tradeId], JSON_THROW_ON_ERROR), $happenedAt]
        );
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function close(string $symbol, string $runId, array $extra, ?string $positionId, string $happenedAt, int $forcedId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, extra, happened_at)
             VALUES (?, ?, \'position_closed\', ?, ?, ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $positionId, json_encode($extra, JSON_THROW_ON_ERROR), $happenedAt]
        );
    }

    private function asBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }
}
