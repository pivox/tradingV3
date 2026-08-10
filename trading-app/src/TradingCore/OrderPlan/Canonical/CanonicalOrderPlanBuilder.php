<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Psr\Clock\ClockInterface;

final readonly class CanonicalOrderPlanBuilder
{
    public function __construct(
        private ClockInterface $clock,
        private CanonicalOrderPlanValidator $validator,
        private CanonicalNetREngine $netREngine = new CanonicalNetREngine(),
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
            || $risk->symbol !== $zone->symbol
            || $risk->side !== $zone->side
            || $risk->entryPrice !== $zone->entryPrice
            || $risk->stopPrice !== $protection->stopPrice
            || $protection->entryPrice !== $zone->entryPrice
            || $suppliedNetR->configHash !== $policy->configHash
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_identity_mismatch');
        }
        $netR = $this->netREngine->calculate(new CanonicalNetRRequest(
            $policy,
            $protection,
            $risk,
            $request->costs,
        ));
        if ($netR != $suppliedNetR) {
            throw new CanonicalOrderPlanException('canonical_order_plan_net_r_mismatch');
        }
        if (count($protection->targets) !== count($netR->targets)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_target_mismatch');
        }

        $targets = [];
        foreach ($protection->targets as $index => $protectionTarget) {
            $netTarget = $netR->targets[$index] ?? null;
            if (!$netTarget instanceof CanonicalNetRTargetDecision || $netTarget->id !== $protectionTarget->id || $netTarget->price !== $protectionTarget->price) {
                throw new CanonicalOrderPlanException('canonical_order_plan_target_mismatch');
            }
            $targets[] = new CanonicalOrderPlanTarget(
                id: $protectionTarget->id,
                price: $protectionTarget->price,
                riskMultiple: $protectionTarget->riskMultiple,
                liquidityRole: $protectionTarget->liquidityRole,
                grossReward: $netTarget->grossReward,
                entryFee: $netTarget->entryFee,
                targetFee: $netTarget->targetFee,
                entrySpreadCost: $netTarget->entrySpreadCost,
                entrySlippageCost: $netTarget->entrySlippageCost,
                targetSpreadCost: $netTarget->targetSpreadCost,
                targetSlippageCost: $netTarget->targetSlippageCost,
                fundingCost: $netTarget->fundingCost,
                netReward: $netTarget->netReward,
                netRisk: $netTarget->netRisk,
                netR: $netTarget->netR,
            );
        }
        $inputHashes = array_values(array_unique([...$zone->inputHashes, ...$protection->inputHashes, $netR->costInputHash]));
        $now = $this->clock->now();
        if ($zone->computedAt > $now || $zone->expiresAt < $now) {
            throw new CanonicalOrderPlanException('canonical_order_plan_expired');
        }

        $acceptedRequest = new CanonicalOrderPlanBuildRequest(
            $policy,
            $zone,
            $protection,
            $risk,
            $netR,
            $request->costs,
        );

        return $this->validator->validate(CanonicalOrderPlan::fromAcceptedComponents($acceptedRequest, $targets, $inputHashes, $now));
    }
}
