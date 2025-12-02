<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator\Dto;

/**
 * DTO pour les réponses d'exécution MTF
 */
final class MtfRunResponseDto
{
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
        public readonly float $executionTimeSeconds,
        public readonly int $symbolsRequested,
        public readonly int $symbolsProcessed,
        public readonly int $symbolsSuccessful,
        public readonly int $symbolsFailed,
        public readonly int $symbolsSkipped,
        public readonly float $successRate,
        public readonly array $results,
        public readonly array $errors,
        public readonly \DateTimeImmutable $timestamp,
        public readonly ?string $message = null
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    public function isPartialSuccess(): bool
    {
        return $this->status === 'partial_success';
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }

    public function getTotalSymbols(): int
    {
        return $this->symbolsRequested;
    }

    public function getProcessedSymbols(): int
    {
        return $this->symbolsProcessed;
    }

    public function toArray(): array
    {
        // Convertir les objets MtfResultDto en tableaux pour la sérialisation JSON
        $normalizedResults = [];
        foreach ($this->results as $entry) {
            if (is_array($entry)) {
                $symbol = $entry['symbol'] ?? null;
                $result = $entry['result'] ?? null;
                
                // Si result est un objet MtfResultDto, le convertir en tableau
                if ($result instanceof MtfResultDto) {
                    $normalizedResults[] = [
                        'symbol' => $symbol,
                        'result' => $result->toArray(),
                    ];
                } else {
                    // Sinon, garder tel quel (déjà un tableau ou autre type)
                    $normalizedResults[] = $entry;
                }
            } else {
                $normalizedResults[] = $entry;
            }
        }

        return [
            'run_id' => $this->runId,
            'status' => $this->status,
            'execution_time_seconds' => $this->executionTimeSeconds,
            'symbols_requested' => $this->symbolsRequested,
            'symbols_processed' => $this->symbolsProcessed,
            'symbols_successful' => $this->symbolsSuccessful,
            'symbols_failed' => $this->symbolsFailed,
            'symbols_skipped' => $this->symbolsSkipped,
            'success_rate' => $this->successRate,
            'results' => $normalizedResults,
            'errors' => $this->errors,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'message' => $this->message
        ];
    }
}
