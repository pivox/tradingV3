<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Lifecycle;

use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;

final readonly class OkxNormalizedOrderLifecycleDto
{
    /**
     * @param list<OkxNormalizedFillDto> $fills
     * @param list<string> $qualityFlags
     * @param array<string,mixed> $redactedPayload
     */
    public function __construct(
        public OkxLifecycleStatus $status,
        public string $symbol,
        public string $exchangeOrderId,
        public ?string $clientOrderId,
        public ?ExchangeOrderSide $side,
        public ?ExchangePositionSide $positionSide,
        public ExchangeOrderType $orderType,
        public float $quantity,
        public float $filledQuantity,
        public float $remainingQuantity,
        public ?float $price,
        public ?float $averageFillPrice,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public array $fills,
        public bool $requiresResync,
        public int $deduplicatedEventCount,
        public array $qualityFlags,
        public array $redactedPayload,
    ) {
    }
}
