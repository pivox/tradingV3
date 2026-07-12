<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid\Dto;

final readonly class HyperliquidMarginSafetyEvidence
{
    public function __construct(
        public string $symbol,
        public string $notional,
        public int $marginTableId,
        public string $tierLowerBound,
        public int $tierMaxLeverage,
        public string $maintenanceMarginRate,
        public string $maintenanceMarginDeduction,
        public string $accountAddress,
        public string $accountMarginMode,
        public int $accountLeverage,
        public \DateTimeImmutable $observedAt,
    ) {
    }
}
