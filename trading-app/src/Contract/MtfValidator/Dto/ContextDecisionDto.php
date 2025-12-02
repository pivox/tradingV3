<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

final class ContextDecisionDto
{
    /**
     * @param TimeframeDecisionDto[] $timeframeDecisions
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly ?string $reasonIfInvalid,
        public readonly array $timeframeDecisions,
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
            'valid' => $this->isValid,
            'reason_if_invalid' => $this->reasonIfInvalid,
            'timeframe_decisions' => $normalizedDecisions,
        ];
    }
}
