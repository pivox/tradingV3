<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Psr\Clock\ClockInterface;

final readonly class CanonicalEntryZoneEngine
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function calculate(CanonicalEntryZoneRequest $request): CanonicalEntryZone
    {
        return self::calculateAt($request, $this->clock->now());
    }

    public static function calculateAt(CanonicalEntryZoneRequest $request, \DateTimeImmutable $now): CanonicalEntryZone
    {
        $policy = $request->policy;
        $zonePolicy = $policy->entryZone;
        $risk = $policy->riskPolicy;
        if (preg_match('/\A[A-Z0-9][A-Z0-9_.-]*\z/D', $request->symbol) !== 1) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_market_identity_mismatch');
        }
        if (
            ($policy->allowedSymbols !== [] && !\in_array($request->symbol, $policy->allowedSymbols, true))
            || ($policy->allowedMarkets !== [] && !\in_array($request->market->marketType, $policy->allowedMarkets, true))
        ) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_environment_scope_mismatch');
        }
        if (
            $request->anchor->exchange !== $risk->exchange
            || $request->atr->exchange !== $risk->exchange
            || $request->market->exchange !== $risk->exchange
            || $request->tick->exchange !== $risk->exchange
            || $request->anchor->environment !== $risk->environment
            || $request->atr->environment !== $risk->environment
            || $request->market->environment !== $risk->environment
            || $request->tick->environment !== $risk->environment
            || $request->anchor->symbol !== $request->symbol
            || $request->atr->symbol !== $request->symbol
            || $request->market->symbol !== $request->symbol
            || $request->tick->symbol !== $request->symbol
            || $request->market->source !== $policy->costContract->entrySpreadSource
        ) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_market_identity_mismatch');
        }
        if ($request->anchor->source !== $zonePolicy->anchorSource || $request->anchor->timeframe !== $zonePolicy->anchorTimeframe) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_anchor_mismatch');
        }
        if ($request->atr->source !== 'atr' || $request->atr->timeframe !== $zonePolicy->atrTimeframe) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_atr_mismatch');
        }

        self::validatePositive($request->anchor->value, 'canonical_entry_zone_anchor_invalid');
        self::validatePositive($request->atr->value, 'canonical_entry_zone_atr_invalid');
        self::validatePositive($request->market->candidatePrice, 'canonical_entry_zone_candidate_invalid');
        self::validatePositive($request->tick->tickSize, 'canonical_entry_zone_tick_invalid');
        $observedAt = $request->anchor->observedAt;
        foreach ([$request->anchor, $request->atr, $request->market, $request->tick] as $input) {
            self::validateInputHash($input->inputHash);
            if ($input->observedAt > $now) {
                throw new CanonicalOrderPlanException('canonical_entry_zone_input_future');
            }
            if (CanonicalOrderPlanTime::isOlderThan($input->observedAt, $now, $zonePolicy->maximumInputAgeSeconds)) {
                throw new CanonicalOrderPlanException('canonical_entry_zone_input_stale');
            }
            if ($input->observedAt > $observedAt) {
                $observedAt = $input->observedAt;
            }
        }

        $anchor = self::decimal($request->anchor->value);
        $atr = self::decimal($request->atr->value);
        $tick = self::decimal($request->tick->tickSize);
        $halfWidth = $atr->multipliedBy(self::decimal($zonePolicy->atrMultiplier));
        $minimum = $anchor->multipliedBy(self::decimal($zonePolicy->minimumHalfWidthRate));
        $maximum = $anchor->multipliedBy(self::decimal($zonePolicy->maximumHalfWidthRate));
        if ($halfWidth->isLessThan($minimum)) {
            $halfWidth = $minimum;
        }
        if ($halfWidth->isGreaterThan($maximum)) {
            $halfWidth = $maximum;
        }

        $signedAsymmetry = $risk->side === 'long' ? $zonePolicy->asymmetryRate : -$zonePolicy->asymmetryRate;
        $lowerWidth = $halfWidth->multipliedBy(BigDecimal::one()->plus(self::decimal($signedAsymmetry)));
        $upperWidth = $halfWidth->multipliedBy(BigDecimal::one()->minus(self::decimal($signedAsymmetry)));
        $lower = self::quantize($anchor->minus($lowerWidth), $tick, RoundingMode::FLOOR);
        $upper = self::quantize($anchor->plus($upperWidth), $tick, RoundingMode::CEILING);
        if ($lower->isLessThanOrEqualTo(BigDecimal::zero()) || !$upper->isGreaterThan($lower)) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_bounds_invalid');
        }
        $entry = self::quantize(
            self::decimal($request->market->candidatePrice),
            $tick,
            $risk->side === 'long' ? RoundingMode::CEILING : RoundingMode::FLOOR,
        );
        if ($entry->isLessThan($lower) || $entry->isGreaterThan($upper)) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_candidate_outside');
        }

        return new CanonicalEntryZone(
            modeId: $risk->modeId,
            modeVersion: $risk->modeVersion,
            setupId: $risk->setupId,
            setupVersion: $risk->setupVersion,
            exchange: $risk->exchange,
            environment: $risk->environment,
            side: $risk->side,
            symbol: $request->symbol,
            marketType: $request->market->marketType,
            lowerPrice: $lower->toFloat(),
            upperPrice: $upper->toFloat(),
            entryPrice: $entry->toFloat(),
            tickSize: $request->tick->tickSize,
            anchorSource: $zonePolicy->anchorSource,
            anchorTimeframe: $zonePolicy->anchorTimeframe,
            atrTimeframe: $zonePolicy->atrTimeframe,
            observedAt: $observedAt,
            computedAt: $now,
            expiresAt: $now->modify(sprintf('+%d seconds', $zonePolicy->ttlSeconds)),
            configHash: $policy->configHash,
            inputHashes: [
                $request->anchor->inputHash,
                $request->atr->inputHash,
                $request->market->inputHash,
                $request->tick->inputHash,
            ],
        );
    }

    private static function validatePositive(float $value, string $reasonCode): void
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
    }

    private static function validateInputHash(string $hash): void
    {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_input_hash_invalid');
        }
    }

    private static function decimal(float $value): BigDecimal
    {
        return CanonicalOrderPlanDecimal::fromFloat($value, 'canonical_entry_zone_value_invalid');
    }

    private static function quantize(BigDecimal $value, BigDecimal $tick, int $roundingMode): BigDecimal
    {
        return $value->dividedBy($tick, 0, $roundingMode)->multipliedBy($tick);
    }
}
