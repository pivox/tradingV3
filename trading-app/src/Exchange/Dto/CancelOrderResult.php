<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Exchange\Enum\ExchangeOrderStatus;

final readonly class CancelOrderResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public bool $cancelled,
        public string $symbol,
        public ?string $exchangeOrderId,
        public ?string $clientOrderId,
        public ExchangeOrderStatus $status,
        public array $metadata = [],
    ) {
        if (trim($symbol) === '') {
            throw new \InvalidArgumentException('symbol cannot be blank');
        }
    }
}
