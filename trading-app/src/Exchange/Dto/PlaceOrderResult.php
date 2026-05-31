<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Exchange\Enum\ExchangeOrderStatus;

final readonly class PlaceOrderResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public bool $accepted,
        public string $symbol,
        public string $clientOrderId,
        public ?string $exchangeOrderId,
        public ExchangeOrderStatus $status,
        public \DateTimeImmutable $submittedAt,
        public ?ExchangeOrderDto $order = null,
        public array $metadata = [],
    ) {
        if (trim($symbol) === '') {
            throw new \InvalidArgumentException('symbol cannot be blank');
        }
        if (trim($clientOrderId) === '') {
            throw new \InvalidArgumentException('clientOrderId cannot be blank');
        }
    }
}
