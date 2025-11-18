<?php

declare(strict_types=1);

namespace App\Trading\Dto;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Contract\Provider\Dto\OrderDto as ProviderOrderDto;
use Brick\Math\BigDecimal;

/**
 * DTO pour les ordres dans le contexte Trading/Storage
 */
final class OrderDto
{
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $clientOrderId,
        public readonly string $symbol,
        public readonly OrderSide $side,
        public readonly OrderType $type,
        public readonly OrderStatus $status,
        public readonly BigDecimal $price,
        public readonly BigDecimal $quantity,
        public readonly BigDecimal $filledQuantity,
        public readonly ?BigDecimal $avgFilledPrice,
        public readonly ?\DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt,
        public readonly array $raw = []
    ) {}

    /**
     * Mappe depuis un ProviderOrderDto
     */
    public static function fromProviderDto(ProviderOrderDto $providerDto): self
    {
        return new self(
            orderId: $providerDto->orderId,
            clientOrderId: $providerDto->metadata['client_order_id'] ?? null,
            symbol: $providerDto->symbol,
            side: $providerDto->side,
            type: $providerDto->type,
            status: $providerDto->status,
            price: $providerDto->price ?? BigDecimal::zero(),
            quantity: $providerDto->quantity,
            filledQuantity: $providerDto->filledQuantity,
            avgFilledPrice: $providerDto->averagePrice,
            createdAt: $providerDto->createdAt,
            updatedAt: $providerDto->updatedAt,
            raw: $providerDto->metadata
        );
    }
}


