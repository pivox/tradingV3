<?php

declare(strict_types=1);

namespace App\Front\Query;

use App\Front\ViewModel\DecisionSummaryView;
use Doctrine\DBAL\Connection;

final class DecisionSummaryQuery
{
    private readonly FrontDatabase $db;

    public function __construct(Connection $connection)
    {
        $this->db = new FrontDatabase($connection);
    }

    public function latest(int $runLimit = 12, int $symbolLimit = 200): DecisionSummaryView
    {
        $runLimit = max(1, min(50, $runLimit));
        $symbolLimit = max(1, min(500, $symbolLimit));

        $runs = $this->db->fetchAll(
            ['mtf_run'],
            "SELECT run_id, status, execution_time_seconds, symbols_requested, symbols_processed, symbols_successful,
                    symbols_failed, symbols_skipped, success_rate, dry_run, force_run, current_tf, started_at, finished_at, workers
             FROM mtf_run
             ORDER BY started_at DESC
             LIMIT {$runLimit}",
        );

        $runId = isset($runs[0]['run_id']) ? (string) $runs[0]['run_id'] : null;
        $symbols = $runId !== null ? $this->symbolsForRun($runId, $symbolLimit) : [];
        $reasonCounts = $this->reasonCounts($symbols);

        return new DecisionSummaryView($runs, $symbols, $reasonCounts);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(string $decisionKey): array
    {
        $decisionKey = trim($decisionKey);
        if ($decisionKey === '') {
            return [
                'decision_key' => '',
                'order_intents' => [],
                'zone_events' => [],
                'lifecycle_events' => [],
            ];
        }

        return [
            'decision_key' => $decisionKey,
            'order_intents' => $this->db->fetchAll(
                ['order_intent'],
                "SELECT id, exchange, market_type, symbol, timeframe, side, type, status, price, size, client_order_id,
                        order_id, exchange_order_id, failure_reason, decision_key, strategy_profile, strategy_version, created_at, updated_at, sent_at
                 FROM order_intent
                 WHERE decision_key = :decisionKey
                 ORDER BY updated_at DESC
                 LIMIT 50",
                ['decisionKey' => $decisionKey],
            ),
            'zone_events' => $this->db->fetchAll(
                ['trade_zone_events'],
                "SELECT id, exchange, market_type, symbol, happened_at, reason, decision_key, timeframe, config_profile,
                        zone_min, zone_max, candidate_price, zone_dev_pct, zone_max_dev_pct, atr_pct, spread_bps,
                        volume_ratio, vwap_distance_pct, entry_zone_width_pct, mtf_level, category
                 FROM trade_zone_events
                 WHERE decision_key = :decisionKey OR decision_key LIKE :decisionPrefix
                 ORDER BY happened_at DESC
                 LIMIT 50",
                ['decisionKey' => $decisionKey, 'decisionPrefix' => $decisionKey . '%'],
            ),
            'lifecycle_events' => $this->db->fetchAll(
                ['trade_lifecycle_event', 'order_intent'],
                "SELECT id, symbol, event_type, run_id, order_id, client_order_id, side, qty, price, timeframe,
                        config_profile, config_version, reason_code, extra, happened_at
                 FROM trade_lifecycle_event
                 WHERE CAST(extra AS TEXT) LIKE :needle
                    OR client_order_id = :decisionKey
                    OR order_id = :decisionKey
                    OR client_order_id IN (
                        SELECT client_order_id
                        FROM order_intent
                        WHERE decision_key = :decisionKey
                          AND client_order_id IS NOT NULL
                    )
                    OR order_id IN (
                        SELECT order_id
                        FROM order_intent
                        WHERE decision_key = :decisionKey
                          AND order_id IS NOT NULL
                    )
                    OR order_id IN (
                        SELECT exchange_order_id
                        FROM order_intent
                        WHERE decision_key = :decisionKey
                          AND exchange_order_id IS NOT NULL
                    )
                 ORDER BY happened_at DESC
                 LIMIT 50",
                ['needle' => '%' . $decisionKey . '%', 'decisionKey' => $decisionKey],
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function symbolsForRun(string $runId, int $limit): array
    {
        $symbols = $this->db->fetchAll(
            ['mtf_run_symbol'],
            "SELECT id, run_id, symbol, status, execution_tf, blocking_tf, signal_side, current_price,
                    trading_decision, error, context, created_at
             FROM mtf_run_symbol
             WHERE run_id = :runId
             ORDER BY created_at DESC, symbol ASC
             LIMIT {$limit}",
            ['runId' => $runId],
        );

        foreach ($symbols as $index => $symbol) {
            $tradingDecision = FrontDatabase::jsonObject($symbol['trading_decision'] ?? null);
            $error = FrontDatabase::jsonObject($symbol['error'] ?? null);
            $context = FrontDatabase::jsonObject($symbol['context'] ?? null);

            $symbols[$index]['trading_decision'] = $tradingDecision;
            $symbols[$index]['error'] = $error;
            $symbols[$index]['context'] = $context;
            $symbols[$index]['decision_key'] = $tradingDecision['decision_key'] ?? $context['decision_key'] ?? null;
            $symbols[$index]['failed_rules'] = $this->failedRules($tradingDecision, $context);
            $symbols[$index]['primary_reason'] = $this->primaryReason($symbol, $tradingDecision, $error, $context);
        }

        return $symbols;
    }

    /**
     * @param list<array<string, mixed>> $symbols
     * @return list<array{reason: string, count: int}>
     */
    private function reasonCounts(array $symbols): array
    {
        $counts = [];
        foreach ($symbols as $symbol) {
            $reason = (string) ($symbol['primary_reason'] ?? 'Inconnu');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        arsort($counts);

        $rows = [];
        foreach ($counts as $reason => $count) {
            $rows[] = ['reason' => $reason, 'count' => $count];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $symbol
     * @param array<string, mixed> $tradingDecision
     * @param array<string, mixed> $error
     * @param array<string, mixed> $context
     */
    private function primaryReason(array $symbol, array $tradingDecision, array $error, array $context): string
    {
        $rawReason = $tradingDecision['reason']
            ?? $tradingDecision['message']
            ?? $tradingDecision['error']
            ?? $error['message']
            ?? $error['error']
            ?? $context['reason']
            ?? $context['primary_reason']
            ?? $symbol['status']
            ?? 'unknown';

        $reason = is_scalar($rawReason) ? (string) $rawReason : 'unknown';

        return match ($reason) {
            'filters_mandatory_failed_execution_selector_empty',
            'filters_mandatory_failed' => 'Filtres obligatoires non validés',
            'NO_LONG_NO_SHORT' => 'Aucun côté long/short valide',
            'LONG_AND_SHORT' => 'Conflit long et short',
            'entry_zone_invalid_or_filters_failed' => 'EntryZone ou filtres invalides',
            'skipped_out_of_zone',
            'entry_zone.rejected_by_deviation' => 'Prix hors EntryZone',
            default => $this->humanizeReason($reason),
        };
    }

    /**
     * @param array<string, mixed> $tradingDecision
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function failedRules(array $tradingDecision, array $context): array
    {
        $candidates = [
            $context['rules_failed'] ?? null,
            $context['failed_rules'] ?? null,
            $context['conditions_failed'] ?? null,
            $context['failed_conditions_long'] ?? null,
            $context['failed_conditions_short'] ?? null,
            $tradingDecision['failed_checks'] ?? null,
            $tradingDecision['rules_failed'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || $candidate === []) {
                continue;
            }

            return array_values(array_map(static fn (mixed $value): string => (string) $value, $candidate));
        }

        return [];
    }

    private function humanizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return 'Inconnu';
        }

        return ucfirst(str_replace('_', ' ', $reason));
    }
}
