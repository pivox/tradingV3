<?php

declare(strict_types=1);

namespace App\Exchange\Event;

final readonly class ExchangeOrderFilled extends AbstractExchangeOrderEvent
{
    public function eventType(): string
    {
        return 'exchange.order.filled';
    }
}
