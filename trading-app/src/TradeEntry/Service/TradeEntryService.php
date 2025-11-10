<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\Dto\{TradeEntryRequest, ExecutionResult};
use App\TradeEntry\Hook\PostExecutionHookInterface;
use App\TradeEntry\Workflow\{BuildPreOrder, BuildOrderPlan, ExecuteOrderPlan};
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TradeEntryService
{
    public function __construct(
        private readonly BuildPreOrder $preflight,
        private readonly BuildOrderPlan $planner,
        private readonly ExecuteOrderPlan $executor,
        private readonly TradeEntryMetricsService $metrics,
        private readonly \App\TradeEntry\Policy\DailyLossGuard $dailyLossGuard,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function buildAndExecute(
        TradeEntryRequest $request,
        ?string $decisionKey = null,
        ?PostExecutionHookInterface $hook = null
    ): ExecutionResult {
        // Daily loss guard: block trading when limit is reached
        try {
            $state = $this->dailyLossGuard->checkAndMaybeLock();
            if ($state['locked'] === true) {
                $cid = sprintf('SKIP-DAILY-LOCK-%s', substr(sha1(($decisionKey ?? '') . microtime(true)), 0, 12));
                $this->positionsLogger->warning('order_journey.trade_entry.blocked', [
                    'symbol' => $request->symbol,
                    'decision_key' => $decisionKey,
                    'reason' => 'daily_loss_limit_reached',
                    'limit_usdt' => $state['limit_usdt'] ?? null,
                    'pnl_today' => $state['pnl_today'] ?? null,
                    'measure' => $state['measure'] ?? null,
                    'measure_value' => $state['measure_value'] ?? null,
                    'start_measure' => $state['start_measure'] ?? null,
                ]);
                return new ExecutionResult(
                    clientOrderId: $cid,
                    exchangeOrderId: null,
                    status: 'skipped',
                    raw: [
                        'reason' => 'daily_loss_limit_reached',
                        'limit_usdt' => $state['limit_usdt'] ?? null,
                        'pnl_today' => $state['pnl_today'] ?? null,
                        'measure' => $state['measure'] ?? null,
                        'measure_value' => $state['measure_value'] ?? null,
                        'start_measure' => $state['start_measure'] ?? null,
                    ],
                );
            }
        } catch (\Throwable $e) {
            // If guard fails unexpectedly, do not block, just log and continue
            $this->positionsLogger->error('order_journey.trade_entry.guard_error', [
                'symbol' => $request->symbol,
                'decision_key' => $decisionKey,
                'error' => $e->getMessage(),
            ]);
        }
        // Correlation key for logs across steps (allow external propagation)
        if ($decisionKey === null) {
            try {
                $decisionKey = sprintf('te:%s:%s', $request->symbol, bin2hex(random_bytes(6)));
            } catch (\Throwable) {
                $decisionKey = uniqid('te:' . $request->symbol . ':', true);
            }
        }

        $this->positionsLogger->info('order_journey.trade_entry.preflight_start', [
            'symbol' => $request->symbol,
            'decision_key' => $decisionKey,
            'reason' => 'pretrade_checks_begin',
            'order_type' => $request->orderType,
            'side' => $request->side->value,
        ]);

        $preflight = ($this->preflight)($request, $decisionKey);

        $this->positionsLogger->debug('order_journey.trade_entry.preflight_snapshot', [
            'symbol' => $preflight->symbol,
            'decision_key' => $decisionKey,
            'best_bid' => $preflight->bestBid,
            'best_ask' => $preflight->bestAsk,
            'spread_pct' => $preflight->spreadPct,
            'available_usdt' => $preflight->availableUsdt,
            'reason' => 'snapshot_after_checks',
        ]);

        $plan = ($this->planner)($request, $preflight, $decisionKey);

        $this->positionsLogger->info('order_journey.trade_entry.plan_ready', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'entry' => $plan->entry,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'order_mode' => $plan->orderMode,
            'reason' => 'plan_constructed',
        ]);

        $result = ($this->executor)($plan, $decisionKey);

        $this->positionsLogger->info('order_journey.trade_entry.execution_complete', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'status' => $result->status,
            'client_order_id' => $result->clientOrderId,
            'exchange_order_id' => $result->exchangeOrderId,
            'reason' => 'execution_finished',
        ]);

        $metric = match ($result->status) {
            'submitted' => 'submitted',
            'skipped' => 'skipped',
            default => 'errors',
        };
        $this->metrics->incr($metric);

        // Appeler le hook si fourni et si l'ordre a été soumis
        if ($hook !== null && $result->status === 'submitted') {
            $hook->onSubmitted($request, $result, $decisionKey);
        }

        return $result;
    }

    public function buildAndSimulate(
        TradeEntryRequest $request,
        ?string $decisionKey = null,
        ?PostExecutionHookInterface $hook = null
    ): ExecutionResult {
        if ($decisionKey === null) {
            try {
                $decisionKey = sprintf('te:%s:%s', $request->symbol, bin2hex(random_bytes(6)));
            } catch (\Throwable) {
                $decisionKey = uniqid('te:' . $request->symbol . ':', true);
            }
        }

        $this->positionsLogger->info('order_journey.trade_entry.simulation_start', [
            'symbol' => $request->symbol,
            'decision_key' => $decisionKey,
            'reason' => 'simulate_trade_entry',
        ]);

        // Run preflight and planning only (no execution)
        // Propagate decision key for consistent logging across steps
        $preflight = ($this->preflight)($request, $decisionKey);
        $plan = ($this->planner)($request, $preflight, $decisionKey);

        $cid = 'SIM-' . substr(sha1($decisionKey), 0, 12);
        $result = new ExecutionResult(
            clientOrderId: $cid,
            exchangeOrderId: null,
            status: 'simulated',
            raw: [
                'preflight' => [
                    'symbol' => $preflight->symbol,
                    'best_bid' => $preflight->bestBid,
                    'best_ask' => $preflight->bestAsk,
                    'price_precision' => $preflight->pricePrecision,
                    'available_usdt' => $preflight->availableUsdt,
                ],
                'plan' => [
                    'symbol' => $plan->symbol,
                    'side' => $plan->side->value,
                    'entry' => $plan->entry,
                    'stop' => $plan->stop,
                    'take_profit' => $plan->takeProfit,
                    'size' => $plan->size,
                    'leverage' => $plan->leverage,
                ],
            ],
        );

        // Appeler le hook si fourni
        if ($hook !== null) {
            $hook->onSimulated($request, $result, $decisionKey);
        }

        return $result;
    }
}
