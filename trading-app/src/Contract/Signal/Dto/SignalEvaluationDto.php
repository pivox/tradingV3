<?php

declare(strict_types=1);

namespace App\Contract\Signal\Dto;

use App\Common\Enum\SignalSide;
use App\Common\Enum\Timeframe;

/**
 * DTO représentant l'évaluation brute d'un timeframe.
 */
final readonly class SignalEvaluationDto
{
    /**
     * @param array<string, mixed> $payload Données retournées par le service de signal
     */
    public function __construct(
        public Timeframe $timeframe,
        public SignalSide $signal,
        public array $payload = []
    ) {
    }

    /**
     * Retourne les données de l'évaluation avec le timeframe/signal normalisés.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            $this->payload,
            [
                'timeframe' => $this->timeframe->value,
                'signal' => $this->signal->value,
            ]
        );
    }
}

