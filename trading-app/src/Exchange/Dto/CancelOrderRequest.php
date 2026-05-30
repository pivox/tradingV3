<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final readonly class CancelOrderRequest
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public string $symbol,
        public ?string $exchangeOrderId = null,
        public ?string $clientOrderId = null,
        public array $metadata = [],
    ) {
        if (trim($this->symbol) === '') {
            throw new \InvalidArgumentException('symbol cannot be blank');
        }

        $hasExchangeOrderId = $this->exchangeOrderId !== null && trim($this->exchangeOrderId) !== '';
        $hasClientOrderId = $this->clientOrderId !== null && trim($this->clientOrderId) !== '';
        if (!$hasExchangeOrderId && !$hasClientOrderId) {
            throw new \InvalidArgumentException('exchangeOrderId or clientOrderId is required');
        }
    }
}
