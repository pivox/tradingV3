<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Psr\Clock\ClockInterface;

final readonly class HyperliquidExecutionStatePolicy
{
    private const MAX_AGE_MILLISECONDS = 2_000;
    private const EMERGENCY_SLIPPAGE = '0.005';

    public function __construct(private ClockInterface $clock)
    {
    }

    /** @return list<string> */
    public function blockingReasons(HyperliquidExecutionState $state, string $expectedSymbol): array
    {
        $reasons = [];
        strtoupper(trim($state->symbol)) === strtoupper(trim($expectedSymbol)) || $reasons[] = 'hyperliquid_execution_state_symbol_mismatch';
        if (!is_finite($state->bestBid) || !is_finite($state->bestAsk)
            || $state->bestBid <= 0.0 || $state->bestAsk <= 0.0 || $state->bestBid >= $state->bestAsk
        ) {
            $reasons[] = 'hyperliquid_execution_quote_invalid';
        }

        $age = $this->milliseconds($this->clock->now()) - $this->milliseconds($state->observedAt);
        if ($age < 0) {
            $reasons[] = 'hyperliquid_execution_quote_in_future';
        } elseif ($age > self::MAX_AGE_MILLISECONDS) {
            $reasons[] = 'hyperliquid_execution_quote_stale';
        }
        if ($state->observedLeverage !== null && !in_array($state->observedMarginMode, ['isolated', 'cross'], true)) {
            $reasons[] = 'hyperliquid_execution_margin_mode_invalid';
        }

        return array_values(array_unique($reasons));
    }

    public function requiresIsolatedModeUpdate(HyperliquidExecutionState $state): bool
    {
        return $state->observedLeverage !== null && $state->observedMarginMode === 'cross';
    }

    public function emergencyCloseCap(HyperliquidExecutionState $state, string $positionSide, string $priceTick): float
    {
        $isLong = strtolower(trim($positionSide)) === 'long';
        return $this->boundedSlippagePrice(
            $isLong ? $state->bestBid : $state->bestAsk,
            sell: $isLong,
            priceTick: $priceTick,
        );
    }

    public function protectiveStopCap(float $stopPrice, string $positionSide, string $priceTick): float
    {
        return $this->boundedSlippagePrice(
            $stopPrice,
            sell: strtolower(trim($positionSide)) === 'long',
            priceTick: $priceTick,
        );
    }

    private function boundedSlippagePrice(float $referencePrice, bool $sell, string $priceTick): float
    {
        $tick = BigDecimal::of($priceTick);
        if ($tick->isLessThanOrEqualTo(BigDecimal::zero()) || !is_finite($referencePrice) || $referencePrice <= 0.0) {
            throw new \InvalidArgumentException('hyperliquid_execution_price_tick_invalid');
        }
        $reference = BigDecimal::of((string) $referencePrice);
        $factor = $sell
            ? BigDecimal::one()->minus(self::EMERGENCY_SLIPPAGE)
            : BigDecimal::one()->plus(self::EMERGENCY_SLIPPAGE);
        $rounding = $sell ? RoundingMode::DOWN : RoundingMode::UP;
        $units = $reference->multipliedBy($factor)->dividedBy($tick, 0, $rounding);
        if ($units->isLessThan(BigDecimal::one())) {
            $units = BigDecimal::one();
        }
        $cap = $units->multipliedBy($tick);
        if ($cap->isLessThanOrEqualTo(BigDecimal::zero()) || !$cap->remainder($tick)->isZero()) {
            throw new \InvalidArgumentException('hyperliquid_execution_cap_price_invalid');
        }

        return (float) (string) $cap;
    }

    private function milliseconds(\DateTimeInterface $time): int
    {
        return ((int) $time->format('U') * 1_000) + (int) $time->format('v');
    }
}
