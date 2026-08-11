<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

interface CanonicalOrderPlanBuilderInterface
{
    public function build(CanonicalOrderPlanBuildRequest $request): CanonicalOrderPlan;
}
