<?php

declare(strict_types=1);

namespace App\Common\Enum;

enum OrderType: string
{
    case MARKET = 'market';
    case LIMIT = 'limit';
    case STOP = 'stop';
    case STOP_LIMIT = 'stop_limit';
}


