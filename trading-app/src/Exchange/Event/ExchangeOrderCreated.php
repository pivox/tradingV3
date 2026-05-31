<?php

declare(strict_types=1);

namespace App\Exchange\Event;

final readonly class ExchangeOrderCreated extends AbstractExchangeOrderEvent
{
    public function eventType(): string
    {
        return 'exchange.order.created';
    }
}
