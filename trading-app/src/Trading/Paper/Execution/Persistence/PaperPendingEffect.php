<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

final readonly class PaperPendingEffect
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $sourcePosition,
        public string $effectKey,
        public array $payload,
        public int $journalOrdinal,
    ) {
    }
}
