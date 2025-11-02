<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\TradeEntry\OrderPlan\OrderPlanModel;

final class TakerOnlyPolicy implements OrderModePolicyInterface
{
    public function __construct() {}

    public function enforce(OrderPlanModel $plan): void
    {
        if ($plan->orderType === 'limit' && $plan->orderMode !== 1) {
            throw new \RuntimeException('TakerOnly requis (orderMode=1) pour LIMIT');
        }
    }
}

