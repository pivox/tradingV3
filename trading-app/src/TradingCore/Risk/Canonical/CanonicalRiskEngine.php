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
        $riskBudgetDecimal = $this->decimal($request->equityQuote)->multipliedBy($this->decimal($request->policy->riskRate));
        $riskBudgetQuote = $riskBudgetDecimal->toFloat();
        if (!\is_finite($riskBudgetQuote) || $riskBudgetQuote <= 0.0) {
            throw new CanonicalRiskException('canonical_risk_budget_invalid');
        }

        $lossPerQuantityDecimal = $this->decimalComponents($request, BigDecimal::of('1'))['total'];
        if ($lossPerQuantityDecimal->isLessThanOrEqualTo(0)) {
            throw new CanonicalRiskException('canonical_risk_stop_path_invalid');
        }

        $rawQuantity = $riskBudgetDecimal->dividedBy($lossPerQuantityDecimal, 18, RoundingMode::DOWN)->toFloat();
        if (!\is_finite($rawQuantity)) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }
        $quantityCaps = [
            $this->quantizeDecimalRatioDown($riskBudgetDecimal, $lossPerQuantityDecimal, $request->quantityStep),
            $this->quantizeDownDecimal($request->maxQuantity, $request->quantityStep),
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
            $quantityCaps[] = $this->quantizeDownDecimal($request->marketMaxQuantity, $request->quantityStep);
        }

        $quantityDecimal = array_reduce(
            $quantityCaps,
            static fn (?BigDecimal $minimum, BigDecimal $cap): BigDecimal => $minimum === null || $cap->isLessThan($minimum) ? $cap : $minimum,
        );
        if (!$quantityDecimal instanceof BigDecimal) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }
        foreach ($quantityCaps as $quantityCap) {
            if ($quantityDecimal->isGreaterThan($quantityCap)) {
                throw new CanonicalRiskException('canonical_risk_post_quantization_cap_breach');
            }
        }
        if ($quantityDecimal->isLessThanOrEqualTo(0) || $quantityDecimal->isLessThan($this->decimal($request->minQuantity))) {
            throw new CanonicalRiskException('canonical_risk_quantity_below_minimum', [
                'raw_quantity' => $rawQuantity,
                'minimum_quantity' => $request->minQuantity,
            ]);
        }

        $decimalComponents = $this->decimalComponents($request, $quantityDecimal);
        if ($decimalComponents['total']->isGreaterThan($riskBudgetDecimal)) {
            $quantityDecimal = $quantityDecimal->minus($this->decimal($request->quantityStep));
            if ($quantityDecimal->isLessThanOrEqualTo(0) || $quantityDecimal->isLessThan($this->decimal($request->minQuantity))) {
                throw new CanonicalRiskException('canonical_risk_quantity_below_minimum');
            }
            $decimalComponents = $this->decimalComponents($request, $quantityDecimal);
        }
        $components = $this->floatComponents($decimalComponents);
        if ($decimalComponents['total']->isGreaterThan($riskBudgetDecimal)) {
            throw new CanonicalRiskException('canonical_risk_post_quantization_breach', [
                'risk_budget_quote' => $riskBudgetQuote,
                'total_stop_loss' => $components['total'],
            ]);
        }

        $positionNotionalDecimal = $this->decimalProduct([$request->entryPrice, $request->contractSize])->multipliedBy($quantityDecimal);
        $positionNotional = $positionNotionalDecimal->toFloat();
        if ($positionNotionalDecimal->isLessThan($this->decimal($request->policy->exchangeMinNotional))) {
            throw new CanonicalRiskException('canonical_risk_notional_below_minimum', [
                'position_notional' => $positionNotional,
                'exchange_min_notional' => $request->policy->exchangeMinNotional,
            ]);
        }
        $finalLeverage = max(1, $positionNotionalDecimal->dividedBy(
            $this->decimal($request->availableBalanceQuote),
            0,
            RoundingMode::CEILING,
        )->toInt());
        if ($finalLeverage > $effectiveLeverageCap) {
            throw new CanonicalRiskException('canonical_leverage_post_quantization_breach', [
                'final_leverage' => $finalLeverage,
                'effective_cap' => $effectiveLeverageCap,
            ]);
        }
        $quantity = $this->exactFloat($quantityDecimal);

        return new CanonicalRiskDecision(
            riskBudgetQuote: $riskBudgetQuote,
            quantity: $quantity,
            positionNotional: $positionNotional,
            finalLeverage: $finalLeverage,
            effectiveLeverageCap: $effectiveLeverageCap,
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
            symbol: $request->symbol,
            marketType: $request->marketType,
            side: $request->side,
            entryPrice: $request->entryPrice,
            stopPrice: $request->stopPrice,
            contractSize: $request->contractSize,
            costs: $request->costs,
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

    /** @return array{gross:BigDecimal,entry_fee:BigDecimal,stop_exit_fee:BigDecimal,entry_spread:BigDecimal,stop_spread:BigDecimal,entry_slippage:BigDecimal,stop_slippage:BigDecimal,funding:BigDecimal,total:BigDecimal} */
    private function decimalComponents(CanonicalRiskCalculationRequest $request, BigDecimal $quantity): array
    {
        $costs = $request->costs;
        $entryNotional = $this->decimalProduct([$request->entryPrice, $request->contractSize])->multipliedBy($quantity);
        $stopNotional = $this->decimalProduct([$request->stopPrice, $request->contractSize])->multipliedBy($quantity);
        $gross = $this->decimal($request->entryPrice)
            ->minus($this->decimal($request->stopPrice))
            ->abs()
            ->multipliedBy($this->decimal($request->contractSize))
            ->multipliedBy($quantity);
        $entryFee = $entryNotional->multipliedBy($this->decimal($this->feeRateFor((string) $costs->entryLiquidityRole, $request->policy)));
        $stopExitFee = $stopNotional->multipliedBy($this->decimal($this->feeRateFor((string) $costs->stopLiquidityRole, $request->policy)));
        $entrySpread = $entryNotional->multipliedBy($this->decimal((float) $costs->entrySpreadRate));
        $stopSpread = $stopNotional->multipliedBy($this->decimal((float) $costs->stopSpreadRate));
        $entrySlippage = $entryNotional->multipliedBy($this->decimal((float) $costs->entrySlippageRate));
        $stopSlippage = $stopNotional->multipliedBy($this->decimal((float) $costs->stopSlippageRate));
        $funding = $entryNotional
            ->multipliedBy($this->decimal($this->adverseFundingRate($request->side, (float) $costs->fundingRate)))
            ->multipliedBy((string) (int) $costs->fundingIntervals);
        $total = $gross->plus($entryFee)->plus($stopExitFee)
            ->plus($entrySpread)->plus($stopSpread)->plus($entrySlippage)->plus($stopSlippage)->plus($funding);

        return [
            'gross' => $gross,
            'entry_fee' => $entryFee,
            'stop_exit_fee' => $stopExitFee,
            'entry_spread' => $entrySpread,
            'stop_spread' => $stopSpread,
            'entry_slippage' => $entrySlippage,
            'stop_slippage' => $stopSlippage,
            'funding' => $funding,
            'total' => $total,
        ];
    }

    /**
     * @param array{gross:BigDecimal,entry_fee:BigDecimal,stop_exit_fee:BigDecimal,entry_spread:BigDecimal,stop_spread:BigDecimal,entry_slippage:BigDecimal,stop_slippage:BigDecimal,funding:BigDecimal,total:BigDecimal} $components
     * @return array{gross:float,entry_fee:float,stop_exit_fee:float,entry_spread:float,stop_spread:float,entry_slippage:float,stop_slippage:float,funding:float,total:float}
     */
    private function floatComponents(array $components): array
    {
        return array_map(static fn (BigDecimal $component): float => $component->toFloat(), $components);
    }

    private function quantizeDownDecimal(float $quantity, float $step): BigDecimal
    {
        if (!\is_finite($quantity) || $quantity <= 0.0) {
            return BigDecimal::of('0');
        }

        $decimalPlaces = $this->decimalPlaces($step);
        $quantityUnits = $this->scaledFloorUnits($quantity, $decimalPlaces);
        $stepUnits = $this->scaledFloorUnits($step, $decimalPlaces);
        if ($stepUnits < 1) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }
        $steps = intdiv($quantityUnits, $stepUnits);

        return $this->decimal($step)->multipliedBy((string) $steps);
    }

    /**
     * @param non-empty-list<float> $numeratorFactors
     * @param non-empty-list<float> $denominatorFactors
     */
    private function quantizeRatioDown(array $numeratorFactors, array $denominatorFactors, float $step): BigDecimal
    {
        $numerator = BigDecimal::of('1');
        foreach ($numeratorFactors as $factor) {
            if (!\is_finite($factor) || $factor <= 0.0) {
                return BigDecimal::of('0');
            }
            $numerator = $numerator->multipliedBy(BigDecimal::of($this->canonicalDecimal($factor)));
        }
        $denominator = BigDecimal::of('1');
        foreach ($denominatorFactors as $factor) {
            if (!\is_finite($factor) || $factor <= 0.0) {
                return BigDecimal::of('0');
            }
            $denominator = $denominator->multipliedBy(BigDecimal::of($this->canonicalDecimal($factor)));
        }

        return $this->quantizeDecimalRatioDown($numerator, $denominator, $step);
    }

    private function quantizeDecimalRatioDown(BigDecimal $numerator, BigDecimal $denominator, float $step): BigDecimal
    {
        $stepDecimal = BigDecimal::of($this->canonicalDecimal($step));
        $steps = $numerator->dividedBy(
            $denominator->multipliedBy($stepDecimal),
            0,
            RoundingMode::FLOOR,
        );
        return $stepDecimal->multipliedBy($steps);
    }

    /** @param non-empty-list<float> $factors */
    private function decimalProduct(array $factors): BigDecimal
    {
        $product = BigDecimal::of('1');
        foreach ($factors as $factor) {
            $product = $product->multipliedBy($this->decimal($factor));
        }

        return $product;
    }

    private function decimal(float $value): BigDecimal
    {
        return BigDecimal::of($this->canonicalDecimal($value));
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

    private function exactFloat(BigDecimal $value): float
    {
        $float = $value->toFloat();
        if (!\is_finite($float) || !$this->decimal($float)->isEqualTo($value)) {
            throw new CanonicalRiskException('canonical_risk_quantity_precision_unsupported');
        }

        return $float;
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
