<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeFillCostModel
{
    public const MODEL_VERSION = 'fixed_adverse_slippage_bps_v1';
    public const SPREAD_MODEL_VERSION = 'top_of_book_embedded_spread_v1';
    public const TAKER_SLIPPAGE_BPS = 5.0;

    public function forFill(
        float $quantity,
        float $price,
        float $contractSize,
        bool $postOnly,
    ): FakeFillCost {
        $this->assertPositiveFinite($quantity, 'quantity');
        $this->assertPositiveFinite($price, 'price');
        $this->assertPositiveFinite($contractSize, 'contractSize');

        $liquidityRole = $postOnly ? 'maker' : 'taker';
        $slippageCostUsdt = $postOnly
            ? 0.0
            : round($quantity * $price * $contractSize * self::TAKER_SLIPPAGE_BPS / 10_000.0, 12);

        return new FakeFillCost(
            liquidityRole: $liquidityRole,
            spreadCostUsdt: 0.0,
            slippageCostUsdt: $slippageCostUsdt,
            modelVersion: self::MODEL_VERSION,
            spreadModelVersion: self::SPREAD_MODEL_VERSION,
        );
    }

    private function assertPositiveFinite(float $value, string $field): void
    {
        if (!\is_finite($value) || $value <= 0.0) {
            throw new \InvalidArgumentException(sprintf('%s must be positive and finite', $field));
        }
    }
}
