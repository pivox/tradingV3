<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;


use App\TradeEntry\OrderPlan\OrderPlanModel;

final class MakerOnlyPolicy
{
    public function __construct() {}

    public function enforce(OrderPlanModel $plan): void
    {
        if ($plan->orderType === 'limit' && $plan->orderMode !== 4) {
            throw new \RuntimeException('MakerOnly requis (orderMode=4) pour LIMIT');
        }
    }
}
