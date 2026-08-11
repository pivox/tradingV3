<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Psr\Clock\ClockInterface;

final readonly class CanonicalOrderPlanValidator
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function validate(CanonicalOrderPlan $plan): CanonicalOrderPlan
    {
        return self::validateAt($plan, $this->clock->now());
    }

    public static function validateAt(CanonicalOrderPlan $plan, \DateTimeImmutable $now): CanonicalOrderPlan
    {
        if (!hash_equals($plan->expectedPlanHash(), $plan->planHash)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_hash_mismatch');
        }
        if ($plan->orderType !== 'limit') {
            throw new CanonicalOrderPlanException('canonical_order_plan_type_invalid');
        }
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $plan->marketType) !== 1) {
            throw new CanonicalOrderPlanException('canonical_order_plan_market_type_invalid');
        }
        if ($plan->zoneComputedAt > $plan->createdAt || $plan->createdAt > $now || $plan->expiresAt < $now) {
            throw new CanonicalOrderPlanException('canonical_order_plan_expired');
        }
        if ($plan->modeId === 'day_trading' && $plan->modeVersion === '1.1.0') {
            $expectedHoldingExpiry = CanonicalHoldingBoundary::expiresAt($plan->createdAt, 28_800, [
                'maximum_duration' => 'PT8H',
                'daily_boundary_time' => '00:00:00',
                'daily_boundary_timezone' => 'UTC',
                'close_before_boundary' => true,
            ]);
            if (
                !$plan->cancelAfterAt instanceof \DateTimeImmutable
                || !$plan->holdingExpiresAt instanceof \DateTimeImmutable
                || $plan->expiresAt > $plan->createdAt->modify('+90 seconds')
                || $plan->cancelAfterAt > $plan->createdAt->modify('+120 seconds')
                || $plan->cancelAfterAt < $plan->expiresAt
                || $plan->holdingExpiresAt != $expectedHoldingExpiry
            ) {
                throw new CanonicalOrderPlanException('canonical_order_plan_order_deadline_invalid');
            }
        } elseif ($plan->cancelAfterAt !== null || $plan->holdingExpiresAt !== null) {
            throw new CanonicalOrderPlanException('canonical_order_plan_order_deadline_invalid');
        }
        if (
            $plan->maximumInputAgeSeconds <= 0
            || $plan->costObservedAt > $plan->createdAt
            || $plan->costObservedAt > $now
            || CanonicalOrderPlanTime::isOlderThan($plan->costObservedAt, $now, $plan->maximumInputAgeSeconds)
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_cost_stale');
        }
        if (
            $plan->inputObservedAt > $plan->createdAt
            || $plan->inputObservedAt > $now
            || CanonicalOrderPlanTime::isOlderThan($plan->inputObservedAt, $now, $plan->maximumInputAgeSeconds)
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_input_stale');
        }
        foreach ([
            $plan->quantity,
            $plan->quantityStep,
            $plan->contractSize,
            $plan->entryPrice,
            $plan->stopPrice,
            $plan->tickSize,
            $plan->zoneLowerPrice,
            $plan->zoneUpperPrice,
            $plan->positionNotional,
            $plan->grossStopLoss,
            $plan->equityQuote,
            $plan->availableBalanceQuote,
            $plan->riskRate,
            $plan->modeLeverageCap,
            $plan->exchangeLeverageCap,
            $plan->minQuantity,
            $plan->maxQuantity,
            $plan->exchangeMinNotional,
            $plan->exchangeMaxNotional,
            $plan->environmentMaxNotional,
        ] as $value) {
            if (!\is_finite($value) || $value <= 0.0) {
                throw new CanonicalOrderPlanException('canonical_order_plan_value_invalid');
            }
        }
        if (
            $plan->zoneLowerPrice > $plan->zoneUpperPrice
            || $plan->entryPrice < $plan->zoneLowerPrice
            || $plan->entryPrice > $plan->zoneUpperPrice
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_entry_outside_zone');
        }
        if (
            ($plan->side === 'long' && $plan->stopPrice >= $plan->entryPrice)
            || ($plan->side === 'short' && $plan->stopPrice <= $plan->entryPrice)
            || !\in_array($plan->side, ['long', 'short'], true)
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_stop_polarity_invalid');
        }
        if (!self::aligned($plan->quantity, $plan->quantityStep)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_quantity_step_invalid');
        }
        foreach ([$plan->entryPrice, $plan->stopPrice, $plan->zoneLowerPrice, $plan->zoneUpperPrice] as $price) {
            if (!self::aligned($price, $plan->tickSize)) {
                throw new CanonicalOrderPlanException('canonical_order_plan_price_tick_invalid');
            }
        }
        $quantity = self::decimal($plan->quantity);
        $contractSize = self::decimal($plan->contractSize);
        $entry = self::decimal($plan->entryPrice);
        $stop = self::decimal($plan->stopPrice);
        $expectedNotional = $entry->multipliedBy($contractSize)->multipliedBy($quantity);
        $stopNotional = $stop->multipliedBy($contractSize)->multipliedBy($quantity);
        $expectedRiskBudget = self::decimal($plan->equityQuote)->multipliedBy(self::decimal($plan->riskRate));
        $expectedGrossStopLoss = $entry->minus($stop)->abs()->multipliedBy($contractSize)->multipliedBy($quantity);
        $expectedEntryFee = $expectedNotional->multipliedBy(self::decimal(self::feeRate($plan->entryLiquidityRole, $plan)));
        $expectedStopFee = $stopNotional->multipliedBy(self::decimal(self::feeRate($plan->stopLiquidityRole, $plan)));
        $expectedEntrySpread = $expectedNotional->multipliedBy(self::decimal($plan->entrySpreadRate));
        $expectedStopSpread = $stopNotional->multipliedBy(self::decimal($plan->stopSpreadRate));
        $expectedEntrySlippage = $expectedNotional->multipliedBy(self::decimal($plan->entrySlippageRate));
        $expectedStopSlippage = $stopNotional->multipliedBy(self::decimal($plan->stopSlippageRate));
        $expectedFunding = $expectedNotional
            ->multipliedBy(self::decimal(self::adverseFundingRate($plan->side, $plan->fundingRate)))
            ->multipliedBy((string) $plan->fundingIntervals);
        $expectedStopLoss = $expectedGrossStopLoss
            ->plus($expectedEntryFee)
            ->plus($expectedStopFee)
            ->plus($expectedEntrySpread)
            ->plus($expectedStopSpread)
            ->plus($expectedEntrySlippage)
            ->plus($expectedStopSlippage)
            ->plus($expectedFunding);
        if ($expectedNotional->toFloat() !== $plan->positionNotional) {
            throw new CanonicalOrderPlanException('canonical_order_plan_notional_mismatch');
        }
        if (
            $expectedRiskBudget->toFloat() !== $plan->riskBudgetQuote
            || $expectedGrossStopLoss->toFloat() !== $plan->grossStopLoss
            || $expectedEntryFee->toFloat() !== $plan->entryFee
            || $expectedStopFee->toFloat() !== $plan->stopExitFee
            || $expectedEntrySpread->toFloat() !== $plan->entrySpreadCost
            || $expectedStopSpread->toFloat() !== $plan->stopSpreadCost
            || $expectedEntrySlippage->toFloat() !== $plan->entrySlippageCost
            || $expectedStopSlippage->toFloat() !== $plan->stopSlippageCost
            || $expectedFunding->toFloat() !== $plan->fundingCost
            || $expectedStopLoss->toFloat() !== $plan->totalStopLoss
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_risk_components_mismatch');
        }
        if ($expectedStopLoss->isLessThanOrEqualTo(BigDecimal::zero()) || $expectedStopLoss->isGreaterThan($expectedRiskBudget)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_risk_budget_breach');
        }
        $leverageCaps = [$plan->modeLeverageCap, $plan->exchangeLeverageCap];
        if ($plan->symbolLeverageCap !== null) {
            $leverageCaps[] = $plan->symbolLeverageCap;
        }
        $expectedLeverageCap = (int) floor(min($leverageCaps));
        $expectedLeverage = max(1, $expectedNotional->dividedBy(
            self::decimal($plan->availableBalanceQuote),
            0,
            RoundingMode::CEILING,
        )->toInt());
        if (
            $plan->finalLeverage < 1
            || $plan->effectiveLeverageCap < 1
            || $plan->effectiveLeverageCap !== $expectedLeverageCap
            || $plan->finalLeverage !== $expectedLeverage
            || $plan->finalLeverage > $plan->effectiveLeverageCap
            || $plan->finalLeverage > $plan->modeLeverageCap
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_leverage_breach');
        }
        if (
            $plan->quantity < $plan->minQuantity
            || $plan->quantity > $plan->maxQuantity
            || ($plan->marketMaxQuantity !== null && $plan->quantity > $plan->marketMaxQuantity)
            || $plan->positionNotional < $plan->exchangeMinNotional
            || $plan->positionNotional > $plan->exchangeMaxNotional
            || $plan->positionNotional > $plan->environmentMaxNotional
            || $expectedNotional->isGreaterThan(self::decimal($plan->availableBalanceQuote)->multipliedBy((string) $expectedLeverageCap))
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_notional_breach');
        }
        $ids = [];
        foreach ($plan->targets as $target) {
            if (
                isset($ids[$target->id])
                || !\is_finite($target->price)
                || !\is_finite($target->netR)
                || $target->netR < $plan->minimumNetR
                || ($plan->side === 'long' && $target->price <= $plan->entryPrice)
                || ($plan->side === 'short' && $target->price >= $plan->entryPrice)
                || $target->netRisk !== $plan->totalStopLoss
            ) {
                throw new CanonicalOrderPlanException('canonical_order_plan_target_invalid');
            }
            if (!self::aligned($target->price, $plan->tickSize)) {
                throw new CanonicalOrderPlanException('canonical_order_plan_price_tick_invalid');
            }
            $expectedGrossReward = self::decimal($target->price)
                ->minus($entry)
                ->abs()
                ->multipliedBy($contractSize)
                ->multipliedBy($quantity);
            $targetNotional = self::decimal($target->price)->multipliedBy($contractSize)->multipliedBy($quantity);
            $expectedTargetFee = $targetNotional->multipliedBy(self::decimal(self::feeRate($target->liquidityRole, $plan)));
            $expectedTargetSpread = $targetNotional->multipliedBy(self::decimal($target->spreadRate));
            $expectedTargetSlippage = $targetNotional->multipliedBy(self::decimal($target->slippageRate));
            $expectedNetReward = $expectedGrossReward
                ->minus($expectedEntryFee)
                ->minus($expectedTargetFee)
                ->minus($expectedEntrySpread)
                ->minus($expectedEntrySlippage)
                ->minus($expectedTargetSpread)
                ->minus($expectedTargetSlippage)
                ->minus($expectedFunding);
            $expectedNetR = $expectedNetReward->dividedBy($expectedStopLoss, 18, RoundingMode::DOWN);
            if (
                $expectedGrossReward->toFloat() !== $target->grossReward
                || $expectedEntryFee->toFloat() !== $target->entryFee
                || $expectedTargetFee->toFloat() !== $target->targetFee
                || $expectedEntrySpread->toFloat() !== $target->entrySpreadCost
                || $expectedEntrySlippage->toFloat() !== $target->entrySlippageCost
                || $expectedTargetSpread->toFloat() !== $target->targetSpreadCost
                || $expectedTargetSlippage->toFloat() !== $target->targetSlippageCost
                || $expectedFunding->toFloat() !== $target->fundingCost
                || $expectedNetReward->toFloat() !== $target->netReward
                || $expectedStopLoss->toFloat() !== $target->netRisk
                || $expectedNetR->toFloat() !== $target->netR
            ) {
                throw new CanonicalOrderPlanException('canonical_order_plan_target_cost_mismatch');
            }
            $ids[$target->id] = true;
        }
        if (count(array_unique($plan->inputHashes)) !== count($plan->inputHashes) || !\in_array($plan->costInputHash, $plan->inputHashes, true)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_lineage_invalid');
        }
        foreach ([$plan->configHash, $plan->costInputHash, ...$plan->inputHashes] as $hash) {
            if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $hash) !== 1) {
                throw new CanonicalOrderPlanException('canonical_order_plan_lineage_invalid');
            }
        }

        return $plan;
    }

    private static function decimal(float $value): BigDecimal
    {
        return CanonicalOrderPlanDecimal::fromFloat($value, 'canonical_order_plan_value_invalid');
    }

    private static function aligned(float $value, float $step): bool
    {
        return self::decimal($value)->remainder(self::decimal($step))->isZero();
    }

    private static function feeRate(string $role, CanonicalOrderPlan $plan): float
    {
        return match ($role) {
            'maker' => $plan->makerFeeRate,
            'taker' => $plan->takerFeeRate,
            default => throw new CanonicalOrderPlanException('canonical_order_plan_liquidity_role_invalid'),
        };
    }

    private static function adverseFundingRate(string $side, float $fundingRate): float
    {
        return $side === 'long' ? max(0.0, $fundingRate) : max(0.0, -$fundingRate);
    }
}
