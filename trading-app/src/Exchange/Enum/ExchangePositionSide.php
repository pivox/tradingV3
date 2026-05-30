<?php

declare(strict_types=1);

namespace App\Exchange\Enum;

enum ExchangePositionSide: string
{
    case LONG = 'long';
    case SHORT = 'short';
}
