<?php

declare(strict_types=1);

namespace App\Common\Enum;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case FILLED = 'filled';
    case PARTIALLY_FILLED = 'partially_filled';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
}


