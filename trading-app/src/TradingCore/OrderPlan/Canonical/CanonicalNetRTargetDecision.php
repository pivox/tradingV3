<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalNetRTargetDecision
{
    public function __construct(
        public string $id,
        public float $price,
        public float $grossReward,
        public float $entryFee,
        public float $targetFee,
        public float $entrySpreadCost,
        public float $entrySlippageCost,
        public float $targetSpreadCost,
        public float $targetSlippageCost,
        public float $fundingCost,
        public float $netReward,
        public float $netRisk,
        public float $netR,
    ) {
    }
}
