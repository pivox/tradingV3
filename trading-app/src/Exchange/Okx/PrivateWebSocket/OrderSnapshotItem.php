<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Contract\Provider\Dto\OrderDto;

final readonly class OrderSnapshotItem
{
    public \DateTimeImmutable $createdAt;

    public function __construct(
        public string $orderId,
        public string $symbol,
        public string $side,
        public string $type,
        public string $status,
        public string $quantity,
        public string $filledQuantity,
        public string $remainingQuantity,
        public ?string $price,
        public ?string $stopPrice,
        \DateTimeImmutable $createdAt,
    ) {
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function fromProviderDto(OrderDto $order): self
    {
        return new self(
            orderId: $order->orderId,
            symbol: $order->symbol,
            side: $order->side->value,
            type: $order->type->value,
            status: $order->status->value,
            quantity: (string) $order->quantity,
            filledQuantity: (string) $order->filledQuantity,
            remainingQuantity: (string) $order->remainingQuantity,
            price: $order->price === null ? null : (string) $order->price,
            stopPrice: $order->stopPrice === null ? null : (string) $order->stopPrice,
            createdAt: $order->createdAt,
        );
    }
}
