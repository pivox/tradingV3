<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Dto;

use App\MtfValidator\Service\Dto\Internal\InternalRunSummaryDto;

/**
 * DTO pour le résultat d'exécution MTF
 */
final class MtfRunResultDto
{
    public function __construct(
        public readonly string $runId,
        public readonly array $symbols,
        public readonly array $results,
        public readonly InternalRunSummaryDto $summary,
        public readonly \DateTimeImmutable $startedAt,
        public readonly \DateTimeImmutable $completedAt,
        public readonly float $executionTimeSeconds
    ) {}

    public function isSuccess(): bool
    {
        return $this->summary->successRate > 0;
    }

    public function getSymbolCount(): int
    {
        return count($this->symbols);
    }

    public function getSuccessfulCount(): int
    {
        return $this->summary->symbolsSuccessful;
    }

    public function getFailedCount(): int
    {
        return $this->summary->symbolsFailed;
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'symbols' => $this->symbols,
            'results' => $this->results,
            'summary' => $this->summary->toArray(),
            'started_at' => $this->startedAt->format('Y-m-d H:i:s'),
            'completed_at' => $this->completedAt->format('Y-m-d H:i:s'),
            'execution_time_seconds' => $this->executionTimeSeconds
        ];
    }
}
