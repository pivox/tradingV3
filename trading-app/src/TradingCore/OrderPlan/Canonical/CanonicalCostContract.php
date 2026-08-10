<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalCostContract
{
    public function __construct(
        public string $entrySpreadSource,
        public string $entrySlippageSource,
        public string $stopSpreadSource,
        public string $stopSlippageSource,
        public string $targetSpreadSource,
        public string $targetSlippageSource,
        public string $fundingSource,
        public int $fundingIntervalSeconds,
    ) {
    }
}
