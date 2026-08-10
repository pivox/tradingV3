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
            || $protection->modeId !== $policy->riskPolicy->modeId
            || $protection->modeVersion !== $policy->riskPolicy->modeVersion
            || $protection->setupId !== $policy->riskPolicy->setupId
            || $protection->setupVersion !== $policy->riskPolicy->setupVersion
            || $protection->exchange !== $policy->riskPolicy->exchange
            || $protection->environment !== $policy->riskPolicy->environment
            || $protection->side !== $policy->riskPolicy->side
            || $risk->policy->configHash !== $policy->configHash
            || $risk->symbol !== $protection->symbol
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

        $quantity = self::decimal($risk->quantity);
        $contractSize = self::decimal($risk->contractSize);
        $entry = self::decimal($protection->entryPrice);
        $stop = self::decimal($protection->stopPrice);
        $entryNotional = $entry->multipliedBy($contractSize)->multipliedBy($quantity);
        $stopNotional = $stop->multipliedBy($contractSize)->multipliedBy($quantity);
        $grossRisk = $entry->minus($stop)->abs()->multipliedBy($contractSize)->multipliedBy($quantity);
        $entryFee = $entryNotional->multipliedBy(self::decimal($this->feeRate($costs->entryLiquidityRole, $policy)));
        $stopFee = $stopNotional->multipliedBy(self::decimal($this->feeRate($costs->stopLiquidityRole, $policy)));
        $entrySpread = $entryNotional->multipliedBy(self::decimal($costs->entrySpreadRate));
        $stopSpread = $stopNotional->multipliedBy(self::decimal($costs->stopSpreadRate));
        $entrySlippage = $entryNotional->multipliedBy(self::decimal($costs->entrySlippageRate));
        $stopSlippage = $stopNotional->multipliedBy(self::decimal($costs->stopSlippageRate));
        $funding = $entryNotional
            ->multipliedBy(self::decimal($this->adverseFundingRate($protection->side, $costs->fundingRate)))
            ->multipliedBy((string) $fundingIntervals);
        $netRisk = $grossRisk
            ->plus($entryFee)
            ->plus($stopFee)
            ->plus($entrySpread)
            ->plus($stopSpread)
            ->plus($entrySlippage)
            ->plus($stopSlippage)
            ->plus($funding);
        if ($quantity->isLessThanOrEqualTo(0) || $contractSize->isLessThanOrEqualTo(0) || $netRisk->isLessThanOrEqualTo(0)) {
            throw new CanonicalOrderPlanException('canonical_net_r_risk_invalid');
        }
        if (
            $grossRisk->toFloat() !== $risk->grossStopLoss
            || $entryFee->toFloat() !== $risk->entryFee
            || $stopFee->toFloat() !== $risk->stopExitFee
            || $entrySpread->toFloat() !== $risk->entrySpreadCost
            || $stopSpread->toFloat() !== $risk->stopSpreadCost
            || $entrySlippage->toFloat() !== $risk->entrySlippageCost
            || $stopSlippage->toFloat() !== $risk->stopSlippageCost
            || $funding->toFloat() !== $risk->fundingCost
            || $netRisk->toFloat() !== $risk->totalStopLoss
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
            $targetPrice = self::decimal($target->price);
            $targetNotional = $targetPrice->multipliedBy($contractSize)->multipliedBy($quantity);
            $grossReward = $targetPrice->minus($entry)->abs()->multipliedBy($contractSize)->multipliedBy($quantity);
            $targetFee = $targetNotional->multipliedBy(self::decimal($this->feeRate($target->liquidityRole, $policy)));
            $targetSpread = $targetNotional->multipliedBy(self::decimal((float) $targetCost->spreadRate));
            $targetSlippage = $targetNotional->multipliedBy(self::decimal((float) $targetCost->slippageRate));
            $netReward = $grossReward
                ->minus($entryFee)
                ->minus($targetFee)
                ->minus($entrySpread)
                ->minus($entrySlippage)
                ->minus($targetSpread)
                ->minus($targetSlippage)
                ->minus($funding);
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
                entryFee: $entryFee->toFloat(),
                targetFee: $targetFee->toFloat(),
                entrySpreadCost: $entrySpread->toFloat(),
                entrySlippageCost: $entrySlippage->toFloat(),
                targetSpreadCost: $targetSpread->toFloat(),
                targetSlippageCost: $targetSlippage->toFloat(),
                fundingCost: $funding->toFloat(),
                netReward: $netReward->toFloat(),
                netRisk: $netRisk->toFloat(),
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

    private function adverseFundingRate(string $side, float $fundingRate): float
    {
        return $side === 'long' ? max(0.0, $fundingRate) : max(0.0, -$fundingRate);
    }

    private static function decimal(float $value): BigDecimal
    {
        return CanonicalOrderPlanDecimal::fromFloat($value, 'canonical_net_r_value_invalid');
    }
}
