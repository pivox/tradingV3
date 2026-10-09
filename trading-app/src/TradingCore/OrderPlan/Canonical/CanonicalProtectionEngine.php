<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final class CanonicalProtectionEngine
{
    public function calculate(CanonicalProtectionRequest $request): CanonicalProtectionDecision
    {
        $policy = $request->policy;
        $risk = $policy->riskPolicy;
        $zone = $request->entryZone;
        if (
            $zone->modeId !== $risk->modeId
            || $zone->modeVersion !== $risk->modeVersion
            || $zone->setupId !== $risk->setupId
            || $zone->setupVersion !== $risk->setupVersion
            || $zone->exchange !== $risk->exchange
            || $zone->environment !== $risk->environment
            || $zone->side !== $risk->side
            || $zone->configHash !== $policy->configHash
        ) {
            throw new CanonicalOrderPlanException('canonical_protection_identity_mismatch');
        }
        self::positive($zone->entryPrice, 'canonical_protection_entry_invalid');
        self::positive($zone->tickSize, 'canonical_protection_tick_invalid');

        $stopInput = $this->stopInput($request);
        $this->validateStopInput($stopInput, $request);
        $prices = ProtectionPriceMath::calculate(
            $policy->stop, $policy->targets, $risk->side, $zone->entryPrice, $zone->tickSize, $stopInput->value,
        );

        return new CanonicalProtectionDecision(
            modeId: $risk->modeId,
            modeVersion: $risk->modeVersion,
            setupId: $risk->setupId,
            setupVersion: $risk->setupVersion,
            exchange: $risk->exchange,
            environment: $risk->environment,
            side: $risk->side,
            symbol: $zone->symbol,
            marketType: $zone->marketType,
            entryPrice: $prices['entry_price'],
            stopPrice: $prices['stop_price'],
            riskDistance: $prices['risk_distance'],
            targets: $prices['targets'],
            oldestObservedAt: min($zone->oldestObservedAt, $stopInput->observedAt),
            computedAt: $zone->computedAt,
            configHash: $policy->configHash,
            inputHashes: [...$zone->inputHashes, $stopInput->inputHash],
        );
    }

    private function stopInput(CanonicalProtectionRequest $request): CanonicalPriceObservation
    {
        return match ($request->policy->stop->kind) {
            'atr' => $request->atr !== null && $request->pivot === null
                ? $request->atr
                : throw new CanonicalOrderPlanException('canonical_protection_atr_required'),
            'pivot' => $request->pivot !== null && $request->atr === null
                ? $request->pivot
                : throw new CanonicalOrderPlanException('canonical_protection_pivot_required'),
            default => throw new CanonicalOrderPlanException('canonical_stop_policy_invalid'),
        };
    }

    private function validateStopInput(CanonicalPriceObservation $input, CanonicalProtectionRequest $request): void
    {
        $policy = $request->policy;
        $zone = $request->entryZone;
        if (
            $input->exchange !== $policy->riskPolicy->exchange
            || $input->environment !== $policy->riskPolicy->environment
            || $input->symbol !== $zone->symbol
            || $input->marketType !== $zone->marketType
        ) {
            throw new CanonicalOrderPlanException('canonical_protection_input_identity_mismatch');
        }
        $expectedSource = $policy->stop->kind === 'atr' ? 'atr' : $policy->stop->pivotId;
        if ($input->source !== $expectedSource || $input->timeframe !== $policy->stop->timeframe) {
            throw new CanonicalOrderPlanException($policy->stop->kind === 'atr' ? 'canonical_protection_atr_mismatch' : 'canonical_protection_pivot_mismatch');
        }
        self::positive($input->value, 'canonical_protection_input_invalid');
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $input->inputHash) !== 1) {
            throw new CanonicalOrderPlanException('canonical_protection_input_hash_invalid');
        }
        if ($input->observedAt > $zone->computedAt) {
            throw new CanonicalOrderPlanException('canonical_protection_input_future');
        }
        if (CanonicalOrderPlanTime::isOlderThan($input->observedAt, $zone->computedAt, $policy->entryZone->maximumInputAgeSeconds)) {
            throw new CanonicalOrderPlanException('canonical_protection_input_stale');
        }
    }

    private static function positive(float $value, string $reasonCode): void
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
    }
}
