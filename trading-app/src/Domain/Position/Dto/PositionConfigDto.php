<?php

declare(strict_types=1);

namespace App\Domain\Position\Dto;

class PositionConfigDto
{

    public function __construct(
        public readonly float $defaultRiskPercent,
        public readonly float $maxRiskPercent,
        public readonly float $slAtrMultiplier,
        public readonly float $tpAtrMultiplier,
        public readonly float $maxPositionSize,
        public readonly string $orderType,
        public readonly string $timeInForce,
        public readonly bool $enablePartialFills,
        public readonly float $minOrderSize,
        public readonly float $maxOrderSize,
        public readonly bool $enableStopLoss,
        public readonly bool $enableTakeProfit,
        public readonly string $openType,
        public bool $dryRun = false
    ) {
    }
}



