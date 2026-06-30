<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid\Lifecycle;

enum HyperliquidLifecycleStatus: string
{
    case ACCEPTED = 'accepted';
    case OPEN = 'open';
    case PARTIALLY_FILLED = 'partially_filled';
    case FILLED = 'filled';
    case CANCELED = 'canceled';
    case REJECTED = 'rejected';
    case FAILED = 'failed';
    case UNKNOWN_REQUIRES_RESYNC = 'unknown_requires_resync';
}
