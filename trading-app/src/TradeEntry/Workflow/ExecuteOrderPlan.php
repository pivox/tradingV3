<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\OrderPlan\OrderPlanModel;

final class ExecuteOrderPlan
{
    public function __construct(private readonly ExecutionBox $execution) {}

    public function __invoke(OrderPlanModel $plan): ExecutionResult
    {
        return $this->execution->execute($plan);
    }
}
