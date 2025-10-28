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
            'results' => $this->results,
            'errors' => $this->errors,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'message' => $this->message
        ];
    }
}
