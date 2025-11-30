<?php

declare(strict_types=1);

namespace App\MtfRunner\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class SymbolInvestigationService
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function investigate(string $symbol, \DateTimeImmutable $targetDateTime): array
    {
        $symbol = strtoupper($symbol);
        $startTime = $targetDateTime->modify('-1 hour');
        $endTime = $targetDateTime->modify('+1 hour');

        $runs = $this->fetchRuns($symbol, $startTime, $endTime);
        $traceIds = $this->fetchTraceIds($symbol, $startTime, $endTime);

        $runsData = [];
        foreach ($runs as $run) {
            $runId = (string) ($run['run_id'] ?? '');
            $runsData[] = [
                'run_id' => $runId,
                'first_event' => $run['first_event'] ?? null,
                'last_event' => $run['last_event'] ?? null,
                'event_count' => (int) ($run['event_count'] ?? 0),
                'data' => $runId !== '' ? $this->exportRunData($symbol, $runId, $traceIds) : [],
            ];
        }

        $globalData = $this->exportGlobalSymbolData($symbol, $startTime, $endTime);
        $logs = $this->exportLogsForDateTime($symbol, $targetDateTime);

        return [
            'metadata' => [
                'symbol' => $symbol,
                'target_datetime' => $targetDateTime->format('Y-m-d H:i:s'),
                'time_window_start' => $startTime->format('Y-m-d H:i:s'),
                'time_window_end' => $endTime->format('Y-m-d H:i:s'),
                'total_runs' => count($runs),
                'total_trace_ids' => count($traceIds),
            ],
            'runs' => $runsData,
            'global_data' => $globalData,
            'logs' => $logs,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchRuns(string $symbol, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $sql = <<<SQL
            SELECT DISTINCT run_id,
                MIN(happened_at) AS first_event,
                MAX(happened_at) AS last_event,
                COUNT(*) AS event_count
            FROM trade_lifecycle_event
            WHERE symbol = ?
              AND happened_at >= ?
              AND happened_at <= ?
              AND run_id IS NOT NULL
            GROUP BY run_id
            ORDER BY first_event DESC
        SQL;

        return $this->connection->fetchAllAssociative($sql, [
            $symbol,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return string[]
     */
    private function fetchTraceIds(string $symbol, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $sql = <<<SQL
            SELECT DISTINCT trace_id
            FROM mtf_audit
            WHERE symbol = ? AND created_at >= ? AND created_at <= ?
            UNION
            SELECT DISTINCT trace_id
            FROM indicator_snapshots
            WHERE symbol = ? AND inserted_at >= ? AND inserted_at <= ?
        SQL;

        return $this->connection->fetchFirstColumn($sql, [
            $symbol,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $symbol,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param string[] $traceIds
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function exportRunData(string $symbol, string $runId, array $traceIds): array
    {
        $data = [];

        $data['trade_lifecycle_event'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM trade_lifecycle_event WHERE run_id = ? ORDER BY happened_at",
            [$runId]
        );

        $clientOrderId = $this->connection->fetchOne(
            "SELECT client_order_id FROM trade_lifecycle_event WHERE run_id = ? AND client_order_id IS NOT NULL LIMIT 1",
            [$runId]
        );
        $orderId = $this->connection->fetchOne(
            "SELECT order_id FROM trade_lifecycle_event WHERE run_id = ? AND order_id IS NOT NULL LIMIT 1",
            [$runId]
        );

        if ($clientOrderId) {
            $data['order_intent'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM order_intent WHERE client_order_id = ?",
                [$clientOrderId]
            );
            $data['futures_order'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM futures_order WHERE client_order_id = ?",
                [$clientOrderId]
            );
            $data['futures_plan_order'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM futures_plan_order WHERE client_order_id = ?",
                [$clientOrderId]
            );
        } elseif ($symbol) {
            $data['order_intent'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM order_intent WHERE symbol = ? AND created_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) - INTERVAL '1 hour' ORDER BY created_at DESC LIMIT 10",
                [$symbol, $runId]
            );
            $data['futures_order'] = [];
            $data['futures_plan_order'] = [];
        } else {
            $data['order_intent'] = [];
            $data['futures_order'] = [];
            $data['futures_plan_order'] = [];
        }

        if (empty($data['futures_order']) && $orderId) {
            $data['futures_order'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM futures_order WHERE order_id = ?",
                [$orderId]
            );
        }

        $futuresOrderIds = array_column($data['futures_order'], 'id');
        if (!empty($futuresOrderIds)) {
            $placeholders = implode(',', array_fill(0, count($futuresOrderIds), '?'));
            $data['futures_order_trade'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM futures_order_trade WHERE futures_order_id IN ($placeholders)",
                $futuresOrderIds
            );
        } else {
            $data['futures_order_trade'] = [];
        }

        $traceIdsStr = '{' . implode(',', $traceIds) . '}';
        $data['mtf_audit'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_audit WHERE run_id = ?::uuid OR (symbol = ? AND trace_id = ANY(?::text[])) ORDER BY created_at",
            [$runId, $symbol, $traceIdsStr]
        );
        $data['mtf_run'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_run WHERE run_id = ?::uuid",
            [$runId]
        );
        $data['mtf_run_symbol'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_run_symbol WHERE run_id = ?::uuid",
            [$runId]
        );
        $data['mtf_run_metric'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_run_metric WHERE run_id = ?::uuid",
            [$runId]
        );

        $decisionKey = $this->connection->fetchOne(
            "SELECT plan_id FROM trade_lifecycle_event WHERE run_id = ? AND plan_id IS NOT NULL LIMIT 1",
            [$runId]
        );

        $tradeZoneQuery = "SELECT * FROM trade_zone_events WHERE symbol = ?";
        $params = [$symbol];
        if ($decisionKey) {
            $tradeZoneQuery .= " AND (decision_key = ? OR decision_key LIKE ?)";
            $params[] = $decisionKey;
            $params[] = '%' . $decisionKey . '%';
        }
        $tradeZoneQuery .= " AND happened_at >= (SELECT MIN(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) - INTERVAL '1 hour'";
        $tradeZoneQuery .= " AND happened_at <= (SELECT MAX(happened_at) FROM trade_lifecycle_event WHERE run_id = ?) + INTERVAL '1 hour'";
        $tradeZoneQuery .= " ORDER BY happened_at";
        $params[] = $runId;
        $params[] = $runId;

        $data['trade_zone_events'] = $this->connection->fetchAllAssociative(
            $tradeZoneQuery,
            $params
        );

        if (!empty($traceIds)) {
            $placeholders = implode(',', array_fill(0, count($traceIds), '?'));
            $data['indicator_snapshots'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM indicator_snapshots WHERE trace_id IN ($placeholders) ORDER BY kline_time",
                $traceIds
            );
        } else {
            $data['indicator_snapshots'] = [];
        }

        return $data;
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function exportGlobalSymbolData(string $symbol, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $params = [$symbol, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];

        $data = [];
        $data['mtf_state'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM mtf_state WHERE symbol = ? AND updated_at >= ? AND updated_at <= ? ORDER BY updated_at DESC",
            $params
        );
        try {
            $data['mtf_switch'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM mtf_switch WHERE contract_symbol = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at DESC",
                $params
            );
        } catch (\Throwable $e) {
            $data['mtf_switch'] = [];
        }
        try {
            $lockParams = ['%' . $symbol . '%', $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
            $data['mtf_lock'] = $this->connection->fetchAllAssociative(
                "SELECT * FROM mtf_lock WHERE lock_key LIKE ? AND acquired_at >= ? AND acquired_at <= ? ORDER BY acquired_at DESC",
                $lockParams
            );
        } catch (\Throwable $e) {
            $data['mtf_lock'] = [];
        }

        $data['order_intent_all'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM order_intent WHERE symbol = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at DESC",
            $params
        );
        $data['futures_order_all'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM futures_order WHERE symbol = ? AND created_at >= ? AND created_at <= ? ORDER BY created_at DESC",
            $params
        );
        $data['trade_lifecycle_event_all'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM trade_lifecycle_event WHERE symbol = ? AND happened_at >= ? AND happened_at <= ? ORDER BY happened_at DESC",
            $params
        );
        $data['trade_zone_events_all'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM trade_zone_events WHERE symbol = ? AND happened_at >= ? AND happened_at <= ? ORDER BY happened_at DESC",
            $params
        );
        $data['indicator_snapshots_all'] = $this->connection->fetchAllAssociative(
            "SELECT * FROM indicator_snapshots WHERE symbol = ? AND inserted_at >= ? AND inserted_at <= ? ORDER BY kline_time DESC",
            $params
        );

        return $data;
    }

    /**
     * @return array<string,string[]>
     */
    private function exportLogsForDateTime(string $symbol, \DateTimeImmutable $targetDateTime): array
    {
        $logDir = $this->projectDir . '/var/log';
        $dateStr = $targetDateTime->format('Y-m-d');
        $startTime = $targetDateTime->modify('-5 minutes');
        $endTime = $targetDateTime->modify('+5 minutes');

        $logFiles = [
            'positions' => $logDir . "/positions-$dateStr.log",
            'mtf' => $logDir . "/mtf-$dateStr.log",
            'signals' => $logDir . "/signals-$dateStr.log",
            'bitmart' => $logDir . "/bitmart-$dateStr.log",
            'provider' => $logDir . "/provider-$dateStr.log",
            'indicators' => $logDir . "/indicators-$dateStr.log",
            'dev' => $logDir . "/dev-$dateStr.log",
        ];

        $logData = [];
        foreach ($logFiles as $type => $file) {
            if (!is_file($file)) {
                continue;
            }
            $lines = $this->extractLogLines($file, $symbol, $startTime, $endTime, 1000);
            if (!empty($lines)) {
                $logData[$type] = $lines;
            }
        }

        return $logData;
    }

    /**
     * @return string[]
     */
    private function extractLogLines(string $filePath, string $symbol, \DateTimeImmutable $start, \DateTimeImmutable $end, int $limit): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $matching = [];
        while (($line = fgets($handle)) !== false) {
            if (stripos($line, $symbol) === false) {
                continue;
            }

            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\]/', $line, $matches)) {
                try {
                    $lineTime = new \DateTimeImmutable($matches[1], new \DateTimeZone('UTC'));
                    if ($lineTime >= $start && $lineTime <= $end) {
                        $matching[] = rtrim($line);
                        if (count($matching) >= $limit) {
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    // ignore invalid timestamps
                }
            }
        }

        fclose($handle);
        return $matching;
    }
}
