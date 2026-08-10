<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

final readonly class CanonicalRiskDecision
{
    /** @param list<string> $capsApplied */
    public function __construct(
        public float $riskBudgetQuote,
        public float $quantity,
        public float $positionNotional,
        public int $finalLeverage,
        public float $grossStopLoss,
        public float $entryFee,
        public float $stopExitFee,
        public float $entrySpreadCost,
        public float $stopSpreadCost,
        public float $entrySlippageCost,
        public float $stopSlippageCost,
        public float $fundingCost,
        public float $totalStopLoss,
        public float $rawQuantity,
        public float $quantityStep,
        public array $capsApplied,
        public CanonicalRiskPolicy $policy,
        public string $symbol,
        public string $side,
        public float $entryPrice,
        public float $stopPrice,
        public float $contractSize,
        public CanonicalCostSnapshot $costs,
    ) {
    }
}
