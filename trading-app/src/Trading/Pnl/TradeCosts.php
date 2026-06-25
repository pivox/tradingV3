<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

final readonly class TradeCosts
{
    public function __construct(
        public ?float $otherTradingFeesUsdt,
        public ?float $fundingUsdt,
        public ?float $spreadCostUsdt,
        public ?float $slippageCostUsdt,
        public ?float $borrowCostUsdt,
        public ?float $liquidationFeeUsdt,
    ) {
    }

    public static function zeroKnown(): self
    {
        return new self(
            otherTradingFeesUsdt: 0.0,
            fundingUsdt: 0.0,
            spreadCostUsdt: 0.0,
            slippageCostUsdt: 0.0,
            borrowCostUsdt: 0.0,
            liquidationFeeUsdt: 0.0,
        );
    }

    /**
     * @return array<string, float|null>
     */
    public function components(): array
    {
        return [
            'other_trading_fees_usdt' => $this->otherTradingFeesUsdt,
            'funding_usdt' => $this->fundingUsdt,
            'spread_cost_usdt' => $this->spreadCostUsdt,
            'slippage_cost_usdt' => $this->slippageCostUsdt,
            'borrow_cost_usdt' => $this->borrowCostUsdt,
            'liquidation_fee_usdt' => $this->liquidationFeeUsdt,
        ];
    }
}
