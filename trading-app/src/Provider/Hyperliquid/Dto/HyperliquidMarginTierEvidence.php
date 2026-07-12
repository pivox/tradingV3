<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid\Dto;

final readonly class HyperliquidMarginTierEvidence
{
    public function __construct(
        public string $lowerBound,
        public int $maxLeverage,
        public string $maintenanceMarginRate,
        public string $maintenanceMarginDeduction,
    ) {
    }
}
