<?php

declare(strict_types=1);

namespace App\Exchange\Enum;

enum ExchangeOrderType: string
{
    case LIMIT = 'limit';
    case MARKET = 'market';
    case STOP_LOSS = 'stop_loss';
    case TAKE_PROFIT = 'take_profit';
    case TRIGGER = 'trigger';
}
