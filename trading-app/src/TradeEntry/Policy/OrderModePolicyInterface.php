<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\TradeEntry\OrderPlan\OrderPlanModel;

interface OrderModePolicyInterface
{
    public function enforce(OrderPlanModel $plan): void;
}

