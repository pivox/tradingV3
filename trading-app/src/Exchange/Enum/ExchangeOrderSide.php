<?php

declare(strict_types=1);

namespace App\Exchange\Enum;

enum ExchangeOrderSide: string
{
    case BUY = 'buy';
    case SELL = 'sell';
}
