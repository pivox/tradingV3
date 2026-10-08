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

    public static function legacyDefault(): self
    {
        return new self(Exchange::OKX, MarketType::PERPETUAL);
    }

    public static function fromValues(mixed $exchange = null, mixed $marketType = null): self
    {
        return new self(
            self::exchangeFrom($exchange),
            self::marketTypeFrom($marketType),
        );
    }

    public static function tryFromValues(mixed $exchange = null, mixed $marketType = null): ?self
    {
        try {
            return self::fromValues($exchange, $marketType);
        } catch (UnsupportedExchangeException) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromValues(
            $data['exchange'] ?? null,
            $data['market_type'] ?? $data['marketType'] ?? null,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function tryFromArray(array $data): ?self
    {
        try {
            return self::fromArray($data);
        } catch (UnsupportedExchangeException) {
            return null;
        }
    }

    public static function resolve(?self $context): self
    {
        return $context ?? self::legacyDefault();
    }

    public static function exchangeValue(?self $context): string
    {
        return self::resolve($context)->exchange->value;
    }

    public static function marketTypeValue(?self $context): string
    {
        return self::resolve($context)->marketType->value;
    }

    public function equals(self $other): bool
    {
        return $this->exchange === $other->exchange && $this->marketType === $other->marketType;
    }

    public function key(): string
    {
        return sprintf('%s::%s', $this->exchange->value, $this->marketType->value);
    }

    public function isLegacyDefault(): bool
    {
        return $this->equals(self::legacyDefault());
    }

    public function __toString(): string
    {
        return $this->key();
    }

    private static function exchangeFrom(mixed $value): Exchange
    {
        if ($value instanceof Exchange) {
            return $value;
        }
        if ($value === null || (\is_string($value) && trim($value) === '')) {
            return Exchange::OKX;
        }
        if (!\is_string($value)) {
            throw new UnsupportedExchangeException(get_debug_type($value));
        }

        return Exchange::tryFrom(strtolower(trim($value))) ?? throw new UnsupportedExchangeException($value);
    }

    private static function marketTypeFrom(mixed $value): MarketType
    {
        return MarketType::tryFrom(self::normalize($value, MarketType::PERPETUAL->value)) ?? MarketType::PERPETUAL;
    }

    private static function normalize(mixed $value, string $fallback): string
    {
        if ($value instanceof Exchange || $value instanceof MarketType) {
            return $value->value;
        }

        if (!\is_string($value) || $value === '') {
            return $fallback;
        }

        return strtolower($value);
    }
}
