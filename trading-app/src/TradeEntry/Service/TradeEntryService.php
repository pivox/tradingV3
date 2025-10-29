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

    public function buildAndExecute(TradeEntryRequest $request): ExecutionResult
    {
        $preflight = ($this->preflight)($request);
        $plan = ($this->planner)($request, $preflight);
        $result = ($this->executor)($plan);

        $this->metrics->incr($result->status === 'submitted' ? 'submitted' : 'errors');

        return $result;
    }
}
