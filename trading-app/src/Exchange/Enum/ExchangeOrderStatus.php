<?php

declare(strict_types=1);

namespace App\Exchange\Enum;

enum ExchangeOrderStatus: string
{
    case PENDING = 'pending';
    case OPEN = 'open';
    case PARTIALLY_FILLED = 'partially_filled';
    case FILLED = 'filled';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case UNKNOWN = 'unknown';
}
