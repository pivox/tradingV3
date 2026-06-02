<?php

declare(strict_types=1);

namespace App\Front\ViewModel;

final readonly class CockpitSummaryView
{
    /**
     * @param array<string, mixed> $mode
     * @param array<string, mixed> $system
     */
    public function __construct(
        public RiskSummaryView $risk,
        public DecisionSummaryView $decisions,
        public array $mode,
        public array $system,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'risk' => $this->risk->toArray(),
            'decisions' => $this->decisions->toArray(),
            'mode' => $this->mode,
            'system' => $this->system,
        ];
    }
}
