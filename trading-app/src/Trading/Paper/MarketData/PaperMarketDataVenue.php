<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

enum PaperMarketDataVenue: string
{
    case OKX = 'okx';
    case HYPERLIQUID = 'hyperliquid';
}
