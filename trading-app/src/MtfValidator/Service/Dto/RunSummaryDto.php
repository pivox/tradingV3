<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto;

/**
 * DTO pour le résumé d'exécution
 */
final class RunSummaryDto
{
    public function __construct(
        public readonly string $runId,
        public readonly float $executionTimeSeconds,
        public readonly int $symbolsRequested,
        public readonly int $symbolsProcessed,
        public readonly int $symbolsSuccessful,
        public readonly int $symbolsFailed,
        public readonly int $symbolsSkipped,
        public readonly float $successRate,
        public readonly bool $dryRun,
        public readonly bool $forceRun,
        public readonly ?string $currentTf,
        public readonly \DateTimeImmutable $timestamp,
        public readonly string $status
    ) {}

    public function getTotalSymbols(): int
    {
        return $this->symbolsRequested;
    }

    public function getProcessedSymbols(): int
    {
        return $this->symbolsProcessed;
    }

    public function getSuccessRate(): float
    {
        return $this->successRate;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'execution_time_seconds' => $this->executionTimeSeconds,
            'symbols_requested' => $this->symbolsRequested,
            'symbols_processed' => $this->symbolsProcessed,
            'symbols_successful' => $this->symbolsSuccessful,
            'symbols_failed' => $this->symbolsFailed,
            'symbols_skipped' => $this->symbolsSkipped,
            'success_rate' => $this->successRate,
            'dry_run' => $this->dryRun,
            'force_run' => $this->forceRun,
            'current_tf' => $this->currentTf,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'status' => $this->status
        ];
    }
}
