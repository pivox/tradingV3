<?php

declare(strict_types=1);

namespace App\Front\ViewModel;

final readonly class DecisionSummaryView
{
    /**
     * @param list<array<string, mixed>> $runs
     * @param list<array<string, mixed>> $symbols
     * @param list<array<string, mixed>> $reasonCounts
     */
    public function __construct(
        public array $runs,
        public array $symbols,
        public array $reasonCounts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'runs' => $this->runs,
            'symbols' => $this->symbols,
            'reason_counts' => $this->reasonCounts,
        ];
    }
}
