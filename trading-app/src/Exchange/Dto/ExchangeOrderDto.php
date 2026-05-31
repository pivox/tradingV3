<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;

final readonly class ExchangeOrderDto
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public string $symbol,
        public string $exchangeOrderId,
        public ?string $clientOrderId,
        public ExchangeOrderSide $side,
        public ?ExchangePositionSide $positionSide,
        public ExchangeOrderType $orderType,
        public ExchangeOrderStatus $status,
        public float $quantity,
        public float $filledQuantity,
        public float $remainingQuantity,
        public ?float $price,
        public ?float $averagePrice,
        public ?float $stopPrice,
        public bool $reduceOnly,
        public bool $postOnly,
        public ?ExchangeTimeInForce $timeInForce,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $updatedAt = null,
        public array $metadata = [],
    ) {
    }
}
