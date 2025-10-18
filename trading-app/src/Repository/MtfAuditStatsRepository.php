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

        $sql = 'SELECT symbol, timeframe, total, nb_passed, pass_rate, avg_atr_rel, avg_spread_bps, avg_severity, last_candle_close_ts, last_created_at, '
             . ' nb_alignment_failed, nb_validation_failed_4h, nb_validation_failed_1h, nb_validation_failed_15m, nb_validation_failed_5m, nb_validation_failed_1m'
             . ' FROM mtf_audit_stats';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY last_created_at DESC NULLS LAST, pass_rate DESC NULLS LAST LIMIT ' . max(1, $limit);

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }
}



