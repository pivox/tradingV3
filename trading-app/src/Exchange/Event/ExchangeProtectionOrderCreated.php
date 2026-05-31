<?php

declare(strict_types=1);

namespace App\Exchange\Event;

final readonly class ExchangeProtectionOrderCreated extends AbstractExchangeOrderEvent
{
    public function eventType(): string
    {
        return 'exchange.protection_order.created';
    }
}
