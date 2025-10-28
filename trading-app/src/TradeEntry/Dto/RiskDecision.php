<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class RiskDecision
{
    public function __construct(
        public float $stopPct,
        public float $riskUsdt,
        public float $leverage,
        public float $quantity   // qty de l'ordre principal
    ) {}
}
