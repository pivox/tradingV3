<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

enum PaperMarketDataQuality: string
{
    case RECORDED_PUBLIC_BOOK_AND_TRADES = 'recorded_public_book_and_trades';
    case PUBLIC_HISTORICAL_CANDLES_AND_TRADES = 'public_historical_candles_and_trades';
    case PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK = 'public_historical_candles_modelled_book';
    case INCOMPLETE = 'incomplete';
}
