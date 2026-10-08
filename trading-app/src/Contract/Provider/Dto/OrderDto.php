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
        public readonly array $metadata = [],
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            orderId: (string) $data['order_id'],
            symbol: (string) $data['symbol'],
            side: OrderSide::from((string) $data['side']),
            type: OrderType::from((string) $data['type']),
            status: OrderStatus::from((string) $data['status']),
            quantity: BigDecimal::of((string) $data['quantity']),
            price: isset($data['price']) && $data['price'] !== null
                ? BigDecimal::of((string) $data['price'])
                : null,
            stopPrice: isset($data['stop_price']) && $data['stop_price'] !== null
                ? BigDecimal::of((string) $data['stop_price'])
                : null,
            filledQuantity: BigDecimal::of((string) ($data['filled_quantity'] ?? '0')),
            remainingQuantity: BigDecimal::of((string) ($data['remaining_quantity'] ?? $data['quantity'])),
            averagePrice: isset($data['average_price']) && $data['average_price'] !== null
                ? BigDecimal::of((string) $data['average_price'])
                : null,
            createdAt: new \DateTimeImmutable((string) $data['created_at']),
            updatedAt: isset($data['updated_at']) && $data['updated_at'] !== null
                ? new \DateTimeImmutable((string) $data['updated_at'])
                : null,
            filledAt: isset($data['filled_at']) && $data['filled_at'] !== null
                ? new \DateTimeImmutable((string) $data['filled_at'])
                : null,
            metadata: $data['metadata'] ?? [],
        );
    }

}
