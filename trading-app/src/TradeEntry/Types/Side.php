<?php
declare(strict_types=1);

namespace App\TradeEntry\Types;

enum Side: string
{
    case Long = 'long';
    case Short = 'short';
}
