<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Value-only cost arithmetic; callers remain responsible for provenance and policy gates. */
final class NetRCostMath
{
    /** @return array<string, BigDecimal> */
    public static function risk(
        string $side,
        float $entryPrice,
        float $stopPrice,
        float $quantity,
        float $contractSize,
        float $entryFeeRate,
        float $stopFeeRate,
        float $entrySpreadRate,
        float $stopSpreadRate,
        float $entrySlippageRate,
        float $stopSlippageRate,
        float $fundingRate,
        int $fundingIntervals,
    ): array {
        if (!\in_array($side, ['long', 'short'], true)
            || !\is_finite($entryPrice) || $entryPrice <= 0.0
            || !\is_finite($stopPrice) || $stopPrice <= 0.0
            || !\is_finite($quantity) || $quantity <= 0.0
            || !\is_finite($contractSize) || $contractSize <= 0.0
            || $fundingIntervals <= 0
            || !\is_finite($fundingRate) || $fundingRate <= -1.0 || $fundingRate >= 1.0) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_invalid');
        }
        foreach ([$entryFeeRate, $stopFeeRate, $entrySpreadRate, $stopSpreadRate, $entrySlippageRate, $stopSlippageRate] as $rate) {
            self::rate($rate);
        }

        $entry = self::decimal($entryPrice);
        $stop = self::decimal($stopPrice);
        $quantityValue = self::decimal($quantity);
        $contractSizeValue = self::decimal($contractSize);
        $entryNotional = $entry->multipliedBy($contractSizeValue)->multipliedBy($quantityValue);
        $stopNotional = $stop->multipliedBy($contractSizeValue)->multipliedBy($quantityValue);
        $grossRisk = $entry->minus($stop)->abs()->multipliedBy($contractSizeValue)->multipliedBy($quantityValue);
        $entryFee = $entryNotional->multipliedBy(self::decimal($entryFeeRate));
        $stopFee = $stopNotional->multipliedBy(self::decimal($stopFeeRate));
        $entrySpread = $entryNotional->multipliedBy(self::decimal($entrySpreadRate));
        $stopSpread = $stopNotional->multipliedBy(self::decimal($stopSpreadRate));
        $entrySlippage = $entryNotional->multipliedBy(self::decimal($entrySlippageRate));
        $stopSlippage = $stopNotional->multipliedBy(self::decimal($stopSlippageRate));
        $adverseFundingRate = $side === 'long' ? max(0.0, $fundingRate) : max(0.0, -$fundingRate);
        $funding = $entryNotional
            ->multipliedBy(self::decimal($adverseFundingRate))
            ->multipliedBy((string) $fundingIntervals);
        $netRisk = $grossRisk
            ->plus($entryFee)
            ->plus($stopFee)
            ->plus($entrySpread)
            ->plus($stopSpread)
            ->plus($entrySlippage)
            ->plus($stopSlippage)
            ->plus($funding);
        if ($netRisk->isLessThanOrEqualTo(0)) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_invalid');
        }

        return [
            'gross_risk' => $grossRisk,
            'entry_fee' => $entryFee,
            'stop_fee' => $stopFee,
            'entry_spread' => $entrySpread,
            'stop_spread' => $stopSpread,
            'entry_slippage' => $entrySlippage,
            'stop_slippage' => $stopSlippage,
            'funding' => $funding,
            'net_risk' => $netRisk,
        ];
    }

    /**
     * @param array<string, BigDecimal> $risk
     * @return array<string, BigDecimal>
     */
    public static function target(
        float $entryPrice,
        float $targetPrice,
        float $quantity,
        float $contractSize,
        float $targetFeeRate,
        float $targetSpreadRate,
        float $targetSlippageRate,
        array $risk,
    ): array {
        foreach ([$entryPrice, $targetPrice, $quantity, $contractSize] as $positive) {
            if (!\is_finite($positive) || $positive <= 0.0) {
                throw new CanonicalOrderPlanException('canonical_net_r_risk_invalid');
            }
        }
        foreach ([$targetFeeRate, $targetSpreadRate, $targetSlippageRate] as $rate) {
            self::rate($rate);
        }
        foreach (['entry_fee', 'entry_spread', 'entry_slippage', 'funding', 'net_risk'] as $component) {
            if (!isset($risk[$component]) || !$risk[$component] instanceof BigDecimal) {
                throw new CanonicalOrderPlanException('canonical_net_r_risk_invalid');
            }
        }
        if ($risk['net_risk']->isLessThanOrEqualTo(0)) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_invalid');
        }
        foreach (['entry_fee', 'entry_spread', 'entry_slippage', 'funding'] as $component) {
            if ($risk[$component]->isLessThan(0)) {
                throw new CanonicalOrderPlanException('canonical_net_r_cost_invalid');
            }
        }
        $entry = self::decimal($entryPrice);
        $targetPriceValue = self::decimal($targetPrice);
        $quantityValue = self::decimal($quantity);
        $contractSizeValue = self::decimal($contractSize);
        $targetNotional = $targetPriceValue->multipliedBy($contractSizeValue)->multipliedBy($quantityValue);
        $grossReward = $targetPriceValue->minus($entry)->abs()->multipliedBy($contractSizeValue)->multipliedBy($quantityValue);
        $targetFee = $targetNotional->multipliedBy(self::decimal($targetFeeRate));
        $targetSpread = $targetNotional->multipliedBy(self::decimal($targetSpreadRate));
        $targetSlippage = $targetNotional->multipliedBy(self::decimal($targetSlippageRate));
        $netReward = $grossReward
            ->minus($risk['entry_fee'])
            ->minus($targetFee)
            ->minus($risk['entry_spread'])
            ->minus($risk['entry_slippage'])
            ->minus($targetSpread)
            ->minus($targetSlippage)
            ->minus($risk['funding']);
        $netR = $netReward->dividedBy($risk['net_risk'], 18, RoundingMode::DOWN);

        return [
            'gross_reward' => $grossReward,
            'target_fee' => $targetFee,
            'target_spread' => $targetSpread,
            'target_slippage' => $targetSlippage,
            'net_reward' => $netReward,
            'net_r' => $netR,
        ];
    }

    private static function rate(float $rate): void
    {
        if (!\is_finite($rate) || $rate < 0.0 || $rate >= 1.0) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_invalid');
        }
    }

    private static function decimal(float $value): BigDecimal
    {
        return CanonicalOrderPlanDecimal::fromFloat($value, 'canonical_net_r_value_invalid');
    }
}
