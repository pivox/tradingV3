<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;

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
    }

    private static function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('%s cannot be blank', $field));
        }
    }
}
