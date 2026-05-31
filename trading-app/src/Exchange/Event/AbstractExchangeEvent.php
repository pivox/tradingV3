<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

abstract readonly class AbstractExchangeEvent implements ExchangeEventInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        private Exchange $exchange,
        private MarketType $marketType,
        private string $symbol,
        private \DateTimeImmutable $occurredAt,
        private array $payload = [],
    ) {
        if (trim($this->symbol) === '') {
            throw new \InvalidArgumentException('exchange event symbol cannot be blank');
        }
    }

    public function exchange(): Exchange
    {
        return $this->exchange;
    }

    public function marketType(): MarketType
    {
        return $this->marketType;
    }

    public function symbol(): string
    {
        return strtoupper($this->symbol);
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function payload(): array
    {
        return $this->payload;
    }
}
