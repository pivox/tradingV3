<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Value-only entry geometry; market identity and observation freshness belong to the caller. */
final class EntryZonePriceMath
{
    /** @return array{lower_price:float,upper_price:float,entry_price:float} */
    public static function calculate(
        CanonicalEntryZonePolicy $policy,
        string $side,
        float $anchorPrice,
        float $atrValue,
        float $candidatePrice,
        float $tickSize,
    ): array {
        if (!\in_array($side, ['long', 'short'], true)) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_market_identity_mismatch');
        }
        self::positive($anchorPrice, 'canonical_entry_zone_anchor_invalid');
        self::positive($atrValue, 'canonical_entry_zone_atr_invalid');
        self::positive($candidatePrice, 'canonical_entry_zone_candidate_invalid');
        self::positive($tickSize, 'canonical_entry_zone_tick_invalid');
        if (!\is_finite($policy->atrMultiplier) || $policy->atrMultiplier <= 0.0
            || !\is_finite($policy->minimumHalfWidthRate) || $policy->minimumHalfWidthRate <= 0.0 || $policy->minimumHalfWidthRate >= 1.0
            || !\is_finite($policy->maximumHalfWidthRate) || $policy->maximumHalfWidthRate < $policy->minimumHalfWidthRate || $policy->maximumHalfWidthRate >= 1.0
            || !\is_finite($policy->asymmetryRate) || $policy->asymmetryRate < -0.95 || $policy->asymmetryRate > 0.95
            || !$policy->quantizeOutward
        ) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_policy_invalid');
        }

        $anchor = self::decimal($anchorPrice);
        $atr = self::decimal($atrValue);
        $tick = self::decimal($tickSize);
        $halfWidth = $atr->multipliedBy(self::decimal($policy->atrMultiplier));
        $minimum = $anchor->multipliedBy(self::decimal($policy->minimumHalfWidthRate));
        $maximum = $anchor->multipliedBy(self::decimal($policy->maximumHalfWidthRate));
        if ($halfWidth->isLessThan($minimum)) {
            $halfWidth = $minimum;
        }
        if ($halfWidth->isGreaterThan($maximum)) {
            $halfWidth = $maximum;
        }

        $signedAsymmetry = $side === 'long' ? $policy->asymmetryRate : -$policy->asymmetryRate;
        $lowerWidth = $halfWidth->multipliedBy(BigDecimal::one()->plus(self::decimal($signedAsymmetry)));
        $upperWidth = $halfWidth->multipliedBy(BigDecimal::one()->minus(self::decimal($signedAsymmetry)));
        $lower = self::quantize($anchor->minus($lowerWidth), $tick, RoundingMode::FLOOR);
        $upper = self::quantize($anchor->plus($upperWidth), $tick, RoundingMode::CEILING);
        if ($lower->isLessThanOrEqualTo(BigDecimal::zero()) || !$upper->isGreaterThan($lower)) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_bounds_invalid');
        }
        $entry = self::quantize(
            self::decimal($candidatePrice),
            $tick,
            $side === 'long' ? RoundingMode::CEILING : RoundingMode::FLOOR,
        );
        if ($entry->isLessThan($lower) || $entry->isGreaterThan($upper)) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_candidate_outside');
        }

        return [
            'lower_price' => $lower->toFloat(),
            'upper_price' => $upper->toFloat(),
            'entry_price' => $entry->toFloat(),
        ];
    }

    private static function positive(float $value, string $reasonCode): void
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new CanonicalOrderPlanException($reasonCode);
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
