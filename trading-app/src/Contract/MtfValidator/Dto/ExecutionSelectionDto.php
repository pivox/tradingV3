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
        // Convertir les objets TimeframeDecisionDto en tableaux
        $normalizedDecisions = [];
        foreach ($this->timeframeDecisions as $decision) {
            if ($decision instanceof TimeframeDecisionDto) {
                $normalizedDecisions[] = $decision->toArray();
            } elseif (is_array($decision)) {
                $normalizedDecisions[] = $decision;
            } else {
                $normalizedDecisions[] = $decision;
            }
        }

        return [
            'selected_timeframe' => $this->selectedTimeframe,
            'selected_side' => $this->selectedSide,
            'reason_if_none' => $this->reasonIfNone,
            'timeframe_decisions' => $normalizedDecisions,
        ];
    }
}
