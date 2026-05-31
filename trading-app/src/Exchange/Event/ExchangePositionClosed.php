<?php

declare(strict_types=1);

namespace App\Exchange\Event;

final readonly class ExchangePositionClosed extends AbstractExchangePositionEvent
{
    public function eventType(): string
    {
        return 'exchange.position.closed';
    }
}
