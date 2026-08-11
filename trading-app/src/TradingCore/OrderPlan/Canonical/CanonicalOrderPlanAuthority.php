<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;

final readonly class CanonicalOrderPlanAuthority
{
    public function __construct(
        private CanonicalProtectionEngine $protectionEngine = new CanonicalProtectionEngine(),
        private CanonicalRiskEngine $riskEngine = new CanonicalRiskEngine(),
        private CanonicalNetREngine $netREngine = new CanonicalNetREngine(),
    ) {
    }

    public function verify(CanonicalOrderPlanBuildRequest $request): CanonicalOrderPlanBuildRequest
    {
        $policy = $request->policy;
        $riskPolicy = $policy->riskPolicy;
        $zone = $request->zone;
        $protection = $request->protection;
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
                || $component->marketType !== $zone->marketType
                || $component->configHash !== $policy->configHash
            ) {
                throw new CanonicalOrderPlanException('canonical_order_plan_identity_mismatch');
            }
        }
        if (
            $request->zoneRequest->policy->configHash !== $policy->configHash
            || $request->protectionRequest->policy->configHash !== $policy->configHash
            || $request->riskRequest->policy->configHash !== $policy->configHash
            || $request->riskRequest->symbol !== $zone->symbol
            || $request->riskRequest->marketType !== $zone->marketType
            || $request->riskRequest->quoteCurrency !== $request->riskRequest->instrument->quoteCurrency
            || $request->riskRequest->side !== $zone->side
            || $request->riskRequest->entryPrice !== $zone->entryPrice
            || $request->riskRequest->stopPrice !== $protection->stopPrice
            || $request->risk->policy->configHash !== $policy->configHash
            || $request->risk->symbol !== $zone->symbol
            || $request->risk->marketType !== $zone->marketType
            || $request->risk->quoteCurrency !== $request->riskRequest->quoteCurrency
            || $request->risk->side !== $zone->side
            || $request->risk->entryPrice !== $zone->entryPrice
            || $request->risk->stopPrice !== $protection->stopPrice
            || $request->risk->instrument != $request->riskRequest->instrument
            || $protection->entryPrice !== $zone->entryPrice
            || $request->netR->configHash !== $policy->configHash
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_identity_mismatch');
        }

        $verifiedZone = CanonicalEntryZoneEngine::calculateAt($request->zoneRequest, $zone->computedAt);
        if ($verifiedZone != $zone) {
            throw new CanonicalOrderPlanException('canonical_order_plan_entry_zone_mismatch');
        }
        if ($request->orderBook !== null) {
            if (
                $request->orderBook->exchange !== $zone->exchange
                || $request->orderBook->environment !== $zone->environment
                || $request->orderBook->symbol !== $zone->symbol
                || $request->orderBook->marketType !== $zone->marketType
                || $request->orderBook->source !== $request->zoneRequest->market->source
            ) {
                throw new CanonicalOrderPlanException('canonical_order_book_identity_mismatch');
            }
            $entryViolation = $request->orderBook->entryViolation(
                $zone->side,
                $verifiedZone->entryPrice,
            );
            if ($entryViolation !== null) {
                throw new CanonicalOrderPlanException('canonical_' . $entryViolation);
            }
        }
        $verifiedProtectionRequest = new CanonicalProtectionRequest(
            $policy,
            $verifiedZone,
            $request->protectionRequest->atr,
            $request->protectionRequest->pivot,
        );
        $verifiedProtection = $this->protectionEngine->calculate($verifiedProtectionRequest);
        if ($verifiedProtection != $protection) {
            throw new CanonicalOrderPlanException('canonical_order_plan_protection_mismatch');
        }
        $verifiedRisk = $this->riskEngine->calculate($request->riskRequest);
        if ($verifiedRisk != $request->risk) {
            throw new CanonicalOrderPlanException('canonical_order_plan_risk_mismatch');
        }
        $verifiedNetR = $this->netREngine->calculate(new CanonicalNetRRequest(
            $policy,
            $verifiedProtection,
            $verifiedRisk,
            $request->costs,
        ));
        if ($verifiedNetR != $request->netR) {
            throw new CanonicalOrderPlanException('canonical_order_plan_net_r_mismatch');
        }

        return new CanonicalOrderPlanBuildRequest(
            $policy,
            $request->zoneRequest,
            $verifiedZone,
            $verifiedProtectionRequest,
            $verifiedProtection,
            $request->riskRequest,
            $verifiedRisk,
            $verifiedNetR,
            $request->costs,
            $request->orderBook,
        );
    }
}
