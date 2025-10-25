<?php

declare(strict_types=1);

namespace App\Contract\Provider\Dto;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Common\Enum\OrderStatus;
use Brick\Math\BigDecimal;

/**
 * DTO pour les ordres
 */
final class OrderDto extends BaseDto
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $symbol,
        public readonly OrderSide $side,
        public readonly OrderType $type,
        public readonly OrderStatus $status,
        public readonly BigDecimal $quantity,
        public readonly ?BigDecimal $price,
        public readonly ?BigDecimal $stopPrice,
        public readonly BigDecimal $filledQuantity,
        public readonly BigDecimal $remainingQuantity,
        public readonly ?BigDecimal $averagePrice,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        public readonly ?\DateTimeImmutable $filledAt = null,
        public readonly array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            orderId: $data['order_id'],
            symbol: $data['symbol'],
            side: OrderSide::from($data['side']),
            type: OrderType::from($data['type']),
            status: OrderStatus::from($data['status']),
            quantity: BigDecimal::of($data['quantity']),
            price: isset($data['price']) ? BigDecimal::of($data['price']) : null,
            stopPrice: isset($data['stop_price']) ? BigDecimal::of($data['stop_price']) : null,
            filledQuantity: BigDecimal::of($data['filled_quantity'] ?? 0),
            remainingQuantity: BigDecimal::of($data['remaining_quantity'] ?? $data['quantity']),
            averagePrice: isset($data['average_price']) ? BigDecimal::of($data['average_price']) : null,
            createdAt: new \DateTimeImmutable($data['created_at']),
            updatedAt: isset($data['updated_at']) ? new \DateTimeImmutable($data['updated_at']) : null,
            filledAt: isset($data['filled_at']) ? new \DateTimeImmutable($data['filled_at']) : null,
            metadata: $data['metadata'] ?? []
        );
    }
}


