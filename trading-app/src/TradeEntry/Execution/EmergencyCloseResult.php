<?php

declare(strict_types=1);

namespace App\TradeEntry\Execution;

final readonly class EmergencyCloseResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $status,
        public bool $closed,
        public bool $critical,
        public ?string $exchangeOrderId = null,
        public array $metadata = [],
    ) {
    }
}
