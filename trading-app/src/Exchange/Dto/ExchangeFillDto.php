<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;

final readonly class ExchangeFillDto
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
        public ?string $fillId,
        public ExchangeOrderSide $side,
        public ?ExchangePositionSide $positionSide,
        public float $quantity,
        public float $price,
        public ?float $fee,
        public ?string $feeCurrency,
        public \DateTimeImmutable $filledAt,
        public array $metadata = [],
    ) {
    }
}
