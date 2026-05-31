<?php

declare(strict_types=1);

namespace App\Exchange\Enum;

enum ExchangeTimeInForce: string
{
    case GTC = 'gtc';
    case IOC = 'ioc';
    case FOK = 'fok';
}
