<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class CanonicalRiskEngine
{
    private const MAX_EXACT_FLOAT_INTEGER_DECIMAL = '9007199254740992';

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
            $this->quantizeRatioDown([$riskBudgetQuote], [$lossPerQuantity], $request->quantityStep),
            $this->quantizeDown($request->maxQuantity, $request->quantityStep),
            $this->quantizeRatioDown(
                [$request->policy->exchangeMaxNotional],
                [$request->entryPrice, $request->contractSize],
                $request->quantityStep,
            ),
            $this->quantizeRatioDown(
                [$request->policy->environmentMaxNotional],
                [$request->entryPrice, $request->contractSize],
                $request->quantityStep,
            ),
            $this->quantizeRatioDown(
                [$request->availableBalanceQuote, (float) $effectiveLeverageCap],
                [$request->entryPrice, $request->contractSize],
                $request->quantityStep,
            ),
        ];
        if ($request->marketMaxQuantity !== null) {
            $quantityCaps[] = $this->quantizeDown($request->marketMaxQuantity, $request->quantityStep);
        }

        $quantity = min($quantityCaps);
        foreach ($quantityCaps as $quantityCap) {
            if ($quantity > $quantityCap) {
                throw new CanonicalRiskException('canonical_risk_post_quantization_cap_breach');
            }
        }
        if ($quantity <= 0.0 || $quantity < $request->minQuantity) {
            throw new CanonicalRiskException('canonical_risk_quantity_below_minimum', [
                'raw_quantity' => $rawQuantity,
                'minimum_quantity' => $request->minQuantity,
            ]);
        }

        $components = $this->components($request, $quantity);
        if ($components['total'] > $riskBudgetQuote) {
            $quantity = $this->subtractOneStep($quantity, $request->quantityStep);
            if ($quantity <= 0.0 || $quantity < $request->minQuantity) {
                throw new CanonicalRiskException('canonical_risk_quantity_below_minimum');
            }
            $components = $this->components($request, $quantity);
        }
        if ($components['total'] > $riskBudgetQuote) {
            throw new CanonicalRiskException('canonical_risk_post_quantization_breach', [
                'risk_budget_quote' => $riskBudgetQuote,
                'total_stop_loss' => $components['total'],
            ]);
        }

        $positionNotional = $request->entryPrice * $request->contractSize * $quantity;
        if ($positionNotional < $request->policy->exchangeMinNotional) {
            throw new CanonicalRiskException('canonical_risk_notional_below_minimum', [
                'position_notional' => $positionNotional,
                'exchange_min_notional' => $request->policy->exchangeMinNotional,
            ]);
        }
        $finalLeverage = max(1, (int) ceil($positionNotional / $request->availableBalanceQuote));
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

        $decimalPlaces = $this->decimalPlaces($step);
        $scale = 10 ** $decimalPlaces;
        $quantityUnits = $this->scaledFloorUnits($quantity, $decimalPlaces);
        $stepUnits = $this->scaledFloorUnits($step, $decimalPlaces);
        if ($stepUnits < 1) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }
        $quantizedUnits = intdiv($quantityUnits, $stepUnits) * $stepUnits;

        return round($quantizedUnits / $scale, $decimalPlaces);
    }

    /**
     * @param non-empty-list<float> $numeratorFactors
     * @param non-empty-list<float> $denominatorFactors
     */
    private function quantizeRatioDown(array $numeratorFactors, array $denominatorFactors, float $step): float
    {
        $numerator = BigDecimal::of('1');
        foreach ($numeratorFactors as $factor) {
            if (!\is_finite($factor) || $factor <= 0.0) {
                return 0.0;
            }
            $numerator = $numerator->multipliedBy(BigDecimal::of($this->canonicalDecimal($factor)));
        }
        $denominator = BigDecimal::of('1');
        foreach ($denominatorFactors as $factor) {
            if (!\is_finite($factor) || $factor <= 0.0) {
                return 0.0;
            }
            $denominator = $denominator->multipliedBy(BigDecimal::of($this->canonicalDecimal($factor)));
        }

        $stepDecimal = BigDecimal::of($this->canonicalDecimal($step));
        $steps = $numerator->dividedBy(
            $denominator->multipliedBy($stepDecimal),
            0,
            RoundingMode::FLOOR,
        );
        $quantity = $stepDecimal->multipliedBy($steps)->toFloat();
        if (!\is_finite($quantity) || $quantity < 0.0) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }

        return $quantity;
    }

    private function scaledFloorUnits(float $value, int $decimalPlaces): int
    {
        $decimal = $this->canonicalDecimal($value);
        if (
            !\is_string($decimal)
            || preg_match('/\A(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?\z/D', $decimal, $matches) !== 1
        ) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }

        $fraction = $matches[2] ?? '';
        $exponent = isset($matches[3]) ? (int) $matches[3] : 0;
        $digits = ltrim($matches[1] . $fraction, '0');
        if ($digits === '') {
            return 0;
        }

        $power = $exponent - strlen($fraction) + $decimalPlaces;
        if ($power >= 0) {
            if (strlen($digits) + $power > strlen(self::MAX_EXACT_FLOAT_INTEGER_DECIMAL)) {
                throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
            }
            $scaled = $digits . str_repeat('0', $power);
        } else {
            $keptLength = strlen($digits) + $power;
            if ($keptLength <= 0) {
                return 0;
            }
            $scaled = substr($digits, 0, $keptLength);
        }

        $scaled = ltrim($scaled, '0');
        if ($scaled === '') {
            return 0;
        }
        if (
            strlen($scaled) > strlen(self::MAX_EXACT_FLOAT_INTEGER_DECIMAL)
            || (
                strlen($scaled) === strlen(self::MAX_EXACT_FLOAT_INTEGER_DECIMAL)
                && strcmp($scaled, self::MAX_EXACT_FLOAT_INTEGER_DECIMAL) > 0
            )
        ) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }

        return (int) $scaled;
    }

    private function canonicalDecimal(float $value): string
    {
        $previousPrecision = ini_get('serialize_precision');
        if ($previousPrecision === false) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }

        $changed = $previousPrecision !== '-1';
        if ($changed && ini_set('serialize_precision', '-1') === false) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }

        $decimal = false;
        $restored = true;
        try {
            $decimal = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } finally {
            if ($changed) {
                $restored = ini_set('serialize_precision', $previousPrecision) !== false;
            }
        }
        if (!\is_string($decimal) || !$restored) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }

        return $decimal;
    }

    private function subtractOneStep(float $quantity, float $step): float
    {
        $decimalPlaces = $this->decimalPlaces($step);
        $scale = 10 ** $decimalPlaces;
        $quantityUnits = $this->scaledFloorUnits($quantity, $decimalPlaces);
        $stepUnits = $this->scaledFloorUnits($step, $decimalPlaces);
        if ($quantityUnits < $stepUnits || $stepUnits < 1) {
            return 0.0;
        }

        return round(($quantityUnits - $stepUnits) / $scale, $decimalPlaces);
    }

    private function decimalPlaces(float $step): int
    {
        $normalized = rtrim(rtrim(sprintf('%.12F', $step), '0'), '.');
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
