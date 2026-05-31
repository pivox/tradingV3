<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Exchange\Dto\ExchangeFillDto;

final readonly class ExchangeFillReceived extends AbstractExchangeEvent
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private ExchangeFillDto $fill,
        array $payload = [],
    ) {
        parent::__construct(
            $fill->exchange,
            $fill->marketType,
            $fill->symbol,
            $fill->filledAt,
            $payload,
        );
    }

    public function eventType(): string
    {
        return 'exchange.fill.received';
    }

    public function fill(): ExchangeFillDto
    {
        return $this->fill;
    }
}
