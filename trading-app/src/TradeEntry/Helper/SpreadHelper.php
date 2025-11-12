<?php
declare(strict_types=1);

namespace App\TradeEntry\Helper;

use App\Contract\Provider\Dto\SymbolBidAskDto;

final class SpreadHelper
{
    public static function calculateSpreadBps(SymbolBidAskDto $orderBook): float
    {
        $bestBid = (float)$orderBook->bid;
        $bestAsk = (float)$orderBook->ask;

        if ($bestBid <= 0.0 || $bestAsk <= 0.0) {
            return INF;
        }

        $mid = ($bestAsk + $bestBid) / 2.0;
        if ($mid <= 0.0) {
            return INF;
        }

        return (($bestAsk - $bestBid) / $mid) * 10_000.0; // bps
    }
}

