<?php

declare(strict_types=1);

namespace App\Logging;

final class TradeLifecycleEventType
{
    public const SYMBOL_SKIPPED = 'symbol_skipped';
    public const ORDER_SUBMITTED = 'order_submitted';
    public const ORDER_EXPIRED = 'order_expired';
    public const POSITION_OPENED = 'position_opened';
    public const POSITION_CLOSED = 'position_closed';

    private function __construct()
    {
    }
}
