<?php

namespace App\Contract\EntryTrade;

interface TradeContextInterface
{
    public function getAccountBalance(): float;
    public function getRiskPercentage(): float;
    public function getTimeframeMultiplier(string $tf): float;
}
