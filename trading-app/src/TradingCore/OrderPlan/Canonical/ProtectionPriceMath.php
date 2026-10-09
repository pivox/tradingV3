<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Value-only stop and target arithmetic; it grants no execution authority. */
final class ProtectionPriceMath
{
    /**
     * @param list<CanonicalTargetPolicy> $targetPolicies
     * @return array{entry_price: float, stop_price: float, risk_distance: float, targets: non-empty-list<CanonicalProtectionTarget>}
     */
    public static function calculate(
        CanonicalStopPolicy $stopPolicy,
        array $targetPolicies,
        string $side,
        float $entryPrice,
        float $tickSize,
        float $stopValue,
    ): array {
        if (!\in_array($side, ['long', 'short'], true)) {
            throw new CanonicalOrderPlanException('canonical_protection_side_invalid');
        }
        self::positive($entryPrice, 'canonical_protection_entry_invalid');
        self::positive($tickSize, 'canonical_protection_tick_invalid');
        self::positive($stopValue, 'canonical_protection_input_invalid');
        if (!\in_array($stopPolicy->kind, ['atr', 'pivot'], true)
            || trim($stopPolicy->timeframe) === ''
            || !\is_finite($stopPolicy->bufferRate) || $stopPolicy->bufferRate < 0.0 || $stopPolicy->bufferRate >= 1.0
            || ($stopPolicy->kind === 'atr' && $stopPolicy->pivotId !== null)
            || ($stopPolicy->kind === 'pivot' && ($stopPolicy->atrMultiplier !== null || trim((string) $stopPolicy->pivotId) === ''))
        ) {
            throw new CanonicalOrderPlanException('canonical_stop_policy_invalid');
        }
        if ($stopPolicy->kind === 'atr' && ($stopPolicy->atrMultiplier === null
            || !\is_finite($stopPolicy->atrMultiplier) || $stopPolicy->atrMultiplier <= 0.0)) {
            throw new CanonicalOrderPlanException('canonical_protection_atr_required');
        }
        if ($targetPolicies === []) {
            throw new CanonicalOrderPlanException('canonical_target_policy_invalid');
        }
        $ids = [];
        $previousMultiple = 0.0;
        foreach ($targetPolicies as $targetPolicy) {
            if (!$targetPolicy instanceof CanonicalTargetPolicy
                || trim($targetPolicy->id) === ''
                || isset($ids[$targetPolicy->id])
                || !\is_finite($targetPolicy->riskMultiple) || $targetPolicy->riskMultiple <= $previousMultiple
                || !\in_array($targetPolicy->liquidityRole, ['maker', 'taker'], true)
            ) {
                throw new CanonicalOrderPlanException('canonical_target_policy_invalid');
            }
            $ids[$targetPolicy->id] = true;
            $previousMultiple = $targetPolicy->riskMultiple;
        }

        $entry = self::decimal($entryPrice);
        $tick = self::decimal($tickSize);
        $buffer = self::decimal($stopPolicy->bufferRate);
        if ($stopPolicy->kind === 'atr') {
            $distance = self::decimal($stopValue)
                ->multipliedBy(self::decimal($stopPolicy->atrMultiplier))
                ->plus($entry->multipliedBy($buffer));
            $rawStop = $side === 'long' ? $entry->minus($distance) : $entry->plus($distance);
        } else {
            $pivot = self::decimal($stopValue);
            $rawStop = $side === 'long'
                ? $pivot->multipliedBy(BigDecimal::one()->minus($buffer))
                : $pivot->multipliedBy(BigDecimal::one()->plus($buffer));
        }

        $stop = self::quantize($rawStop, $tick, $side === 'long' ? RoundingMode::FLOOR : RoundingMode::CEILING);
        if ($stop->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new CanonicalOrderPlanException('canonical_protection_stop_invalid');
        }
        if (($side === 'long' && !$stop->isLessThan($entry)) || ($side === 'short' && !$stop->isGreaterThan($entry))) {
            throw new CanonicalOrderPlanException('canonical_protection_stop_polarity_invalid');
        }
        $riskDistance = $entry->minus($stop)->abs();
        if ($riskDistance->isZero()) {
            throw new CanonicalOrderPlanException('canonical_protection_stop_polarity_invalid');
        }

        $targets = [];
        foreach ($targetPolicies as $targetPolicy) {
            $reward = $riskDistance->multipliedBy(self::decimal($targetPolicy->riskMultiple));
            $rawTarget = $side === 'long' ? $entry->plus($reward) : $entry->minus($reward);
            $target = self::quantize($rawTarget, $tick, $side === 'long' ? RoundingMode::FLOOR : RoundingMode::CEILING);
            if (
                $target->isLessThanOrEqualTo(BigDecimal::zero())
                || ($side === 'long' && !$target->isGreaterThan($entry))
                || ($side === 'short' && !$target->isLessThan($entry))
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

        return [
            'entry_price' => $entry->toFloat(),
            'stop_price' => $stop->toFloat(),
            'risk_distance' => $riskDistance->toFloat(),
            'targets' => $targets,
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
        return CanonicalOrderPlanDecimal::fromFloat($value, 'canonical_protection_value_invalid');
    }

    private static function quantize(BigDecimal $value, BigDecimal $tick, int $roundingMode): BigDecimal
    {
        return $value->dividedBy($tick, 0, $roundingMode)->multipliedBy($tick);
    }
}
