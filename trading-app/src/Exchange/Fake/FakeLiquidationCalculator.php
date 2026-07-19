<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Enum\ExchangePositionSide;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class FakeLiquidationCalculator
{
    private const SCALE = 12;

    public function __construct(private FakeLiquidationPolicy $policy = new FakeLiquidationPolicy())
    {
    }

    public function policy(): FakeLiquidationPolicy
    {
        return $this->policy;
    }

    public function calculate(FakeLiquidationInput $input): FakeLiquidationResult
    {
        if ($input->marginMode === 'cross') {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::UNSUPPORTED,
                'liquidation_cross_margin_unsupported',
            );
        }
        if ($input->marginMode !== $this->policy->supportedMarginMode) {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::UNSUPPORTED,
                'liquidation_margin_mode_unsupported',
            );
        }

        [$quantity, $reason] = $this->requiredDecimal($input->quantity, 'liquidation_quantity', true);
        if ($reason !== null) {
            return $this->unavailable($input, FakeLiquidationResult::INVALID, $reason);
        }
        [$entryPrice, $reason] = $this->requiredDecimal($input->entryPrice, 'liquidation_entry_price', true);
        if ($reason !== null) {
            return $this->unavailable($input, FakeLiquidationResult::INVALID, $reason);
        }
        [$isolatedMargin, $reason] = $this->requiredDecimal(
            $input->isolatedMargin,
            'liquidation_isolated_margin',
            true,
        );
        if ($reason !== null) {
            return $this->unavailable($input, FakeLiquidationResult::INVALID, $reason);
        }
        [$contractSize, $reason] = $this->requiredDecimal(
            $input->contractSize,
            'liquidation_contract_size',
            true,
        );
        if ($reason !== null) {
            return $this->unavailable($input, FakeLiquidationResult::INVALID, $reason);
        }
        [$maintenanceRate, $reason] = $this->requiredDecimal(
            $input->maintenanceMarginRate,
            'liquidation_maintenance_margin_rate',
            true,
        );
        if ($reason !== null || $maintenanceRate?->isGreaterThanOrEqualTo(BigDecimal::one())) {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::INVALID,
                $reason ?? 'liquidation_maintenance_margin_rate_invalid',
            );
        }
        [$markPrice, $reason] = $this->requiredDecimal($input->markPrice, 'liquidation_mark_price', true);
        if ($reason !== null) {
            return $this->unavailable($input, FakeLiquidationResult::INVALID, $reason);
        }
        [$guardBufferRate, $reason] = $this->requiredDecimal(
            $this->policy->guardBufferRate,
            'liquidation_guard_buffer',
            true,
        );
        if ($reason !== null || $guardBufferRate?->isGreaterThanOrEqualTo(BigDecimal::one())) {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::INVALID,
                'liquidation_guard_buffer_invalid',
            );
        }
        [$liquidationFeeRate, $reason] = $this->requiredDecimal(
            $this->policy->liquidationFeeRate,
            'liquidation_fee_rate',
            true,
        );
        if (
            $reason !== null
            || $liquidationFeeRate?->isGreaterThanOrEqualTo(BigDecimal::one())
            || $this->policy->feeCurrency !== 'USDT'
            || $this->policy->feeModelVersion !== FakeLiquidationPolicy::FEE_MODEL_VERSION
            || $this->policy->modelVersion !== FakeLiquidationPolicy::MODEL_VERSION
            || $this->policy->markPriceSource !== FakeLiquidationPolicy::MARK_PRICE_SOURCE
        ) {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::INVALID,
                'liquidation_fee_policy_invalid',
            );
        }

        \assert($quantity instanceof BigDecimal);
        \assert($entryPrice instanceof BigDecimal);
        \assert($isolatedMargin instanceof BigDecimal);
        \assert($contractSize instanceof BigDecimal);
        \assert($maintenanceRate instanceof BigDecimal);
        \assert($markPrice instanceof BigDecimal);
        \assert($guardBufferRate instanceof BigDecimal);

        try {
            $contractQuantity = $quantity->multipliedBy($contractSize);
            $entryNotional = $contractQuantity->multipliedBy($entryPrice);
            $one = BigDecimal::one();
            if ($input->side === ExchangePositionSide::LONG) {
                $numerator = $entryNotional->minus($isolatedMargin);
                if ($numerator->isNegative()) {
                    return $this->unavailable(
                        $input,
                        FakeLiquidationResult::INVALID,
                        'liquidation_threshold_invalid',
                    );
                }
                $denominator = $contractQuantity->multipliedBy($one->minus($maintenanceRate));
            } else {
                $numerator = $entryNotional->plus($isolatedMargin);
                $denominator = $contractQuantity->multipliedBy($one->plus($maintenanceRate));
            }
            $liquidationPrice = $numerator->dividedBy($denominator, self::SCALE, RoundingMode::HALF_EVEN);
            $guardBufferAmount = $entryPrice
                ->multipliedBy($guardBufferRate)
                ->toScale(self::SCALE, RoundingMode::HALF_EVEN);
            $guardPrice = ($input->side === ExchangePositionSide::LONG
                ? $liquidationPrice->plus($guardBufferAmount)
                : $liquidationPrice->minus($guardBufferAmount))
                ->toScale(self::SCALE, RoundingMode::HALF_EVEN);
        } catch (\Throwable) {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::INVALID,
                'liquidation_calculation_invalid',
            );
        }

        $thresholdValid = $input->side === ExchangePositionSide::LONG
            ? !$liquidationPrice->isNegative() && $liquidationPrice->isLessThan($entryPrice)
            : $liquidationPrice->isGreaterThan($entryPrice);
        $guardValid = $input->side === ExchangePositionSide::LONG
            ? $guardPrice->isGreaterThan($liquidationPrice) && $guardPrice->isLessThan($entryPrice)
            : $guardPrice->isLessThan($liquidationPrice) && $guardPrice->isGreaterThan($entryPrice);
        if (!$thresholdValid) {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::INVALID,
                'liquidation_threshold_invalid',
            );
        }
        if (!$guardValid) {
            return $this->unavailable(
                $input,
                FakeLiquidationResult::INVALID,
                'liquidation_guard_buffer_invalid',
            );
        }

        $markState = match ($input->side) {
            ExchangePositionSide::LONG => $markPrice->isLessThanOrEqualTo($liquidationPrice)
                ? FakeLiquidationResult::LIQUIDATE
                : ($markPrice->isLessThanOrEqualTo($guardPrice)
                    ? FakeLiquidationResult::GUARD
                    : FakeLiquidationResult::SAFE),
            ExchangePositionSide::SHORT => $markPrice->isGreaterThanOrEqualTo($liquidationPrice)
                ? FakeLiquidationResult::LIQUIDATE
                : ($markPrice->isGreaterThanOrEqualTo($guardPrice)
                    ? FakeLiquidationResult::GUARD
                    : FakeLiquidationResult::SAFE),
        };

        return new FakeLiquidationResult(
            status: FakeLiquidationResult::READY,
            reason: null,
            markState: $markState,
            side: $input->side,
            marginMode: $input->marginMode,
            quantity: $this->scaled($quantity),
            entryPrice: $this->scaled($entryPrice),
            isolatedMargin: $this->scaled($isolatedMargin),
            contractSize: $this->scaled($contractSize),
            maintenanceMarginRate: $this->scaled($maintenanceRate),
            markPrice: $this->scaled($markPrice),
            liquidationPrice: $this->scaled($liquidationPrice),
            guardPrice: $this->scaled($guardPrice),
            guardBufferAmount: $this->scaled($guardBufferAmount),
            policy: $this->policy,
        );
    }

    public function liquidationFeeUsdt(string $quantity, string $markPrice, string $contractSize): string
    {
        $quantityDecimal = $this->feeDecimal($quantity, 'liquidation_fee_quantity_invalid');
        $markPriceDecimal = $this->feeDecimal($markPrice, 'liquidation_fee_mark_price_invalid');
        $contractSizeDecimal = $this->feeDecimal($contractSize, 'liquidation_fee_contract_size_invalid');
        $rate = $this->feeDecimal($this->policy->liquidationFeeRate, 'liquidation_fee_rate_invalid');
        if (
            $rate->isGreaterThanOrEqualTo(BigDecimal::one())
            || $this->policy->feeCurrency !== 'USDT'
            || $this->policy->feeModelVersion !== FakeLiquidationPolicy::FEE_MODEL_VERSION
        ) {
            throw new \InvalidArgumentException('liquidation_fee_policy_invalid');
        }

        return $this->scaled($quantityDecimal
            ->multipliedBy($markPriceDecimal)
            ->multipliedBy($contractSizeDecimal)
            ->multipliedBy($rate));
    }

    /** @return array{?BigDecimal,?string} */
    private function requiredDecimal(?string $value, string $field, bool $positive): array
    {
        if ($value === null || trim($value) === '') {
            return [null, $field . '_unknown'];
        }
        try {
            $decimal = BigDecimal::of($value);
        } catch (\Throwable) {
            return [null, $field . '_invalid'];
        }
        if ($positive && !$decimal->isGreaterThan(BigDecimal::zero())) {
            return [null, $field . '_invalid'];
        }

        return [$decimal, null];
    }

    private function feeDecimal(string $value, string $reason): BigDecimal
    {
        try {
            $decimal = BigDecimal::of($value);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException($reason, 0, $exception);
        }
        if (!$decimal->isGreaterThan(BigDecimal::zero())) {
            throw new \InvalidArgumentException($reason);
        }

        return $decimal;
    }

    private function scaled(BigDecimal $decimal): string
    {
        return (string) $decimal->toScale(self::SCALE, RoundingMode::HALF_EVEN);
    }

    private function unavailable(
        FakeLiquidationInput $input,
        string $status,
        string $reason,
    ): FakeLiquidationResult {
        return new FakeLiquidationResult(
            status: $status,
            reason: $reason,
            markState: FakeLiquidationResult::UNKNOWN,
            side: $input->side,
            marginMode: $input->marginMode,
            quantity: $input->quantity,
            entryPrice: $input->entryPrice,
            isolatedMargin: $input->isolatedMargin,
            contractSize: $input->contractSize,
            maintenanceMarginRate: $input->maintenanceMarginRate,
            markPrice: $input->markPrice,
            liquidationPrice: null,
            guardPrice: null,
            guardBufferAmount: null,
            policy: $this->policy,
        );
    }
}
