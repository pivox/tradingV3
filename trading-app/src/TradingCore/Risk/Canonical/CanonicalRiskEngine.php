<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

final class CanonicalRiskEngine
{
    private const EPSILON = 1.0e-12;

    public function calculate(CanonicalRiskCalculationRequest $request): CanonicalRiskDecision
    {
        if (
            !\is_finite($request->policy->exchangeMinNotional)
            || $request->policy->exchangeMinNotional < 0.0
            || $request->policy->exchangeMinNotional > $request->policy->exchangeMaxNotional
        ) {
            throw new CanonicalRiskException('canonical_policy_notional_cap_invalid');
        }
        foreach ([$request->policy->exchangeMaxNotional, $request->policy->environmentMaxNotional] as $notionalCap) {
            if (!\is_finite($notionalCap) || $notionalCap <= 0.0) {
                throw new CanonicalRiskException('canonical_policy_notional_cap_invalid');
            }
        }
        if ($request->availableBalanceQuote <= 0.0) {
            throw new CanonicalRiskException('canonical_risk_available_balance_exhausted');
        }
        if (!\is_finite($request->policy->riskRate) || $request->policy->riskRate <= 0.0 || $request->policy->riskRate > 1.0) {
            throw new CanonicalRiskException('canonical_policy_trade_budget_value_invalid');
        }

        $effectiveLeverageCap = $this->effectiveLeverageCap($request);
        $riskBudgetQuote = $request->equityQuote * $request->policy->riskRate;
        if (!\is_finite($riskBudgetQuote) || $riskBudgetQuote <= 0.0) {
            throw new CanonicalRiskException('canonical_risk_budget_invalid');
        }

        $costs = $request->costs;
        $entryLiquidityRole = $costs->entryLiquidityRole ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $stopLiquidityRole = $costs->stopLiquidityRole ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $entrySpreadRate = $costs->entrySpreadRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $stopSpreadRate = $costs->stopSpreadRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $entrySlippageRate = $costs->entrySlippageRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $stopSlippageRate = $costs->stopSlippageRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $fundingRate = $costs->fundingRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $fundingIntervals = $costs->fundingIntervals ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $entryFeeRate = $this->feeRateFor($entryLiquidityRole, $request->policy);
        $stopExitFeeRate = $this->feeRateFor($stopLiquidityRole, $request->policy);

        $entryNotionalPerQuantity = $request->entryPrice * $request->contractSize;
        $stopNotionalPerQuantity = $request->stopPrice * $request->contractSize;
        $grossPerQuantity = abs($request->entryPrice - $request->stopPrice) * $request->contractSize;
        $costPerQuantity = $entryNotionalPerQuantity * (
            $entryFeeRate
            + $entrySpreadRate
            + $entrySlippageRate
            + $this->adverseFundingRate($request->side, $fundingRate) * $fundingIntervals
        ) + $stopNotionalPerQuantity * ($stopExitFeeRate + $stopSpreadRate + $stopSlippageRate);
        $lossPerQuantity = $grossPerQuantity + $costPerQuantity;
        if (!\is_finite($lossPerQuantity) || $lossPerQuantity <= 0.0) {
            throw new CanonicalRiskException('canonical_risk_stop_path_invalid');
        }

        $rawQuantity = $riskBudgetQuote / $lossPerQuantity;
        $quantityCaps = [
            $rawQuantity,
            $request->maxQuantity,
            $request->policy->exchangeMaxNotional / $entryNotionalPerQuantity,
            $request->policy->environmentMaxNotional / $entryNotionalPerQuantity,
            ($request->availableBalanceQuote * $effectiveLeverageCap) / $entryNotionalPerQuantity,
        ];
        if ($request->marketMaxQuantity !== null) {
            $quantityCaps[] = $request->marketMaxQuantity;
        }

        $quantity = $this->quantizeDown(min($quantityCaps), $request->quantityStep);
        foreach ($quantityCaps as $quantityCap) {
            if ($quantity > $quantityCap + self::EPSILON) {
                throw new CanonicalRiskException('canonical_risk_post_quantization_cap_breach');
            }
        }
        if ($quantity + self::EPSILON < $request->minQuantity) {
            throw new CanonicalRiskException('canonical_risk_quantity_below_minimum', [
                'raw_quantity' => $rawQuantity,
                'minimum_quantity' => $request->minQuantity,
            ]);
        }

        $components = $this->components($request, $quantity);
        if ($components['total'] > $riskBudgetQuote + self::EPSILON) {
            $quantity = $this->quantizeDown($quantity - $request->quantityStep, $request->quantityStep);
            if ($quantity + self::EPSILON < $request->minQuantity) {
                throw new CanonicalRiskException('canonical_risk_quantity_below_minimum');
            }
            $components = $this->components($request, $quantity);
        }
        if ($components['total'] > $riskBudgetQuote + self::EPSILON) {
            throw new CanonicalRiskException('canonical_risk_post_quantization_breach', [
                'risk_budget_quote' => $riskBudgetQuote,
                'total_stop_loss' => $components['total'],
            ]);
        }

        $positionNotional = $request->entryPrice * $request->contractSize * $quantity;
        if ($positionNotional + self::EPSILON < $request->policy->exchangeMinNotional) {
            throw new CanonicalRiskException('canonical_risk_notional_below_minimum', [
                'position_notional' => $positionNotional,
                'exchange_min_notional' => $request->policy->exchangeMinNotional,
            ]);
        }
        $finalLeverage = max(1, (int) ceil($positionNotional / $request->availableBalanceQuote - self::EPSILON));
        if ($finalLeverage > $effectiveLeverageCap) {
            throw new CanonicalRiskException('canonical_leverage_post_quantization_breach', [
                'final_leverage' => $finalLeverage,
                'effective_cap' => $effectiveLeverageCap,
            ]);
        }

        return new CanonicalRiskDecision(
            riskBudgetQuote: $riskBudgetQuote,
            quantity: $quantity,
            positionNotional: $positionNotional,
            finalLeverage: $finalLeverage,
            grossStopLoss: $components['gross'],
            entryFee: $components['entry_fee'],
            stopExitFee: $components['stop_exit_fee'],
            entrySpreadCost: $components['entry_spread'],
            stopSpreadCost: $components['stop_spread'],
            entrySlippageCost: $components['entry_slippage'],
            stopSlippageCost: $components['stop_slippage'],
            fundingCost: $components['funding'],
            totalStopLoss: $components['total'],
            rawQuantity: $rawQuantity,
            quantityStep: $request->quantityStep,
            capsApplied: $this->capsApplied($request),
            policy: $request->policy,
        );
    }

