<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

final class ExecutionSelectionDto
{
    /**
     * @param TimeframeDecisionDto[] $timeframeDecisions
     */
    public function __construct(
        public readonly ?string $selectedTimeframe,    // '15m', '5m', '1m' ou null si aucun
        public readonly ?string $selectedSide,         // 'long', 'short' ou null
        public readonly ?string $reasonIfNone,         // pourquoi aucun TF retenu
        public readonly array $timeframeDecisions,     // décisions détaillées d’exécution
    ) {
    }

    public function toArray(): array
    {
        return [
            'selected_timeframe' => $this->selectedTimeframe,
            'selected_side' => $this->selectedSide,
            'reason_if_none' => $this->reasonIfNone,
            'timeframe_decisions' => $this->timeframeDecisions
        ];
    }
}
