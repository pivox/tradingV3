<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution;

final readonly class PaperExecutionCounters
{
    public function __construct(
        public int $requested,
        public int $acknowledged,
        public int $retried,
        public int $failed,
    ) {
    }

    /** @param array<string, int> $counts */
    public static function fromJournal(array $counts): self
    {
        return new self(
            $counts['effect_requested'] ?? 0,
            $counts['effect_acknowledged'] ?? 0,
            $counts['effect_retried'] ?? 0,
            $counts['effect_failed'] ?? 0,
        );
    }
}
