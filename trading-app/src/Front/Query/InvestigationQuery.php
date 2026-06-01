<?php

declare(strict_types=1);

namespace App\Front\Query;

use Doctrine\DBAL\Connection;

final class InvestigationQuery
{
    private readonly FrontDatabase $db;

    public function __construct(
        Connection $connection,
        private readonly string $projectDir,
    ) {
        $this->db = new FrontDatabase($connection);
    }

    /**
     * @return array<string, mixed>
     */
    public function investigate(?string $symbol, ?string $date, ?string $decisionKey, ?string $runId): array
    {
        $symbol = $symbol !== null ? strtoupper(trim($symbol)) : '';
        $decisionKey = $decisionKey !== null ? trim($decisionKey) : '';
        $runId = $runId !== null ? trim($runId) : '';
        $center = $this->parseDate($date);
        $window = $this->window($center);

        if ($symbol === '' && $decisionKey === '' && $runId === '') {
            return [
                'searched' => false,
                'criteria' => compact('symbol', 'date', 'decisionKey', 'runId'),
                'sections' => [],
                'exports' => [],
            ];
        }

        $sections = [
            'mtf_symbols' => $this->mtfSymbols($symbol, $runId),
            'mtf_audit' => $this->mtfAudit($symbol, $runId, $window),
            'order_intents' => $this->orderIntents($symbol, $decisionKey, $window),
            'orders' => $this->orders($symbol, $window),
            'plan_orders' => $this->planOrders($symbol, $window),
            'lifecycle' => $this->lifecycle($symbol, $decisionKey, $runId, $window),
            'zone_events' => $this->zoneEvents($symbol, $decisionKey, $window),
            'snapshots' => $this->snapshots($symbol, $runId, $window),
            'entry_zones' => $this->entryZones($symbol, $window),
        ];

        return [
            'searched' => true,
            'criteria' => compact('symbol', 'date', 'decisionKey', 'runId'),
            'window' => [
                'from' => $window[0]?->format('Y-m-d H:i:s'),
                'to' => $window[1]?->format('Y-m-d H:i:s'),
            ],
            'sections' => $sections,
            'exports' => $this->exports($symbol),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mtfSymbols(string $symbol, string $runId): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        if ($runId !== '') {
            $where[] = 'run_id = :runId';
            $params['runId'] = $runId;
        }

        return $this->selectWithWhere(
            'mtf_run_symbol',
            'SELECT id, run_id, symbol, status, execution_tf, blocking_tf, signal_side, current_price, trading_decision, error, context, created_at FROM mtf_run_symbol',
            $where,
            $params,
            'created_at DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function mtfAudit(string $symbol, string $runId, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        if ($runId !== '') {
            $where[] = 'run_id = :runId';
            $params['runId'] = $runId;
        }
        $this->addWindow($where, $params, 'created_at', $window);

        return $this->selectWithWhere(
            'mtf_audit',
            'SELECT id, symbol, run_id, step, timeframe, cause, details, trace_id, severity, created_at, candle_open_ts FROM mtf_audit',
            $where,
            $params,
            'created_at DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function orderIntents(string $symbol, string $decisionKey, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        if ($decisionKey !== '') {
            $where[] = 'decision_key = :decisionKey';
            $params['decisionKey'] = $decisionKey;
        }
        $this->addWindow($where, $params, 'created_at', $window);

        return $this->selectWithWhere(
            'order_intent',
            'SELECT id, exchange, market_type, decision_key, strategy_profile, strategy_version, symbol, timeframe, side, type, status, price, size, client_order_id, order_id, exchange_order_id, failure_reason, created_at, updated_at, sent_at FROM order_intent',
            $where,
            $params,
            'created_at DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function orders(string $symbol, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        $this->addWindow($where, $params, 'created_at', $window);

        return $this->selectWithWhere(
            'futures_order',
            'SELECT id, exchange, market_type, symbol, side, type, status, price, size, filled_size, client_order_id, order_id, created_at, updated_at FROM futures_order',
            $where,
            $params,
            'created_at DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function planOrders(string $symbol, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        $this->addWindow($where, $params, 'created_at', $window);

        return $this->selectWithWhere(
            'futures_plan_order',
            'SELECT id, exchange, market_type, symbol, side, type, status, trigger_price, execution_price, price, size, plan_type, client_order_id, order_id, created_at, updated_at FROM futures_plan_order',
            $where,
            $params,
            'created_at DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function lifecycle(string $symbol, string $decisionKey, string $runId, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        if ($runId !== '') {
            $where[] = 'run_id = :runId';
            $params['runId'] = $runId;
        }
        if ($decisionKey !== '') {
            $where[] = 'CAST(extra AS TEXT) LIKE :needle';
            $params['needle'] = '%' . $decisionKey . '%';
        }
        $this->addWindow($where, $params, 'happened_at', $window);

        return $this->selectWithWhere(
            'trade_lifecycle_event',
            'SELECT id, symbol, event_type, run_id, order_id, client_order_id, side, qty, price, timeframe, config_profile, config_version, reason_code, extra, happened_at FROM trade_lifecycle_event',
            $where,
            $params,
            'happened_at DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function zoneEvents(string $symbol, string $decisionKey, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        if ($decisionKey !== '') {
            $where[] = '(decision_key = :decisionKey OR decision_key LIKE :decisionPrefix)';
            $params['decisionKey'] = $decisionKey;
            $params['decisionPrefix'] = $decisionKey . '%';
        }
        $this->addWindow($where, $params, 'happened_at', $window);

        return $this->selectWithWhere(
            'trade_zone_events',
            'SELECT id, exchange, market_type, symbol, happened_at, reason, decision_key, timeframe, config_profile, zone_min, zone_max, candidate_price, zone_dev_pct, zone_max_dev_pct, atr_pct, spread_bps, volume_ratio, vwap_distance_pct, entry_zone_width_pct, mtf_level, category FROM trade_zone_events',
            $where,
            $params,
            'happened_at DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function snapshots(string $symbol, string $runId, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        if ($runId !== '') {
            $where[] = 'run_id = :runId';
            $params['runId'] = $runId;
        }
        $this->addWindow($where, $params, 'kline_time', $window);

        return $this->selectWithWhere(
            'indicator_snapshots',
            'SELECT id, exchange, market_type, symbol, timeframe, run_id, kline_time, "values" AS values_json, trace_id, source, inserted_at FROM indicator_snapshots',
            $where,
            $params,
            'kline_time DESC',
        );
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     * @return list<array<string, mixed>>
     */
    private function entryZones(string $symbol, array $window): array
    {
        $where = [];
        $params = [];
        if ($symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params['symbol'] = $symbol;
        }
        $this->addWindow($where, $params, 'created_at', $window);

        return $this->selectWithWhere(
            'entry_zone_live',
            'SELECT id, symbol, side, price_min, price_max, atr_pct1m, vwap, volume_ratio, config_profile, config_version, valid_from, valid_until, created_at, status FROM entry_zone_live',
            $where,
            $params,
            'created_at DESC',
        );
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    private function selectWithWhere(string $table, string $selectSql, array $where, array $params, string $orderBy): array
    {
        $sql = $selectSql;
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ' . $orderBy . ' LIMIT 80';

        return $this->db->fetchAll([$table], $sql, $params);
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     */
    private function addWindow(array &$where, array &$params, string $column, array $window): void
    {
        if ($window[0] !== null) {
            $where[] = $column . ' >= :windowFrom';
            $params['windowFrom'] = $window[0]->format('Y-m-d H:i:s');
        }
        if ($window[1] !== null) {
            $where[] = $column . ' <= :windowTo';
            $params['windowTo'] = $window[1]->format('Y-m-d H:i:s');
        }
    }

    private function parseDate(?string $date): ?\DateTimeImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($date), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function window(?\DateTimeImmutable $center): array
    {
        if ($center === null) {
            return [null, null];
        }

        return [$center->modify('-1 hour'), $center->modify('+1 hour')];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exports(string $symbol): array
    {
        if ($symbol === '') {
            return [];
        }

        $files = glob($this->projectDir . '/investigation/symbol_data_' . $symbol . '_*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_map(fn (string $file): array => [
            'name' => basename($file),
            'path' => str_replace($this->projectDir . '/', '', $file),
            'updated_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
        ], array_slice($files, 0, 10));
    }
}
