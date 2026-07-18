<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Exchange\Dto\ExchangeFundingDto;

final readonly class ExchangeFundingReceived extends AbstractExchangeEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        private ExchangeFundingDto $funding,
        array $payload = [],
    ) {
        parent::__construct(
            $funding->exchange,
            $funding->marketType,
            $funding->symbol,
            $funding->dueAt,
            $payload,
        );
    }

    public function eventType(): string
    {
        return 'exchange.funding.received';
    }

    public function funding(): ExchangeFundingDto
    {
        return $this->funding;
    }
}
