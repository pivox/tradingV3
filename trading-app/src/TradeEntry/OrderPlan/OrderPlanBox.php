<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Dto\RiskDecision;
use App\TradeEntry\Dto\TradeEntryRequest;
 
final class OrderPlanBox
{
    public function __construct(private OrderPlanBuilder $builder) {}

    public function build(TradeEntryRequest $req, EntryZone $zone, RiskDecision $risk): OrderPlanModel
    {
        return $this->builder->build($req, $zone, $risk);
    }
}
