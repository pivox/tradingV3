<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\TradeEntry\Dto\{TradeEntryRequest, PreflightReport};

final class OrderPlanBox
{
    public function __construct(private readonly OrderPlanBuilder $builder) {}

    public function create(TradeEntryRequest $req, PreflightReport $pre, ?string $decisionKey = null): OrderPlanModel
    {
        return $this->builder->build($req, $pre, $decisionKey);
    }
}
