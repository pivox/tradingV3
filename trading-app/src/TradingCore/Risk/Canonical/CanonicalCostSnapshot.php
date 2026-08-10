<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

final readonly class CanonicalCostSnapshot
{
    public function __construct(
        public ?float $entryFeeRate,
        public ?float $stopExitFeeRate,
        public ?float $spreadRate,
        public ?float $slippageRate,
        public ?float $fundingRate,
        public ?int $fundingIntervals,
    ) {
        foreach ([
            'entry_fee_rate' => $entryFeeRate,
            'stop_exit_fee_rate' => $stopExitFeeRate,
            'spread_rate' => $spreadRate,
            'slippage_rate' => $slippageRate,
            'funding_rate' => $fundingRate,
            'funding_intervals' => $fundingIntervals,
        ] as $field => $value) {
            if ($value === null) {
                throw new CanonicalRiskException('canonical_market_cost_unknown', ['field' => $field]);
            }
        }

        foreach ([$entryFeeRate, $stopExitFeeRate, $spreadRate, $slippageRate] as $rate) {
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
