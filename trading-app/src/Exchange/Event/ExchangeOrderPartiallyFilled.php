<?php

declare(strict_types=1);

namespace App\Exchange\Event;

final readonly class ExchangeOrderPartiallyFilled extends AbstractExchangeOrderEvent
{
    public function eventType(): string
    {
        return 'exchange.order.partially_filled';
    }
}
