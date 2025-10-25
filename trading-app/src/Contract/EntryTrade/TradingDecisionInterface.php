<?php

namespace App\Contract\EntryTrade;

use App\Common\Enum\SignalSide;

interface TradingDecisionInterface
{
    public function makeTradingDecision(
        string $symbol,
        SignalSide $side,
        float $currentPrice,
        float $atr,
        float $accountBalance,
        float $riskPercentage,
        bool $isHighConviction = false,
        float $timeframeMultiplier = 1.0
    ): array;
}
