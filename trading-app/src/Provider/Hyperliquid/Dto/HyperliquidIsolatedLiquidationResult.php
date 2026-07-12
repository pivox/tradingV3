<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid\Dto;

final readonly class HyperliquidIsolatedLiquidationResult
{
    public function __construct(
        public string $liquidationPrice,
        public int $tierIndex,
        public string $tierLowerBound,
        public int $tierMaxLeverage,
        public string $maintenanceMarginRate,
        public string $maintenanceMarginDeduction,
    ) {
    }
}
