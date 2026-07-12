<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidIsolatedLiquidationResult;
use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class HyperliquidIsolatedLiquidationSolver
{
    private const CALCULATION_SCALE = 72;
    private const RESULT_SCALE = 36;
    private const DTO_SCALE = 12;

    public function solve(
        HyperliquidMarginSafetyEvidence $evidence,
        string $entryPrice,
        string $quantity,
        int $leverage,
        string $side,
    ): HyperliquidIsolatedLiquidationResult {
        $entry = $this->positive($entryPrice);
        $size = $this->positive($quantity);
        if ($leverage < 1 || $leverage > 50 || !in_array($side, ['long', 'short'], true)) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_input_invalid');
        }
        $this->validateTableStructure($evidence);
        $openingTier = $this->tierForNotional($evidence, $entry->multipliedBy($size));
        if ($leverage > $openingTier->maxLeverage) {
            throw new \InvalidArgumentException('hyperliquid_opening_tier_leverage_invalid');
        }

        $matches = [];
        foreach ($evidence->tiers as $index => $tier) {
            $rate = $this->nonNegative($tier->maintenanceMarginRate);
            $deduction = $this->nonNegative($tier->maintenanceMarginDeduction);
            if ($rate->isGreaterThanOrEqualTo(BigDecimal::one())) {
                throw new \InvalidArgumentException('hyperliquid_liquidation_rate_invalid');
            }
            $factor = BigDecimal::of($side === 'long' ? $leverage - 1 : $leverage + 1)
                ->dividedBy($leverage, self::CALCULATION_SCALE, RoundingMode::HALF_UP);
            $deductionPerUnit = $deduction->dividedBy($size, self::CALCULATION_SCALE, RoundingMode::HALF_UP);
            $numerator = $side === 'long'
                ? $entry->multipliedBy($factor)->minus($deductionPerUnit)
                : $entry->multipliedBy($factor)->plus($deductionPerUnit);
            $denominator = $side === 'long' ? BigDecimal::one()->minus($rate) : BigDecimal::one()->plus($rate);
            $candidate = $numerator->dividedBy(
                $denominator,
                self::RESULT_SCALE,
                $side === 'long' ? RoundingMode::UP : RoundingMode::DOWN,
            );
            if ($candidate->isLessThanOrEqualTo(BigDecimal::zero())) {
                continue;
            }
            $candidateNotional = $candidate->multipliedBy($size)->abs();
            $next = $evidence->tiers[$index + 1] ?? null;
            $inTier = $candidateNotional->isGreaterThanOrEqualTo(BigDecimal::of($tier->lowerBound))
                && ($next === null || $candidateNotional->isLessThan(BigDecimal::of($next->lowerBound)));
            if ($inTier) {
                $matches[] = new HyperliquidIsolatedLiquidationResult(
                    $this->decimalString($candidate),
                    $index,
                    $tier->lowerBound,
                    $tier->maxLeverage,
                    $tier->maintenanceMarginRate,
                    $tier->maintenanceMarginDeduction,
                );
            }
        }
        if (count($matches) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_tier_solution_ambiguous');
        }

        return $matches[0];
    }

    public function toConservativeFloat(HyperliquidIsolatedLiquidationResult $result, string $side): float
    {
        if (!in_array($side, ['long', 'short'], true)) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_side_invalid');
        }
        // The legacy DTO is float-based: round toward entry, then advance one binary64 ULP in that direction.
        $decimal = $this->positive($result->liquidationPrice)->toScale(
            self::DTO_SCALE,
            $side === 'long' ? RoundingMode::UP : RoundingMode::DOWN,
        );
        $value = $this->oneUlpTowardEntry($decimal->toFloat(), $side);
        if (!is_finite($value) || $value <= 0.0) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_float_invalid');
        }
        return $value;
    }

    private function oneUlpTowardEntry(float $value, string $side): float
    {
        if (!is_finite($value) || $value <= 0.0) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_float_invalid');
        }
        $parts = unpack('Nhigh/Nlow', pack('E', $value));
        if (!is_array($parts) || !is_int($parts['high'] ?? null) || !is_int($parts['low'] ?? null)) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_float_bits_invalid');
        }
        $high = $parts['high'];
        $low = $parts['low'];
        if ($side === 'long') {
            if ($low === 0xffffffff) {
                ++$high;
                $low = 0;
            } else {
                ++$low;
            }
        } elseif ($low === 0) {
            --$high;
            $low = 0xffffffff;
        } else {
            --$low;
        }
        $next = unpack('Evalue', pack('N2', $high, $low));
        $moved = is_array($next) ? ($next['value'] ?? null) : null;
        if (!is_float($moved) || !is_finite($moved) || $moved <= 0.0
            || ($side === 'long' && $moved <= $value)
            || ($side === 'short' && $moved >= $value)
        ) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_float_invalid');
        }

        return $moved;
    }

    private function tierForNotional(HyperliquidMarginSafetyEvidence $evidence, BigDecimal $notional): \App\Provider\Hyperliquid\Dto\HyperliquidMarginTierEvidence
    {
        $selected = null;
        foreach ($evidence->tiers as $tier) {
            if ($notional->isGreaterThanOrEqualTo(BigDecimal::of($tier->lowerBound))) {
                $selected = $tier;
            }
        }

        return $selected ?? throw new \InvalidArgumentException('hyperliquid_opening_tier_missing');
    }

    private function validateTableStructure(HyperliquidMarginSafetyEvidence $evidence): void
    {
        if ($evidence->universeMaxLeverage < 1 || $evidence->universeMaxLeverage > 50
            || $evidence->tiers === [] || count($evidence->tiers) > 3
            || $evidence->tiers[0]->lowerBound !== '0'
            || $evidence->tiers[0]->maxLeverage !== $evidence->universeMaxLeverage
            || ($evidence->marginTableId < 50
                && ($evidence->marginTableId !== $evidence->universeMaxLeverage || count($evidence->tiers) !== 1))
        ) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_table_invalid');
        }
        $previousBound = null;
        $previousLeverage = null;
        foreach ($evidence->tiers as $tier) {
            $bound = $this->nonNegative($tier->lowerBound);
            if ($tier->maxLeverage < 1 || $tier->maxLeverage > 50
                || ($previousBound instanceof BigDecimal && $bound->isLessThanOrEqualTo($previousBound))
                || ($previousLeverage !== null && $tier->maxLeverage >= $previousLeverage)
            ) {
                throw new \InvalidArgumentException('hyperliquid_liquidation_table_invalid');
            }
            $previousBound = $bound;
            $previousLeverage = $tier->maxLeverage;
        }
    }

    private function positive(string $value): BigDecimal
    {
        $decimal = $this->nonNegative($value);
        if ($decimal->isZero()) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_decimal_invalid');
        }

        return $decimal;
    }

    private function nonNegative(string $value): BigDecimal
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_decimal_invalid');
        }
        $decimal = BigDecimal::of($value);
        if ($decimal->isNegative()) {
            throw new \InvalidArgumentException('hyperliquid_liquidation_decimal_invalid');
        }

        return $decimal;
    }

    private function decimalString(BigDecimal $value): string
    {
        $normalized = (string) $value;
        return str_contains($normalized, '.') ? rtrim(rtrim($normalized, '0'), '.') : $normalized;
    }
}
