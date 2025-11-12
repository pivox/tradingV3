<?php
declare(strict_types=1);

namespace App\TradeEntry\Message;

final class LimitFillWatchMessage
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $exchangeOrderId,
        public readonly string $clientOrderId,
        public readonly int $cancelAfterSec,
        public readonly int $tries = 0,
        public readonly ?string $decisionKey = null,
    ) {}
}

