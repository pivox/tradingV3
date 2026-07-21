<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

enum PaperMarketDataChannel: string
{
    case CANDLE_1M = 'candle_1m';
    case CANDLE_5M = 'candle_5m';
    case CANDLE_15M = 'candle_15m';
    case CANDLE_1H = 'candle_1h';
    case TOP_OF_BOOK = 'top_of_book';
    case PUBLIC_TRADE = 'public_trade';
    case CONNECTION_STATE = 'connection_state';
    case SNAPSHOT_BOUNDARY = 'snapshot_boundary';
}
