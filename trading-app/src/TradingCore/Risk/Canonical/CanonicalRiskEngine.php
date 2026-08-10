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
        $entryFeeRate = $costs->entryFeeRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $stopExitFeeRate = $costs->stopExitFeeRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $spreadRate = $costs->spreadRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $slippageRate = $costs->slippageRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $fundingRate = $costs->fundingRate ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        $fundingIntervals = $costs->fundingIntervals ?? throw new CanonicalRiskException('canonical_market_cost_unknown');
        foreach ([$entryFeeRate, $stopExitFeeRate] as $feeRate) {
            if (!$this->matchesFeeSchedule($feeRate, $request->policy)) {
                throw new CanonicalRiskException('canonical_market_fee_rate_mismatch');
            }
        }

        $entryNotionalPerQuantity = $request->entryPrice * $request->contractSize;
        $stopNotionalPerQuantity = $request->stopPrice * $request->contractSize;
        $grossPerQuantity = abs($request->entryPrice - $request->stopPrice) * $request->contractSize;
        $costPerQuantity = $entryNotionalPerQuantity * (
            $entryFeeRate
            + $spreadRate
            + $slippageRate
            + max(0.0, $fundingRate) * $fundingIntervals
        ) + $stopNotionalPerQuantity * $stopExitFeeRate;
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
            spreadCost: $components['spread'],
            slippageCost: $components['slippage'],
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

    /** @return array{gross:float,entry_fee:float,stop_exit_fee:float,spread:float,slippage:float,funding:float,total:float} */
    private function components(CanonicalRiskCalculationRequest $request, float $quantity): array
    {
        $costs = $request->costs;
        $entryNotional = $request->entryPrice * $request->contractSize * $quantity;
        $stopNotional = $request->stopPrice * $request->contractSize * $quantity;
        $gross = abs($request->entryPrice - $request->stopPrice) * $request->contractSize * $quantity;
        $entryFee = $entryNotional * (float) $costs->entryFeeRate;
        $stopExitFee = $stopNotional * (float) $costs->stopExitFeeRate;
        $spread = $entryNotional * (float) $costs->spreadRate;
        $slippage = $entryNotional * (float) $costs->slippageRate;
        $funding = $entryNotional * max(0.0, (float) $costs->fundingRate) * (int) $costs->fundingIntervals;

        return [
            'gross' => $gross,
            'entry_fee' => $entryFee,
            'stop_exit_fee' => $stopExitFee,
            'spread' => $spread,
            'slippage' => $slippage,
            'funding' => $funding,
            'total' => $gross + $entryFee + $stopExitFee + $spread + $slippage + $funding,
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

    private function matchesFeeSchedule(float $feeRate, CanonicalRiskPolicy $policy): bool
    {
        return abs($feeRate - $policy->makerFeeRate) <= self::EPSILON
            || abs($feeRate - $policy->takerFeeRate) <= self::EPSILON;
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
