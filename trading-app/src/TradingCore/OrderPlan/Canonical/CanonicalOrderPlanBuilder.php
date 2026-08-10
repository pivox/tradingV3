<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use Psr\Clock\ClockInterface;

final readonly class CanonicalOrderPlanBuilder
{
    public function __construct(
        private ClockInterface $clock,
        private CanonicalOrderPlanValidator $validator,
        private CanonicalNetREngine $netREngine = new CanonicalNetREngine(),
        private CanonicalRiskEngine $riskEngine = new CanonicalRiskEngine(),
    ) {
    }

    public function build(CanonicalOrderPlanBuildRequest $request): CanonicalOrderPlan
    {
        $policy = $request->policy;
        $riskPolicy = $policy->riskPolicy;
        $zone = $request->zone;
        $protection = $request->protection;
        $risk = $request->risk;
        $suppliedNetR = $request->netR;
        foreach ([$zone, $protection] as $component) {
            if (
                $component->modeId !== $riskPolicy->modeId
                || $component->modeVersion !== $riskPolicy->modeVersion
                || $component->setupId !== $riskPolicy->setupId
                || $component->setupVersion !== $riskPolicy->setupVersion
                || $component->exchange !== $riskPolicy->exchange
                || $component->environment !== $riskPolicy->environment
                || $component->side !== $riskPolicy->side
                || $component->symbol !== $zone->symbol
                || $component->configHash !== $policy->configHash
            ) {
                throw new CanonicalOrderPlanException('canonical_order_plan_identity_mismatch');
            }
        }
        if (
            $risk->policy->configHash !== $policy->configHash
            || $request->riskRequest->policy->configHash !== $policy->configHash
            || $request->riskRequest->symbol !== $zone->symbol
            || $request->riskRequest->side !== $zone->side
            || $request->riskRequest->entryPrice !== $zone->entryPrice
            || $request->riskRequest->stopPrice !== $protection->stopPrice
            || $risk->symbol !== $zone->symbol
            || $risk->side !== $zone->side
            || $risk->entryPrice !== $zone->entryPrice
            || $risk->stopPrice !== $protection->stopPrice
            || $protection->entryPrice !== $zone->entryPrice
            || $suppliedNetR->configHash !== $policy->configHash
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_identity_mismatch');
        }
        $verifiedRisk = $this->riskEngine->calculate($request->riskRequest);
        if ($verifiedRisk != $risk) {
            throw new CanonicalOrderPlanException('canonical_order_plan_risk_mismatch');
        }
        $risk = $verifiedRisk;
        $netR = $this->netREngine->calculate(new CanonicalNetRRequest(
            $policy,
            $protection,
            $risk,
            $request->costs,
        ));
        if ($netR != $suppliedNetR) {
            throw new CanonicalOrderPlanException('canonical_order_plan_net_r_mismatch');
        }
        $now = $this->clock->now();
        if ($zone->computedAt > $now || $zone->expiresAt < $now) {
            throw new CanonicalOrderPlanException('canonical_order_plan_expired');
        }

        $acceptedRequest = new CanonicalOrderPlanBuildRequest(
            $policy,
            $zone,
            $protection,
            $request->riskRequest,
            $risk,
            $netR,
            $request->costs,
        );

        return $this->validator->validate(CanonicalOrderPlan::fromAcceptedComponents($acceptedRequest, $now));
    }
}
