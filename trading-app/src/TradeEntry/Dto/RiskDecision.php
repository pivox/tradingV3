<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class RiskDecision
{
    public function __construct(
        public readonly float $entry,
        public readonly float $stop,
        public readonly float $takeProfit,
        public readonly int $sizeContracts,
        public readonly int $leverage,
        public readonly float $riskUsdt,
        public readonly float $notionalUsdt,
    ) {}
}
