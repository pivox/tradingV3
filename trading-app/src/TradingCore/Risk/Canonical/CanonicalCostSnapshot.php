<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

final readonly class CanonicalCostSnapshot
{
    public function __construct(
        public ?string $entryLiquidityRole,
        public ?string $stopLiquidityRole,
        public ?float $entrySpreadRate,
        public ?float $stopSpreadRate,
        public ?float $entrySlippageRate,
        public ?float $stopSlippageRate,
        public ?float $fundingRate,
        public ?int $fundingIntervals,
    ) {
        foreach ([
            'entry_liquidity_role' => $entryLiquidityRole,
            'stop_liquidity_role' => $stopLiquidityRole,
            'entry_spread_rate' => $entrySpreadRate,
            'stop_spread_rate' => $stopSpreadRate,
            'entry_slippage_rate' => $entrySlippageRate,
            'stop_slippage_rate' => $stopSlippageRate,
            'funding_rate' => $fundingRate,
            'funding_intervals' => $fundingIntervals,
        ] as $field => $value) {
            if ($value === null) {
                throw new CanonicalRiskException('canonical_market_cost_unknown', ['field' => $field]);
            }
        }

        foreach ([$entryLiquidityRole, $stopLiquidityRole] as $role) {
            if (!\in_array($role, ['maker', 'taker'], true)) {
                throw new CanonicalRiskException('canonical_market_liquidity_role_invalid');
            }
        }
        foreach ([$entrySpreadRate, $stopSpreadRate, $entrySlippageRate, $stopSlippageRate] as $rate) {
            if (!\is_finite($rate) || $rate < 0.0 || $rate >= 1.0) {
                throw new CanonicalRiskException('canonical_market_cost_rate_invalid');
            }
        }
        if (!\is_finite($fundingRate) || $fundingRate <= -1.0 || $fundingRate >= 1.0) {
            throw new CanonicalRiskException('canonical_market_cost_rate_invalid');
        }
        if ($fundingIntervals < 0) {
            throw new CanonicalRiskException('canonical_market_funding_intervals_invalid');
        }
    }
}
