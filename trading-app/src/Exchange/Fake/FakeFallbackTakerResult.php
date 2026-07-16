<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Exchange\Dto\ExchangeOrderDto;

final readonly class FakeFallbackTakerResult
{
    public function __construct(
        public bool $executed,
        public bool $idempotentReplay,
        public string $reason,
        public ?ExchangeOrderDto $parentOrder,
        public ?ExchangeOrderDto $fallbackOrder,
        public ?float $slippageBps = null,
    ) {
    }
}
