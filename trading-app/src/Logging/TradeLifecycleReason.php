<?php

declare(strict_types=1);

namespace App\Logging;

final class TradeLifecycleReason
{
    // Skips TradeEntry / zone
    public const SKIPPED_OUT_OF_ZONE = 'skipped_out_of_zone';
    public const ZONE_FAR_FROM_MARKET = 'zone_far_from_market';

    // Submit failures
    public const SUBMIT_FAILED = 'submit_failed';
    public const SUBMIT_REJECTED_EXCHANGE = 'submit_rejected_exchange';
    public const DAILY_LOSS_LIMIT = 'daily_loss_limit_reached';

    // Position guards
    public const LEVERAGE_TOO_LOW = 'leverage_too_low';

    // Expiration / cancel-after
    public const CANCEL_AFTER_TIMEOUT = 'cancel_after_timeout';

    // MTF / readiness issues
    public const MTF_NOT_READY = 'mtf_not_ready';

    private function __construct()
    {
    }
}
