<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Persistence;

interface RunSinkInterface
{
    /**
     * Called when a run starts. Meta can include options such as dry_run, force_run, current_tf, workers, user_id, ip_address.
     * @param array<string,mixed> $meta
     */
    public function onRunStart(string $runId, array $meta): void;

    /**
     * Persist a single symbol result for the given run.
     * @param array<string,mixed> $symbolResult Normalized SymbolResultDto->toArray()
     */
    public function onSymbolResult(string $runId, array $symbolResult): void;

    /**
     * Called when the run completes with final summary and full results map.
     * @param array<string,mixed> $summary RunSummaryDto->toArray()
     * @param array<string,array<string,mixed>> $results Map of symbol => result
     */
    public function onRunCompleted(string $runId, array $summary, array $results): void;

    /**
     * Persist performance metrics for a run.
     * @param array<string,mixed> $report Typically PerformanceProfiler->getReport()
     */
    public function onMetrics(string $runId, array $report): void;
}

