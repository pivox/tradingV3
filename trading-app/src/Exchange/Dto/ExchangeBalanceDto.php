<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final readonly class ExchangeBalanceDto
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public string $currency,
        public float $available,
        public ?float $total = null,
        public ?float $equity = null,
        public ?float $unrealizedPnl = null,
        public array $metadata = [],
    ) {
    }
}
