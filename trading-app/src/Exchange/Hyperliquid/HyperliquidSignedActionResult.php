<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final readonly class HyperliquidSignedActionResult
{
    /** @param list<array<string, mixed>> $statuses */
    public function __construct(
        public string $outcome,
        public array $statuses,
        public ?string $reason,
        public string $correlationId,
    ) {
    }
}
