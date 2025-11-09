<?php

declare(strict_types=1);

namespace App\MtfValidator\Execution;

final class ExecutionDecision
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly string $executionTimeframe,
        public readonly ?float $expectedRMultiple = null,
        public readonly ?float $entryZoneWidthPct = null,
        public readonly array $meta = [],
    ) {}
}

