<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Psr\Clock\ClockInterface;

final readonly class CanonicalOrderPlanBuilder
{
    public function __construct(
        private ClockInterface $clock,
        private CanonicalOrderPlanValidator $validator,
        private CanonicalOrderPlanAuthority $authority = new CanonicalOrderPlanAuthority(),
    ) {
    }

    public function build(CanonicalOrderPlanBuildRequest $request): CanonicalOrderPlan
    {
        return CanonicalOrderPlan::build($request, $this->clock, $this->validator, $this->authority);
    }
}
