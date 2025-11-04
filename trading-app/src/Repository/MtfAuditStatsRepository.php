<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;
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
        $sql = $concurrently
            ? 'REFRESH MATERIALIZED VIEW CONCURRENTLY mtf_audit_stats'
            : 'REFRESH MATERIALIZED VIEW mtf_audit_stats';

        $this->connection->executeStatement($sql);
        $this->logger->info('[MtfAuditStats] Materialized view refreshed', [ 'concurrently' => $concurrently ]);
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
        $row = $this->connection->fetchAssociative($sql) ?: [];
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
        $total = (int)$this->connection->fetchOne($sqlTotal);

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


