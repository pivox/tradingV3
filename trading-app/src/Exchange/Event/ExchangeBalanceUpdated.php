<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Exchange\Dto\ExchangeBalanceDto;

final readonly class ExchangeBalanceUpdated extends AbstractExchangeEvent
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private ExchangeBalanceDto $balance,
        \DateTimeImmutable $occurredAt,
        array $payload = [],
    ) {
        parent::__construct(
            $balance->exchange,
            $balance->marketType,
            $balance->currency,
            $occurredAt,
            $payload,
        );
    }

    public function eventType(): string
    {
        return 'exchange.balance.updated';
    }

    public function balance(): ExchangeBalanceDto
    {
        return $this->balance;
    }
}
