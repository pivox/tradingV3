<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Config;

final class TradingConfig
{
    public function __construct(
        public readonly float $budgetCapUsdt,
        public readonly float $riskAbsUsdt,
        public readonly float $tpAbsUsdt,
        public readonly float $riskPct,
        public readonly int $atrLookback,
        public readonly string $atrMethod,
        public readonly string $atrTimeframe,
        public readonly float $atrKStop,
        public readonly float $tpRMultiple,
        public readonly string $openType
    ) {
    }
}
