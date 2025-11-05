<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Application;

/**
 * Small wrapper exposing the indicator snapshot persistence routine used
 * within the run coordinator.
 */
final class SnapshotPersister
{
    public function __construct(private readonly RunCoordinator $runCoordinator)
    {
    }

    public function persist(string $symbol, string $timeframe, array $result): void
    {
        $this->runCoordinator->persistIndicatorSnapshot($symbol, $timeframe, $result);
    }
}
