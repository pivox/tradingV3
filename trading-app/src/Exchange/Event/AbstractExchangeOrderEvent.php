<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Exchange\Dto\ExchangeOrderDto;

abstract readonly class AbstractExchangeOrderEvent extends AbstractExchangeEvent
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private ExchangeOrderDto $order,
        \DateTimeImmutable $occurredAt,
        array $payload = [],
    ) {
        parent::__construct(
            $order->exchange,
            $order->marketType,
            $order->symbol,
            $occurredAt,
            $payload,
        );
    }

    public function order(): ExchangeOrderDto
    {
        return $this->order;
    }
}
