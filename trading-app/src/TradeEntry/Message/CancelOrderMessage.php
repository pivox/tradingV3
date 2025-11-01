<?php

declare(strict_types=1);

namespace App\TradeEntry\Message;

final class CancelOrderMessage
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $exchangeOrderId,
        public readonly string $clientOrderId,
        public readonly ?string $decisionKey = null
    ) {}
}
