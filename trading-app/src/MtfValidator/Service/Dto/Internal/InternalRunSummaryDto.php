<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto\Internal;

/**
 * Représente le résumé interne d'une exécution MTF.
 */
final class InternalRunSummaryDto
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
        public readonly int $contractsProcessed,
        public readonly ?string $lastSuccessfulTimeframe,
        public readonly bool $dryRun,
        public readonly bool $forceRun,
        public readonly ?string $currentTf,
        public readonly \DateTimeImmutable $timestamp,
        public readonly string $status,
        public readonly ?string $message = null
    ) {
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
            'contracts_processed' => $this->contractsProcessed,
            'last_successful_timeframe' => $this->lastSuccessfulTimeframe,
            'dry_run' => $this->dryRun,
            'force_run' => $this->forceRun,
            'current_tf' => $this->currentTf,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
