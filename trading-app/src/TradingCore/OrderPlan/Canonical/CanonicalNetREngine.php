<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

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
            || $protection->modeId !== $policy->riskPolicy->modeId
            || $protection->modeVersion !== $policy->riskPolicy->modeVersion
            || $protection->setupId !== $policy->riskPolicy->setupId
            || $protection->setupVersion !== $policy->riskPolicy->setupVersion
            || $protection->exchange !== $policy->riskPolicy->exchange
            || $protection->environment !== $policy->riskPolicy->environment
            || $protection->side !== $policy->riskPolicy->side
            || $risk->policy->configHash !== $policy->configHash
            || $risk->symbol !== $protection->symbol
            || $risk->marketType !== $protection->marketType
            || $risk->side !== $protection->side
            || $risk->entryPrice !== $protection->entryPrice
            || $risk->stopPrice !== $protection->stopPrice
        ) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_identity_mismatch');
        }
        if (
            $costs->exchange !== $policy->riskPolicy->exchange
            || $costs->environment !== $policy->riskPolicy->environment
            || $costs->symbol !== $protection->symbol
            || $costs->marketType !== $protection->marketType
            || $costs->configHash !== $policy->configHash
        ) {
            throw new CanonicalOrderPlanException('canonical_net_r_cost_identity_mismatch');
        }

        $this->validateCostSources($policy, $costs);
        $fundingIntervals = intdiv(
            $policy->holdingWindowSeconds - 1,
            $policy->costContract->fundingIntervalSeconds,
        ) + 1;
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
        if (CanonicalOrderPlanTime::isOlderThan($costs->observedAt, $protection->computedAt, $policy->entryZone->maximumInputAgeSeconds)) {
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

        $riskAmounts = NetRCostMath::risk(
            $protection->side, $protection->entryPrice, $protection->stopPrice,
            $risk->quantity, $risk->contractSize,
            $this->feeRate($costs->entryLiquidityRole, $policy),
            $this->feeRate($costs->stopLiquidityRole, $policy),
            (float) $costs->entrySpreadRate, (float) $costs->stopSpreadRate,
            (float) $costs->entrySlippageRate, (float) $costs->stopSlippageRate,
            (float) $costs->fundingRate, $fundingIntervals,
        );
        if (
            $riskAmounts['gross_risk']->toFloat() !== $risk->grossStopLoss
            || $riskAmounts['entry_fee']->toFloat() !== $risk->entryFee
            || $riskAmounts['stop_fee']->toFloat() !== $risk->stopExitFee
            || $riskAmounts['entry_spread']->toFloat() !== $risk->entrySpreadCost
            || $riskAmounts['stop_spread']->toFloat() !== $risk->stopSpreadCost
            || $riskAmounts['entry_slippage']->toFloat() !== $risk->entrySlippageCost
            || $riskAmounts['stop_slippage']->toFloat() !== $risk->stopSlippageCost
            || $riskAmounts['funding']->toFloat() !== $risk->fundingCost
            || $riskAmounts['net_risk']->toFloat() !== $risk->totalStopLoss
        ) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_cost_mismatch');
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
            $targetAmounts = NetRCostMath::target(
                $protection->entryPrice, $target->price, $risk->quantity, $risk->contractSize,
                $this->feeRate($target->liquidityRole, $policy),
                (float) $targetCost->spreadRate, (float) $targetCost->slippageRate,
                $riskAmounts,
            );
            if ($targetAmounts['net_r']->isLessThan(CanonicalOrderPlanDecimal::fromFloat($policy->minimumNetR, 'canonical_net_r_value_invalid'))) {
                throw new CanonicalOrderPlanException('canonical_minimum_net_r_not_met', [
                    'target_id' => $target->id,
                    'net_r' => $targetAmounts['net_r']->toFloat(),
                    'minimum_net_r' => $policy->minimumNetR,
                ]);
            }
            $decisions[] = new CanonicalNetRTargetDecision(
                id: $target->id,
                price: $target->price,
                grossReward: $targetAmounts['gross_reward']->toFloat(),
                entryFee: $riskAmounts['entry_fee']->toFloat(),
                targetFee: $targetAmounts['target_fee']->toFloat(),
                entrySpreadCost: $riskAmounts['entry_spread']->toFloat(),
                entrySlippageCost: $riskAmounts['entry_slippage']->toFloat(),
                targetSpreadCost: $targetAmounts['target_spread']->toFloat(),
                targetSlippageCost: $targetAmounts['target_slippage']->toFloat(),
                fundingCost: $riskAmounts['funding']->toFloat(),
                netReward: $targetAmounts['net_reward']->toFloat(),
                netRisk: $riskAmounts['net_risk']->toFloat(),
                netR: $targetAmounts['net_r']->toFloat(),
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
}
