<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Lifecycle;

enum OkxLifecycleStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case OPEN = 'open';
    case PARTIALLY_FILLED = 'partially_filled';
    case FILLED = 'filled';
    case CANCEL_PENDING = 'cancel_pending';
    case CANCELED = 'canceled';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case FAILED = 'failed';
    case UNKNOWN_REQUIRES_RESYNC = 'unknown_requires_resync';
}
