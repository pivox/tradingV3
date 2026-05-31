<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangePositionSide;

abstract readonly class AbstractExchangePositionEvent extends AbstractExchangeEvent
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        Exchange $exchange,
        MarketType $marketType,
        string $symbol,
        private ExchangePositionSide $side,
        private float $size,
        private ?ExchangePositionDto $position,
        \DateTimeImmutable $occurredAt,
        array $payload = [],
    ) {
        parent::__construct($exchange, $marketType, $symbol, $occurredAt, $payload);
    }

    public function side(): ExchangePositionSide
    {
        return $this->side;
    }

    public function size(): float
    {
        return $this->size;
    }

    public function position(): ?ExchangePositionDto
    {
        return $this->position;
    }
}
