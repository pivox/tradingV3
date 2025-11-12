<?php

declare(strict_types=1);

namespace App\MtfValidator\Repository;

use Doctrine\DBAL\Connection;
use Throwable;
use Psr\Log\LoggerInterface;

final class MtfAuditStatsRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function refreshMaterializedView(bool $concurrently = true): void
    {
        // Try refresh; if view missing, create it and try again.
        try {
            $sql = $concurrently
                ? 'REFRESH MATERIALIZED VIEW CONCURRENTLY mtf_audit_stats'
                : 'REFRESH MATERIALIZED VIEW mtf_audit_stats';
            $this->connection->executeStatement($sql);
            $this->logger->info('[MtfAuditStats] Materialized view refreshed', [ 'concurrently' => $concurrently ]);
            return;
        } catch (Throwable $e) {
            if (!$this->isRelationMissingError($e)) {
                throw $e;
            }
            // Create the view and indexes, then refresh (non-concurrently first for safety)
            $this->createMaterializedViewIfMissing();
            $this->connection->executeStatement('REFRESH MATERIALIZED VIEW mtf_audit_stats');
            $this->logger->info('[MtfAuditStats] Materialized view created and refreshed');
        }
    }

    private function isRelationMissingError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'relation') && str_contains($msg, 'does not exist')
            || str_contains($msg, '42p01');
    }

    private function createMaterializedViewIfMissing(): void
    {
        // Create the view with the same definition as the migration (idempotent)
        $sqlCreate = <<<'SQL'
CREATE MATERIALIZED VIEW IF NOT EXISTS mtf_audit_stats AS
WITH base AS (
  SELECT
    symbol,
    COALESCE(NULLIF(timeframe, ''), NULLIF(details->> 'timeframe', '')) AS timeframe,
    created_at,
    COALESCE(candle_open_ts, NULLIF(details->> 'kline_time', '')::timestamptz) AS candle_open_ts,
    step,
    NULLIF(details->> 'passed', '')::boolean AS passed,
    CASE
      WHEN details ? 'metrics' AND NULLIF(details->'metrics'->> 'atr_rel', '') IS NOT NULL
        THEN NULLIF(details->'metrics'->> 'atr_rel', '')::numeric
      WHEN (details ? 'atr' AND details ? 'current_price'
            AND NULLIF(details->> 'current_price', '') ~ '^[0-9.]+$'
            AND (details->> 'current_price')::numeric > 0)
        THEN ((details->> 'atr')::numeric / NULLIF((details->> 'current_price')::numeric, 0))
      ELSE NULL
    END AS atr_rel,
    CASE WHEN details ? 'spread_bps' THEN NULLIF(details->> 'spread_bps', '')::numeric ELSE NULL END AS spread_bps,
    severity
  FROM mtf_audit
)
SELECT
  symbol,
  timeframe,
  COUNT(*)                                                       AS total,
  COUNT(*) FILTER (WHERE passed IS TRUE)                         AS nb_passed,
  CASE WHEN COUNT(*) > 0
       THEN ROUND((COUNT(*) FILTER (WHERE passed IS TRUE))::numeric * 100.0 / COUNT(*), 2)
       ELSE 0.0
  END                                                            AS pass_rate,
  AVG(atr_rel)                                                   AS avg_atr_rel,
  AVG(spread_bps)                                                AS avg_spread_bps,
  AVG(severity)::float                                           AS avg_severity,
  MAX(candle_open_ts)                                            AS last_candle_open_ts,
  MAX(created_at)                                                AS last_created_at,
  COUNT(*) FILTER (WHERE step = 'ALIGNMENT_FAILED')              AS nb_alignment_failed,
  COUNT(*) FILTER (WHERE step = '4H_VALIDATION_FAILED')          AS nb_validation_failed_4h,
  COUNT(*) FILTER (WHERE step = '1H_VALIDATION_FAILED')          AS nb_validation_failed_1h,
  COUNT(*) FILTER (WHERE step = '15M_VALIDATION_FAILED')         AS nb_validation_failed_15m,
  COUNT(*) FILTER (WHERE step = '5M_VALIDATION_FAILED')          AS nb_validation_failed_5m,
  COUNT(*) FILTER (WHERE step = '1M_VALIDATION_FAILED')          AS nb_validation_failed_1m
FROM base
WHERE timeframe IS NOT NULL AND timeframe <> ''
GROUP BY symbol, timeframe
SQL;

        $this->connection->executeStatement($sqlCreate);
        $this->connection->executeStatement('CREATE UNIQUE INDEX IF NOT EXISTS ux_mtf_audit_stats_symbol_tf ON mtf_audit_stats (symbol, timeframe)');
        $this->connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_last_created ON mtf_audit_stats (last_created_at DESC)');
        $this->connection->executeStatement('CREATE INDEX IF NOT EXISTS idx_mtf_audit_stats_pass_rate ON mtf_audit_stats (pass_rate DESC)');
        $this->logger->info('[MtfAuditStats] Materialized view created (if missing)');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSummary(?string $symbol = null, ?string $timeframe = null, int $limit = 50): array
    {
        $where = [];
        $params = [];
        $types = [];

        if ($symbol !== null && $symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
            $types['symbol'] = \PDO::PARAM_STR;
        }
        if ($timeframe !== null && $timeframe !== '') {
            $where[] = 'timeframe = :tf';
            $params['tf'] = $timeframe;
            $types['tf'] = \PDO::PARAM_STR;
        }

        $sql = 'SELECT symbol, timeframe, total, nb_passed, pass_rate, avg_atr_rel, avg_spread_bps, avg_severity, last_candle_open_ts, last_created_at, '
             . ' nb_alignment_failed, nb_validation_failed_4h, nb_validation_failed_1h, nb_validation_failed_15m, nb_validation_failed_5m, nb_validation_failed_1m'
             . ' FROM mtf_audit_stats';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY last_created_at DESC NULLS LAST, pass_rate DESC NULLS LAST LIMIT ' . max(1, $limit);

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * KPIs globaux basés sur la vue matérialisée
     * @return array{total_audits:int,total_passed:int,avg_pass_rate:float,avg_severity:float|null,last_created_at:string}
     */
    public function getGlobalKpis(): array
    {
        $sql = 'SELECT '
             . ' COALESCE(SUM(total),0) as total_audits, '
             . ' COALESCE(SUM(nb_passed),0) as total_passed, '
             . ' COALESCE(AVG(avg_severity),NULL) as avg_severity, '
             . ' MAX(last_created_at) as last_created_at'
             . ' FROM mtf_audit_stats';
        try {
            $row = $this->connection->fetchAssociative($sql) ?: [];
        } catch (Throwable $e) {
            if (!$this->isRelationMissingError($e)) {
                throw $e;
            }
            $this->createMaterializedViewIfMissing();
            $row = $this->connection->fetchAssociative($sql) ?: [];
        }
        $totalAudits = (int)($row['total_audits'] ?? 0);
        $totalPassed = (int)($row['total_passed'] ?? 0);
        $avgPassRate = $totalAudits > 0 ? round(($totalPassed / $totalAudits) * 100, 2) : 0.0;

        return [
            'total_audits' => $totalAudits,
            'total_passed' => $totalPassed,
            'avg_pass_rate' => $avgPassRate,
            'avg_severity' => isset($row['avg_severity']) ? (float)$row['avg_severity'] : null,
            'last_created_at' => (string)($row['last_created_at'] ?? 'N/A'),
        ];
    }

    /**
     * DataTables server-side paging for mtf_audit_stats
     * @return array{total:int, filtered:int, rows: array<int,array<string,mixed>>}
     */
    public function getSummaryPaged(?string $symbol, ?string $timeframe, string $search, int $orderCol, string $orderDir, int $start, int $length): array
    {
        $where = [];
        $params = [];
        $types = [];

        if ($symbol !== null && $symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
            $types['symbol'] = \PDO::PARAM_STR;
        }
        if ($timeframe !== null && $timeframe !== '') {
            $where[] = 'timeframe = :tf';
            $params['tf'] = $timeframe;
            $types['tf'] = \PDO::PARAM_STR;
        }
        if ($search !== '') {
            $where[] = '(symbol ILIKE :q OR timeframe ILIKE :q)';
            $params['q'] = '%' . $search . '%';
            $types['q'] = \PDO::PARAM_STR;
        }

        $orderColumns = [
            0 => 'symbol',
            1 => 'timeframe',
            2 => 'total',
            3 => 'nb_passed',
            4 => 'pass_rate',
            5 => 'avg_atr_rel',
            6 => 'avg_spread_bps',
            7 => 'avg_severity',
            8 => 'last_candle_open_ts',
            9 => 'last_created_at',
            10 => 'nb_alignment_failed',
            11 => 'nb_validation_failed_4h',
            12 => 'nb_validation_failed_1h',
            13 => 'nb_validation_failed_15m',
            14 => 'nb_validation_failed_5m',
            15 => 'nb_validation_failed_1m',
        ];
        $orderBy = $orderColumns[$orderCol] ?? 'total';
        $dir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

        // Total count
        $sqlTotal = 'SELECT COUNT(*) FROM mtf_audit_stats';
        try {
            $total = (int)$this->connection->fetchOne($sqlTotal);
        } catch (Throwable $e) {
            if (!$this->isRelationMissingError($e)) { throw $e; }
            $this->createMaterializedViewIfMissing();
            $total = (int)$this->connection->fetchOne($sqlTotal);
        }

        // Filtered count
        $sqlCount = 'SELECT COUNT(*) FROM mtf_audit_stats';
        if ($where !== []) { $sqlCount .= ' WHERE ' . implode(' AND ', $where); }
        $filtered = (int)$this->connection->fetchOne($sqlCount, $params, $types);

        // Rows
        $sql = 'SELECT symbol, timeframe, total, nb_passed, pass_rate, avg_atr_rel, avg_spread_bps, avg_severity, last_candle_open_ts, last_created_at, '
             . ' nb_alignment_failed, nb_validation_failed_4h, nb_validation_failed_1h, nb_validation_failed_15m, nb_validation_failed_5m, nb_validation_failed_1m'
             . ' FROM mtf_audit_stats';
        if ($where !== []) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY ' . $orderBy . ' ' . $dir . ' OFFSET ' . max(0, $start) . ' LIMIT ' . max(1, $length);
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);

        return [
            'total' => $total,
            'filtered' => $filtered,
            'rows' => $rows,
        ];
    }

    /**
     * Top by total audits
     * @return array<int,array{symbol:string,timeframe:string,total:int,pass_rate:float|null}>
     */
    public function getTopByTotal(?string $symbol, ?string $timeframe, int $limit = 10): array
    {
        $where = [];
        $params = [];
        $types = [];
        if ($symbol !== null && $symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
            $types['symbol'] = \PDO::PARAM_STR;
        }
        if ($timeframe !== null && $timeframe !== '') {
            $where[] = 'timeframe = :tf';
            $params['tf'] = $timeframe;
            $types['tf'] = \PDO::PARAM_STR;
        }
        $sql = 'SELECT symbol, timeframe, total, pass_rate FROM mtf_audit_stats';
        if ($where !== []) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY total DESC, pass_rate DESC NULLS LAST LIMIT ' . max(1, $limit);
        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }
}

