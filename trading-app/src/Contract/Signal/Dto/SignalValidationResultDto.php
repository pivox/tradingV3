<?php

declare(strict_types=1);

namespace App\Contract\Signal\Dto;

use App\Common\Dto\SignalDto;
use App\Common\Enum\SignalSide;
use DateTimeImmutable;

/**
 * DTO de résultat complet d'une validation de signal.
 */
final readonly class SignalValidationResultDto
{
    public function __construct(
        public SignalEvaluationDto $evaluation,
        public SignalValidationContextDto $context,
        public string $status,
        public SignalSide $finalSignal,
    ) {
    }

    public function timeframeKey(): string
    {
        return $this->evaluation->timeframe->value;
    }

    public function finalSignalValue(): string
    {
        return $this->finalSignal->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluationArray(): array
    {
        return $this->evaluation->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function contextArray(): array
    {
        return $this->context->toArray();
    }

    /**
     * Retourne un tableau structuré équivalent à l'ancien format.
     *
     * @return array{
     *     signals: array<string, array<string, mixed>>,
     *     final: array{signal: string},
     *     status: string,
     *     context: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        $evaluation = $this->evaluationArray();

        return [
            'signals' => [
                $this->timeframeKey() => array_merge(
                    $evaluation,
                    [
                        'context_aligned' => $this->context->aligned,
                        'context_dir' => $this->context->direction,
                        'context_signals' => $this->context->signals,
                        'context_fully_populated' => $this->context->fullyPopulated,
                        'context_fully_aligned' => $this->context->fullyAligned,
                    ]
                ),
            ],
            'final' => ['signal' => $this->finalSignal->value],
            'status' => $this->status,
            'context' => $this->contextArray(),
        ];
    }

    /**
     * Construire un SignalDto utilisable par la persistance.
     *
     * @param array<string, mixed> $additionalMeta
     */
    public function toSignalDto(string $symbol, DateTimeImmutable $klineTime, array $additionalMeta = []): SignalDto
    {
        $evaluation = $this->evaluationArray();

        $score = $evaluation['score'] ?? null;
        $trigger = $evaluation['trigger'] ?? null;
        $existingMeta = $evaluation['meta'] ?? [];

        // Nettoyer pour éviter les doublons
        unset($evaluation['meta']);

        $meta = array_merge(
            $existingMeta,
            $additionalMeta,
            [
                'evaluation' => $evaluation,
                'validation_status' => $this->status,
                'validation_context' => $this->contextArray(),
            ]
        );

        return new SignalDto(
            symbol: $symbol,
            timeframe: $this->evaluation->timeframe,
            klineTime: $klineTime,
            side: $this->finalSignal,
            score: $score,
            trigger: $trigger,
            meta: $meta
        );
    }
}

