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
        $acceptedRequest = $this->authority->verify($request);
        $zone = $acceptedRequest->zone;
        $now = $this->clock->now();
        if ($zone->computedAt > $now || $zone->expiresAt < $now) {
            throw new CanonicalOrderPlanException('canonical_order_plan_expired');
        }

        return $this->validator->validate(CanonicalOrderPlan::fromAcceptedComponents($acceptedRequest, $now));
    }
}
