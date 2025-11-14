<?php

declare(strict_types=1);

namespace App\Controller\Web;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class TradeLifecycleStatsController extends AbstractController
{
    private const MAX_HOURS = 240;
    private const DEFAULT_HOURS = 48;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('/trade-lifecycle/stats', name: 'trade_lifecycle_stats')]
    public function __invoke(Request $request): Response
    {
        $hours = (int) $request->query->get('hours', self::DEFAULT_HOURS);
        $hours = max(1, min(self::MAX_HOURS, $hours));
        $since = (new \DateTimeImmutable(sprintf('-%d hours', $hours)))->setTimezone(new \DateTimeZone('UTC'));

        $viewData = [
            'hours' => $hours,
            'updatedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'stats' => [
                'skipStats' => $this->fetchSkipStats($since),
                'zoneStats' => $this->fetchZoneStats($since),
                'missedTrades' => $this->fetchMissedTrades($since),
                'expirationStats' => $this->fetchExpirationStats($since),
                'avgTimeoutSec' => $this->fetchAverageTimeout($since),
                'mtfBlocking' => $this->fetchMtfBlocking($since),
                'marketQuality' => $this->fetchMarketQuality($since),
            ],
        ];

        return $this->render('TradeLifecycle/stats.html.twig', $viewData);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function fetchSkipStats(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT symbol,
                       COUNT(*) FILTER (WHERE reason_code = 'skipped_out_of_zone') AS skipped,
                       COUNT(*) AS total,
                       COALESCE(
                           COUNT(*) FILTER (WHERE reason_code = 'skipped_out_of_zone')::float
                           / NULLIF(COUNT(*), 0),
                           0
                       ) AS ratio
                FROM trade_lifecycle_event
                WHERE happened_at >= :since
                GROUP BY symbol
                HAVING COUNT(*) >= 5
                ORDER BY ratio DESC
                LIMIT 20
            SQL,
            $this->sinceParam($since)
        );

        return array_map(static function (array $row): array {
            return [
                'symbol' => $row['symbol'],
                'skipped' => (int) $row['skipped'],
                'total' => (int) $row['total'],
                'ratio' => (float) $row['ratio'],
            ];
        }, $rows);
    }

    private function fetchZoneStats(\DateTimeImmutable $since): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    AVG((extra->>'zone_dev_pct')::float) AS avg_dev,
                    AVG((extra->>'zone_max_dev_pct')::float) AS avg_max_dev,
                    MAX((extra->>'zone_dev_pct')::float) AS max_dev
                FROM trade_lifecycle_event
                WHERE reason_code = 'skipped_out_of_zone'
                  AND happened_at >= :since
                  AND extra ?? 'zone_dev_pct'
            SQL,
            $this->sinceParam($since)
        ) ?: [];

        return [
            'avg_dev' => $row['avg_dev'] ?? null ? (float) $row['avg_dev'] : null,
            'avg_max_dev' => $row['avg_max_dev'] ?? null ? (float) $row['avg_max_dev'] : null,
            'max_dev' => $row['max_dev'] ?? null ? (float) $row['max_dev'] : null,
        ];
    }

    private function fetchMissedTrades(\DateTimeImmutable $since): int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) AS missed
                FROM trade_lifecycle_event
                WHERE reason_code = 'skipped_out_of_zone'
                  AND happened_at >= :since
                  AND NULLIF(extra->>'close_after_5m_pct', '') IS NOT NULL
                  AND (extra->>'close_after_5m_pct')::float > 0.01
            SQL,
            $this->sinceParam($since)
        );

        return (int) $value;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchExpirationStats(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT symbol,
                       COUNT(*) FILTER (WHERE event_type = 'order_expired') AS expired,
                       COUNT(*) FILTER (WHERE event_type = 'order_submitted') AS submitted,
                       COALESCE(
                           COUNT(*) FILTER (WHERE event_type = 'order_expired')::float
                           / NULLIF(COUNT(*) FILTER (WHERE event_type = 'order_submitted'), 0),
                           0
                       ) AS ratio
                FROM trade_lifecycle_event
                WHERE happened_at >= :since
                  AND event_type IN ('order_expired', 'order_submitted')
                GROUP BY symbol
                HAVING COUNT(*) FILTER (WHERE event_type = 'order_submitted') > 0
                ORDER BY ratio DESC
                LIMIT 20
            SQL,
            $this->sinceParam($since)
        );

        return array_map(static function (array $row): array {
            return [
                'symbol' => $row['symbol'],
                'expired' => (int) $row['expired'],
                'submitted' => (int) $row['submitted'],
                'ratio' => (float) $row['ratio'],
            ];
        }, $rows);
    }

    private function fetchAverageTimeout(\DateTimeImmutable $since): ?float
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT AVG((extra->>'cancel_after_sec')::int) AS avg_timeout
                FROM trade_lifecycle_event
                WHERE event_type = 'order_expired'
                  AND extra ?? 'cancel_after_sec'
                  AND happened_at >= :since
            SQL,
            $this->sinceParam($since)
        );

        return $value !== null ? (float) $value : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchMtfBlocking(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT COALESCE(NULLIF(extra->>'mtf_fail_tf', ''), 'n/a') AS tf,
                       COUNT(*) AS total
                FROM trade_lifecycle_event
                WHERE reason_code LIKE 'skipped%'
                  AND happened_at >= :since
                GROUP BY tf
                ORDER BY total DESC
            SQL,
            $this->sinceParam($since)
        );

        return array_map(static function (array $row): array {
            return [
                'tf' => $row['tf'],
                'total' => (int) $row['total'],
            ];
        }, $rows);
    }

    private function fetchMarketQuality(\DateTimeImmutable $since): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    AVG((extra->>'spread_bps')::float) AS avg_spread,
                    AVG((extra->>'book_liquidity_score')::float) AS avg_liquidity,
                    AVG((extra->>'volume_ratio')::float) AS avg_volume_ratio,
                    AVG((extra->>'depth_top_usd')::float) AS avg_depth_usd,
                    AVG((extra->>'latency_ms_rest')::float) AS avg_latency_rest,
                    AVG((extra->>'latency_ms_ws')::float) AS avg_latency_ws
                FROM trade_lifecycle_event
                WHERE event_type = 'order_submitted'
                  AND happened_at >= :since
            SQL,
            $this->sinceParam($since)
        ) ?: [];

        return [
            'avg_spread' => $row['avg_spread'] ?? null ? (float) $row['avg_spread'] : null,
            'avg_liquidity' => $row['avg_liquidity'] ?? null ? (float) $row['avg_liquidity'] : null,
            'avg_volume_ratio' => $row['avg_volume_ratio'] ?? null ? (float) $row['avg_volume_ratio'] : null,
            'avg_depth_usd' => $row['avg_depth_usd'] ?? null ? (float) $row['avg_depth_usd'] : null,
            'avg_latency_rest' => $row['avg_latency_rest'] ?? null ? (float) $row['avg_latency_rest'] : null,
            'avg_latency_ws' => $row['avg_latency_ws'] ?? null ? (float) $row['avg_latency_ws'] : null,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function sinceParam(\DateTimeImmutable $since): array
    {
        return ['since' => $since->format('Y-m-d H:i:sP')];
    }
}
