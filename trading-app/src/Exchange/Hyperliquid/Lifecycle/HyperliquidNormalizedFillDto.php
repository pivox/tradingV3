<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid\Lifecycle;

use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangePositionSide;

final readonly class HyperliquidNormalizedFillDto
{
    /**
     * @param array<string,mixed> $redactedPayload
     */
    public function __construct(
        public string $symbol,
        public string $exchangeOrderId,
        public ?string $clientOrderId,
        public string $fillId,
        public ExchangeOrderSide $side,
        public ?ExchangePositionSide $positionSide,
        public float $quantity,
        public float $price,
        public ?float $fee,
        public ?string $feeCurrency,
        public \DateTimeImmutable $occurredAt,
        public array $redactedPayload,
    ) {
    }
}
