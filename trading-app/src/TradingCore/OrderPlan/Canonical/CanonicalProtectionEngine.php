<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

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
        $entry = self::decimal($zone->entryPrice);
        $tick = self::decimal($zone->tickSize);
        $buffer = self::decimal($policy->stop->bufferRate);
        if ($policy->stop->kind === 'atr') {
            if ($policy->stop->atrMultiplier === null) {
                throw new CanonicalOrderPlanException('canonical_protection_atr_required');
            }
            $distance = self::decimal($stopInput->value)
                ->multipliedBy(self::decimal($policy->stop->atrMultiplier))
                ->plus($entry->multipliedBy($buffer));
            $rawStop = $risk->side === 'long' ? $entry->minus($distance) : $entry->plus($distance);
        } else {
            $pivot = self::decimal($stopInput->value);
            $rawStop = $risk->side === 'long'
                ? $pivot->multipliedBy(BigDecimal::one()->minus($buffer))
                : $pivot->multipliedBy(BigDecimal::one()->plus($buffer));
        }

        $stop = self::quantize($rawStop, $tick, $risk->side === 'long' ? RoundingMode::FLOOR : RoundingMode::CEILING);
        if ($stop->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new CanonicalOrderPlanException('canonical_protection_stop_invalid');
        }
        if (($risk->side === 'long' && !$stop->isLessThan($entry)) || ($risk->side === 'short' && !$stop->isGreaterThan($entry))) {
            throw new CanonicalOrderPlanException('canonical_protection_stop_polarity_invalid');
        }
        $riskDistance = $entry->minus($stop)->abs();
        if ($riskDistance->isZero()) {
            throw new CanonicalOrderPlanException('canonical_protection_stop_polarity_invalid');
        }

        $targets = [];
        foreach ($policy->targets as $targetPolicy) {
            $reward = $riskDistance->multipliedBy(self::decimal($targetPolicy->riskMultiple));
            $rawTarget = $risk->side === 'long' ? $entry->plus($reward) : $entry->minus($reward);
            $target = self::quantize($rawTarget, $tick, $risk->side === 'long' ? RoundingMode::FLOOR : RoundingMode::CEILING);
            if (
                $target->isLessThanOrEqualTo(BigDecimal::zero())
                || ($risk->side === 'long' && !$target->isGreaterThan($entry))
                || ($risk->side === 'short' && !$target->isLessThan($entry))
            ) {
                throw new CanonicalOrderPlanException('canonical_protection_target_polarity_invalid', ['target_id' => $targetPolicy->id]);
            }
            $targets[] = new CanonicalProtectionTarget(
                $targetPolicy->id,
                $target->toFloat(),
                $targetPolicy->riskMultiple,
                $targetPolicy->liquidityRole,
            );
        }

        return new CanonicalProtectionDecision(
            modeId: $risk->modeId,
            modeVersion: $risk->modeVersion,
            setupId: $risk->setupId,
            setupVersion: $risk->setupVersion,
            exchange: $risk->exchange,
            environment: $risk->environment,
            side: $risk->side,
            symbol: $zone->symbol,
            entryPrice: $entry->toFloat(),
            stopPrice: $stop->toFloat(),
            riskDistance: $riskDistance->toFloat(),
            targets: $targets,
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
        if ($input->exchange !== $policy->riskPolicy->exchange || $input->symbol !== $zone->symbol) {
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
        if (($zone->computedAt->getTimestamp() - $input->observedAt->getTimestamp()) > $policy->entryZone->maximumInputAgeSeconds) {
            throw new CanonicalOrderPlanException('canonical_protection_input_stale');
        }
    }

    private static function positive(float $value, string $reasonCode): void
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
    }

    private static function decimal(float $value): BigDecimal
    {
        return BigDecimal::of((string) $value);
    }

    private static function quantize(BigDecimal $value, BigDecimal $tick, int $roundingMode): BigDecimal
    {
        return $value->dividedBy($tick, 0, $roundingMode)->multipliedBy($tick);
    }
}
