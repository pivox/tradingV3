<?php

declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Exchange\Dto\ExchangeOrderDto;

final readonly class RestingEntryState
{
    public const ACTIVE = 'active';
    public const FILLED = 'filled';
    public const CLOSED = 'closed';

    public function __construct(
        public string $status,
        public float $filledQuantity = 0.0,
        public bool $remainderActive = false,
        public ?ExchangeOrderDto $order = null,
        public ?float $averagePrice = null,
    ) {
    }
}
