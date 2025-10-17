<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Order;

final class OrderDraft
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public readonly string $clientOrderId,
        public readonly array $payload,
    ) {
    }
}