    private function effectiveLeverageCap(CanonicalRiskCalculationRequest $request): int
    {
        $caps = [
            $request->policy->modeLeverageCap,
            $request->exchangeLeverageCap,
        ];
        if ($request->symbolLeverageCap !== null) {
            $caps[] = $request->symbolLeverageCap;
        }
        foreach ($caps as $cap) {
            if (!\is_finite($cap) || $cap < 1.0) {
                throw new CanonicalRiskException('canonical_leverage_cap_invalid');
            }
        }

        $effectiveCap = (int) floor(min($caps));
        if ($effectiveCap < 1) {
            throw new CanonicalRiskException('canonical_leverage_cap_invalid');
        }

        return $effectiveCap;
    }

    /** @return array{gross:float,entry_fee:float,stop_exit_fee:float,entry_spread:float,stop_spread:float,entry_slippage:float,stop_slippage:float,funding:float,total:float} */
    private function components(CanonicalRiskCalculationRequest $request, float $quantity): array
    {
        $costs = $request->costs;
        $entryNotional = $request->entryPrice * $request->contractSize * $quantity;
        $stopNotional = $request->stopPrice * $request->contractSize * $quantity;
        $gross = abs($request->entryPrice - $request->stopPrice) * $request->contractSize * $quantity;
        $entryFee = $entryNotional * $this->feeRateFor((string) $costs->entryLiquidityRole, $request->policy);
        $stopExitFee = $stopNotional * $this->feeRateFor((string) $costs->stopLiquidityRole, $request->policy);
        $entrySpread = $entryNotional * (float) $costs->entrySpreadRate;
        $stopSpread = $stopNotional * (float) $costs->stopSpreadRate;
        $entrySlippage = $entryNotional * (float) $costs->entrySlippageRate;
        $stopSlippage = $stopNotional * (float) $costs->stopSlippageRate;
        $funding = $entryNotional
            * $this->adverseFundingRate($request->side, (float) $costs->fundingRate)
            * (int) $costs->fundingIntervals;

        return [
            'gross' => $gross,
            'entry_fee' => $entryFee,
            'stop_exit_fee' => $stopExitFee,
            'entry_spread' => $entrySpread,
            'stop_spread' => $stopSpread,
            'entry_slippage' => $entrySlippage,
            'stop_slippage' => $stopSlippage,
            'funding' => $funding,
            'total' => $gross + $entryFee + $stopExitFee
                + $entrySpread + $stopSpread + $entrySlippage + $stopSlippage + $funding,
        ];
    }

    private function quantizeDown(float $quantity, float $step): float
    {
        if (!\is_finite($quantity) || $quantity <= 0.0) {
            return 0.0;
        }

        $steps = floor($quantity / $step + self::EPSILON);
        return round($steps * $step, $this->decimalPlaces($step));
    }

    private function decimalPlaces(float $step): int
    {
        $normalized = rtrim(rtrim(sprintf('%.16F', $step), '0'), '.');
        $point = strpos($normalized, '.');

        return $point === false ? 0 : strlen($normalized) - $point - 1;
    }

    private function feeRateFor(string $liquidityRole, CanonicalRiskPolicy $policy): float
    {
        return match ($liquidityRole) {
            'maker' => $policy->makerFeeRate,
            'taker' => $policy->takerFeeRate,
            default => throw new CanonicalRiskException('canonical_market_liquidity_role_invalid'),
        };
    }

    private function adverseFundingRate(string $side, float $fundingRate): float
    {
        return $side === 'long' ? max(0.0, $fundingRate) : max(0.0, -$fundingRate);
    }

    /** @return list<string> */
    private function capsApplied(CanonicalRiskCalculationRequest $request): array
    {
        $caps = [
            'mode_leverage_cap',
            'exchange_leverage_cap',
            'max_quantity',
            'exchange_max_notional',
            'environment_max_notional',
        ];
        if ($request->symbolLeverageCap !== null) {
            $caps[] = 'symbol_leverage_cap';
        }
        if ($request->marketMaxQuantity !== null) {
            $caps[] = 'market_max_quantity';
        }

        return $caps;
    }
}
