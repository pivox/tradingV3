<?php

declare(strict_types=1);

namespace App\Provider\Context;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

/**
 * Value object describing the exchange/market tuple.
 */
final class ExchangeContext
{
    public function __construct(
        public readonly Exchange $exchange,
        public readonly MarketType $marketType,
    ) {
    }

    public static function fromEnums(Exchange $exchange, MarketType $marketType): self
    {
        return new self($exchange, $marketType);
    }

    public function equals(self $other): bool
    {
        return $this->exchange === $other->exchange && $this->marketType === $other->marketType;
    }

    public function key(): string
    {
        return sprintf('%s::%s', $this->exchange->value, $this->marketType->value);
    }

    public function __toString(): string
    {
        return $this->key();
    }
}

