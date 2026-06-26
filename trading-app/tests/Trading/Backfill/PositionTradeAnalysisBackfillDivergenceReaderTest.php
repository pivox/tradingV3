<?php

declare(strict_types=1);

namespace App\Tests\Trading\Backfill;

use App\Trading\Backfill\BackfillDivergenceCriteria;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceReader;
use App\Trading\Backfill\PositionTradeAnalysisBackfillDivergenceReportService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20251129000000;
use DoctrineMigrations\Version20260622000000;
use DoctrineMigrations\Version20260623010000;
use DoctrineMigrations\Version20260625000000;
use DoctrineMigrations\Version20260625020000;
use DoctrineMigrations\Version20260626000000;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(PositionTradeAnalysisBackfillDivergenceReader::class)]
#[CoversClass(PositionTradeAnalysisBackfillDivergenceReportService::class)]
final class PositionTradeAnalysisBackfillDivergenceReaderTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $dsn = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL') ?: '';
        if (!is_string($dsn) || !preg_match('/^(postgres|postgresql|pdo-pgsql)/', $dsn)) {
            self::markTestSkipped('PostgreSQL DATABASE_URL required for the backfill divergence reader integration test.');
        }

        try {
            $conn = DriverManager::getConnection(['url' => $dsn]);
            $conn->executeQuery('SELECT 1');
            $conn->executeStatement("SET TIME ZONE 'UTC'");
            $this->conn = $conn;
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }

        $this->createMinimalSchema();
        $this->applyViewMigrations();
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2');
            $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis');
            $this->conn->executeStatement('DROP TABLE IF EXISTS trade_lifecycle_event');
            $this->conn->executeStatement('DROP TABLE IF EXISTS indicator_snapshots');
            $this->conn->close();
        }
    }

    public function testReaderSurfacesV1SymbolTimeMatchAsV2UnmatchedInsteadOfBackfillMatch(): void
    {
        $run = 'run_backfill_divergence';

        $this->entry('BTCUSDT', $run, ['trade_id' => 'T-good'], '2026-06-20 10:00:00+00', 300);
        $this->close('BTCUSDT', $run, ['trade_id' => 'T-good', 'pnl' => 5.0], null, '2026-06-20 10:05:00+00', 400);

        $this->entry('BTCUSDT', $run, ['trade_id' => 'T-legacy-only'], '2026-06-20 10:10:00+00', 301);
        $this->close('BTCUSDT', $run, ['trade_id' => 'unrelated-close', 'pnl' => -7.0], null, '2026-06-20 10:15:00+00', 401);

        $service = new PositionTradeAnalysisBackfillDivergenceReportService(
            new PositionTradeAnalysisBackfillDivergenceReader($this->conn),
        );
        $report = $service->buildReport(new BackfillDivergenceCriteria(symbol: 'BTCUSDT', limit: 10));

        $rowsByEntryId = [];
        foreach ($report['rows'] as $row) {
            $rowsByEntryId[$row['entry_event_id']] = $row;
        }

        self::assertArrayHasKey(301, $rowsByEntryId);
        self::assertSame('unmatched', $rowsByEntryId[301]['classification']);
        self::assertSame(401, $rowsByEntryId[301]['v1_close_event_id']);
        self::assertNull($rowsByEntryId[301]['v2_close_event_id']);
        self::assertSame('unmatched', $rowsByEntryId[301]['close_match_status']);
        self::assertSame('entry_event_id', $report['metadata']['comparison_key']);
    }

    private function createMinimalSchema(): void
    {
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis_v2');
        $this->conn->executeStatement('DROP VIEW IF EXISTS position_trade_analysis');
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

    private function applyViewMigrations(): void
    {
        $classes = [
            Version20251129000000::class => 'Version20251129000000.php',
            Version20260622000000::class => 'Version20260622000000.php',
            Version20260623010000::class => 'Version20260623010000.php',
            Version20260625000000::class => 'Version20260625000000.php',
            Version20260625020000::class => 'Version20260625020000.php',
            Version20260626000000::class => 'Version20260626000000.php',
        ];

        foreach ($classes as $class => $file) {
            if (!class_exists($class, false)) {
                require_once \dirname(__DIR__, 3) . '/migrations/' . $file;
            }
            $migration = new $class($this->conn, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $this->conn->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function entry(string $symbol, string $runId, array $extra, string $happenedAt, int $forcedId): void
    {
        $extra += [
            'orchestration_run_id' => $runId,
            'orchestration_dashboard_id' => 'dash-backfill',
            'orchestration_set_id' => 'set-backfill',
        ];

        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, config_profile, exchange, market_type, extra, happened_at)
             VALUES (?, ?, \'order_submitted\', ?, \'scalper\', \'fake\', \'perpetual\', ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, json_encode($extra, JSON_THROW_ON_ERROR), $happenedAt]
        );
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function close(string $symbol, string $runId, array $extra, ?string $positionId, string $happenedAt, int $forcedId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO trade_lifecycle_event (id, symbol, event_type, run_id, position_id, exchange, market_type, extra, happened_at)
             VALUES (?, ?, \'position_closed\', ?, ?, \'fake\', \'perpetual\', ?::jsonb, ?)',
            [$forcedId, $symbol, $runId, $positionId, json_encode($extra, JSON_THROW_ON_ERROR), $happenedAt]
        );
    }
}
