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
        if (!hash_equals($plan->expectedPlanHash(), $plan->planHash)) {
            throw new CanonicalOrderPlanException('canonical_order_plan_hash_mismatch');
        }
        if ($plan->orderType !== 'limit') {
            throw new CanonicalOrderPlanException('canonical_order_plan_type_invalid');
        }
        $now = $this->clock->now();
        if ($plan->zoneComputedAt > $plan->createdAt || $plan->createdAt > $now || $plan->expiresAt < $now) {
            throw new CanonicalOrderPlanException('canonical_order_plan_expired');
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
            $plan->modeLeverageCap,
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
        if (
            !\is_finite($plan->totalStopLoss)
            || !\is_finite($plan->riskBudgetQuote)
            || $plan->totalStopLoss <= 0.0
            || $plan->totalStopLoss > $plan->riskBudgetQuote
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_risk_budget_breach');
        }
        $quantity = self::decimal($plan->quantity);
        $contractSize = self::decimal($plan->contractSize);
        $entry = self::decimal($plan->entryPrice);
        $expectedNotional = $entry->multipliedBy($contractSize)->multipliedBy($quantity);
        if (!$expectedNotional->isEqualTo(self::decimal($plan->positionNotional))) {
            throw new CanonicalOrderPlanException('canonical_order_plan_notional_mismatch');
        }
        $expectedStopLoss = self::decimal($plan->grossStopLoss)
            ->plus(self::decimal($plan->entryFee))
            ->plus(self::decimal($plan->stopExitFee))
            ->plus(self::decimal($plan->entrySpreadCost))
            ->plus(self::decimal($plan->stopSpreadCost))
            ->plus(self::decimal($plan->entrySlippageCost))
            ->plus(self::decimal($plan->stopSlippageCost))
            ->plus(self::decimal($plan->fundingCost));
        if (!$expectedStopLoss->isEqualTo(self::decimal($plan->totalStopLoss))) {
            throw new CanonicalOrderPlanException('canonical_order_plan_risk_components_mismatch');
        }
        if (
            $plan->finalLeverage < 1
            || $plan->effectiveLeverageCap < 1
            || $plan->finalLeverage > $plan->effectiveLeverageCap
            || $plan->finalLeverage > $plan->modeLeverageCap
        ) {
            throw new CanonicalOrderPlanException('canonical_order_plan_leverage_breach');
        }
        if ($plan->positionNotional > $plan->exchangeMaxNotional || $plan->positionNotional > $plan->environmentMaxNotional) {
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
            $expectedNetReward = $expectedGrossReward
                ->minus(self::decimal($target->entryFee))
                ->minus(self::decimal($target->targetFee))
                ->minus(self::decimal($target->entrySpreadCost))
                ->minus(self::decimal($target->entrySlippageCost))
                ->minus(self::decimal($target->targetSpreadCost))
                ->minus(self::decimal($target->targetSlippageCost))
                ->minus(self::decimal($target->fundingCost));
            $expectedNetR = $expectedNetReward->dividedBy(self::decimal($target->netRisk), 18, RoundingMode::DOWN);
            if (
                $expectedGrossReward->toFloat() !== $target->grossReward
                || $expectedNetReward->toFloat() !== $target->netReward
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
        return BigDecimal::of((string) $value);
    }

    private static function aligned(float $value, float $step): bool
    {
        return self::decimal($value)->remainder(self::decimal($step))->isZero();
    }
}
