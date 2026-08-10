<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CanonicalNetREngine
{
    public function calculate(CanonicalNetRRequest $request): CanonicalNetRDecision
    {
        $policy = $request->policy;
        $protection = $request->protection;
        $risk = $request->riskDecision;
        $costs = $request->costs;
        if (
            $protection->configHash !== $policy->configHash
            || $risk->policy->configHash !== $policy->configHash
            || $risk->symbol !== $protection->symbol
            || $risk->side !== $protection->side
            || $risk->entryPrice !== $protection->entryPrice
            || $risk->stopPrice !== $protection->stopPrice
        ) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_identity_mismatch');
        }

        $this->validateCostSources($policy, $costs);
        $fundingIntervals = intdiv(
            $policy->holdingWindowSeconds + $policy->costContract->fundingIntervalSeconds - 1,
            $policy->costContract->fundingIntervalSeconds,
        );
        $riskCosts = $risk->costs;
        if (
            $riskCosts->entryLiquidityRole !== $costs->entryLiquidityRole
            || $riskCosts->stopLiquidityRole !== $costs->stopLiquidityRole
            || $riskCosts->entrySpreadRate !== $costs->entrySpreadRate
            || $riskCosts->entrySlippageRate !== $costs->entrySlippageRate
            || $riskCosts->stopSpreadRate !== $costs->stopSpreadRate
            || $riskCosts->stopSlippageRate !== $costs->stopSlippageRate
            || $riskCosts->fundingRate !== $costs->fundingRate
            || $riskCosts->fundingIntervals !== $fundingIntervals
        ) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_cost_mismatch');
        }
        if ($costs->observedAt > $protection->computedAt) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_future');
        }
        if (($protection->computedAt->getTimestamp() - $costs->observedAt->getTimestamp()) > $policy->entryZone->maximumInputAgeSeconds) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_stale');
        }

        $costsByTarget = [];
        foreach ($costs->targets as $targetCost) {
            if (isset($costsByTarget[$targetCost->targetId])) {
                throw new CanonicalOrderPlanException('canonical_net_r_target_cost_mismatch');
            }
            $costsByTarget[$targetCost->targetId] = $targetCost;
        }
        if (count($costsByTarget) !== count($protection->targets)) {
            throw new CanonicalOrderPlanException('canonical_net_r_target_cost_mismatch');
        }

        $quantity = self::decimal($risk->quantity);
        $contractSize = self::decimal($risk->contractSize);
        $entry = self::decimal($protection->entryPrice);
        $netRisk = self::decimal($risk->totalStopLoss);
        if ($quantity->isLessThanOrEqualTo(0) || $contractSize->isLessThanOrEqualTo(0) || $netRisk->isLessThanOrEqualTo(0)) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_invalid');
        }

        $decisions = [];
        foreach ($protection->targets as $target) {
            $targetCost = $costsByTarget[$target->id] ?? null;
            if (!$targetCost instanceof CanonicalTargetCostSnapshot) {
                throw new CanonicalOrderPlanException('canonical_net_r_target_cost_mismatch', ['target_id' => $target->id]);
            }
            if ($targetCost->spreadSource !== $policy->costContract->targetSpreadSource || $targetCost->slippageSource !== $policy->costContract->targetSlippageSource) {
                throw new CanonicalOrderPlanException('canonical_net_r_cost_source_mismatch', ['target_id' => $target->id]);
            }
            $targetPrice = self::decimal($target->price);
            $targetNotional = $targetPrice->multipliedBy($contractSize)->multipliedBy($quantity);
            $grossReward = $targetPrice->minus($entry)->abs()->multipliedBy($contractSize)->multipliedBy($quantity);
            $targetFee = $targetNotional->multipliedBy(self::decimal($this->feeRate($target->liquidityRole, $policy)));
            $targetSpread = $targetNotional->multipliedBy(self::decimal((float) $targetCost->spreadRate));
            $targetSlippage = $targetNotional->multipliedBy(self::decimal((float) $targetCost->slippageRate));
            $netReward = $grossReward
                ->minus(self::decimal($risk->entryFee))
                ->minus($targetFee)
                ->minus(self::decimal($risk->entrySpreadCost))
                ->minus(self::decimal($risk->entrySlippageCost))
                ->minus($targetSpread)
                ->minus($targetSlippage)
                ->minus(self::decimal($risk->fundingCost));
            $netR = $netReward->dividedBy($netRisk, 18, RoundingMode::DOWN);
            if ($netR->isLessThan(self::decimal($policy->minimumNetR))) {
                throw new CanonicalOrderPlanException('canonical_minimum_net_r_not_met', [
                    'target_id' => $target->id,
                    'net_r' => $netR->toFloat(),
                    'minimum_net_r' => $policy->minimumNetR,
                ]);
            }
            $decisions[] = new CanonicalNetRTargetDecision(
                id: $target->id,
                price: $target->price,
                grossReward: $grossReward->toFloat(),
                entryFee: $risk->entryFee,
                targetFee: $targetFee->toFloat(),
                entrySpreadCost: $risk->entrySpreadCost,
                entrySlippageCost: $risk->entrySlippageCost,
                targetSpreadCost: $targetSpread->toFloat(),
                targetSlippageCost: $targetSlippage->toFloat(),
                fundingCost: $risk->fundingCost,
                netReward: $netReward->toFloat(),
                netRisk: $risk->totalStopLoss,
                netR: $netR->toFloat(),
            );
        }

        return new CanonicalNetRDecision($decisions, $policy->minimumNetR, $fundingIntervals, $policy->configHash, $costs->inputHash);
    }

    private function validateCostSources(CanonicalExecutionPolicy $policy, CanonicalExecutionCostSnapshot $costs): void
    {
        if (
            $costs->entrySpreadSource !== $policy->costContract->entrySpreadSource
            || $costs->entrySlippageSource !== $policy->costContract->entrySlippageSource
            || $costs->stopSpreadSource !== $policy->costContract->stopSpreadSource
            || $costs->stopSlippageSource !== $policy->costContract->stopSlippageSource
            || $costs->fundingSource !== $policy->costContract->fundingSource
        ) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_source_mismatch');
        }
    }

    private function feeRate(string $role, CanonicalExecutionPolicy $policy): float
    {
        return match ($role) {
            'maker' => $policy->riskPolicy->makerFeeRate,
            'taker' => $policy->riskPolicy->takerFeeRate,
            default => throw new CanonicalOrderPlanException('canonical_net_r_liquidity_role_invalid'),
        };
    }

    private static function decimal(float $value): BigDecimal
    {
        if (!\is_finite($value)) {
            throw new CanonicalOrderPlanException('canonical_net_r_value_invalid');
        }

        return BigDecimal::of((string) $value);
    }
}
