<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use Brick\Math\BigDecimal;

final readonly class PlaceOrderRequest
{
    /**
     * @param array<string,mixed> $metadata Debug-only metadata, never exchange payload.
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public string $symbol,
        public ExchangeOrderSide $side,
        public ExchangePositionSide $positionSide,
        public ExchangeOrderType $orderType,
        public ExchangeTimeInForce $timeInForce,
        public float $quantity,
        public ?float $price,
        public ?float $stopPrice,
        public bool $reduceOnly,
        public bool $postOnly,
        public ?int $leverage,
        public string $marginMode,
        public string $clientOrderId,
        public ?float $attachedStopLossPrice = null,
        public ?float $attachedTakeProfitPrice = null,
        public array $metadata = [],
        public ?string $quantityDecimal = null,
        public ?string $priceDecimal = null,
        public ?string $stopPriceDecimal = null,
        public ?string $attachedStopLossPriceDecimal = null,
        public ?string $attachedTakeProfitPriceDecimal = null,
    ) {
        self::assertNotBlank($this->symbol, 'symbol');
        self::assertNotBlank($this->clientOrderId, 'clientOrderId');
        self::assertNotBlank($this->marginMode, 'marginMode');

        if ($this->quantity <= 0.0) {
            throw new \InvalidArgumentException('quantity must be greater than zero');
        }

        if ($this->orderType === ExchangeOrderType::LIMIT && ($this->price === null || $this->price <= 0.0)) {
            throw new \InvalidArgumentException('limit orders require a positive price');
        }

        foreach ([
            'price' => $this->price,
            'stopPrice' => $this->stopPrice,
            'attachedStopLossPrice' => $this->attachedStopLossPrice,
            'attachedTakeProfitPrice' => $this->attachedTakeProfitPrice,
        ] as $field => $value) {
            if ($value !== null && $value <= 0.0) {
                throw new \InvalidArgumentException(sprintf('%s must be positive when provided', $field));
            }
        }

        if ($this->leverage !== null && $this->leverage <= 0) {
            throw new \InvalidArgumentException('leverage must be positive when provided');
        }

        self::assertExactDecimalForFloat($this->quantityDecimal, $this->quantity, 'quantityDecimal');
        self::assertExactDecimalForNullableFloat($this->priceDecimal, $this->price, 'priceDecimal');
        self::assertExactDecimalForNullableFloat($this->stopPriceDecimal, $this->stopPrice, 'stopPriceDecimal');
        self::assertExactDecimalForNullableFloat(
            $this->attachedStopLossPriceDecimal,
            $this->attachedStopLossPrice,
            'attachedStopLossPriceDecimal',
        );
        self::assertExactDecimalForNullableFloat(
            $this->attachedTakeProfitPriceDecimal,
            $this->attachedTakeProfitPrice,
            'attachedTakeProfitPriceDecimal',
        );
    }

    public function exactQuantity(): string
    {
        return $this->quantityDecimal ?? self::canonicalFloat($this->quantity);
    }

    public function exactPrice(): ?string
    {
        return $this->priceDecimal ?? ($this->price !== null ? self::canonicalFloat($this->price) : null);
    }

    public function exactStopPrice(): ?string
    {
        return $this->stopPriceDecimal ?? ($this->stopPrice !== null ? self::canonicalFloat($this->stopPrice) : null);
    }

    public function exactAttachedStopLossPrice(): ?string
    {
        return $this->attachedStopLossPriceDecimal
            ?? ($this->attachedStopLossPrice !== null ? self::canonicalFloat($this->attachedStopLossPrice) : null);
    }

    public function exactAttachedTakeProfitPrice(): ?string
    {
        return $this->attachedTakeProfitPriceDecimal
            ?? ($this->attachedTakeProfitPrice !== null ? self::canonicalFloat($this->attachedTakeProfitPrice) : null);
    }

    private static function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s cannot be blank', $field));
        }
    }

    private static function assertExactDecimalForNullableFloat(?string $decimal, ?float $value, string $field): void
    {
        if ($decimal !== null && $value === null) {
            throw new \InvalidArgumentException(sprintf('%s requires its float projection', $field));
        }

        if ($value !== null) {
            self::assertExactDecimalForFloat($decimal, $value, $field);
        }
    }

    private static function assertExactDecimalForFloat(?string $decimal, float $value, string $field): void
    {
        if ($decimal === null) {
            return;
        }

        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $decimal) !== 1) {
            throw new \InvalidArgumentException(sprintf('%s must be an unsigned plain decimal string', $field));
        }

        if (BigDecimal::of($decimal)->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw new \InvalidArgumentException(sprintf('%s must be greater than zero', $field));
        }
        if (!\is_finite($value) || (float) $decimal !== $value) {
            throw new \InvalidArgumentException(sprintf('%s does not match its float projection', $field));
        }
    }

    private static function canonicalFloat(float $value): string
    {
        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
