<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\Dto\{TradeEntryRequest, ExecutionResult};
use App\TradeEntry\Workflow\{BuildPreOrder, BuildOrderPlan, ExecuteOrderPlan};

final class TradeEntryService
{
    public function __construct(
        private readonly BuildPreOrder $preflight,
        private readonly BuildOrderPlan $planner,
        private readonly ExecuteOrderPlan $executor,
        private readonly TradeEntryMetricsService $metrics,
    ) {}

    public function buildAndExecute(TradeEntryRequest $request, ?string $decisionKey = null): ExecutionResult
    {
        // Correlation key for logs across steps (allow external propagation)
        if ($decisionKey === null) {
            try {
                $decisionKey = sprintf('te:%s:%s', $request->symbol, bin2hex(random_bytes(6)));
            } catch (\Throwable) {
                $decisionKey = uniqid('te:' . $request->symbol . ':', true);
            }
        }

        $preflight = ($this->preflight)($request, $decisionKey);
        $plan = ($this->planner)($request, $preflight, $decisionKey);
        $result = ($this->executor)($plan, $decisionKey);

        $this->metrics->incr($result->status === 'submitted' ? 'submitted' : 'errors');

        return $result;
    }

    public function buildAndSimulate(TradeEntryRequest $request, ?string $decisionKey = null): ExecutionResult
    {
        if ($decisionKey === null) {
            try {
                $decisionKey = sprintf('te:%s:%s', $request->symbol, bin2hex(random_bytes(6)));
            } catch (\Throwable) {
                $decisionKey = uniqid('te:' . $request->symbol . ':', true);
            }
        }

        // Run preflight and planning only (no execution)
        // Propagate decision key for consistent logging across steps
        $preflight = ($this->preflight)($request, $decisionKey);
        $plan = ($this->planner)($request, $preflight, $decisionKey);

        $cid = 'SIM-' . substr(sha1($decisionKey), 0, 12);
        return new ExecutionResult(
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
    }
}
