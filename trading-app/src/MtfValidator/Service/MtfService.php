<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\MtfValidator\Service\Application\RunCoordinator;
use App\MtfValidator\Service\Application\SnapshotPersister;
use App\MtfValidator\Service\Application\TimeframeCacheService;
use Ramsey\Uuid\UuidInterface;

final class MtfService
{
    public function __construct(
        private readonly RunCoordinator $runCoordinator,
        private readonly TimeframeCacheService $timeframeCacheService,
        private readonly SnapshotPersister $snapshotPersister,
    ) {
    }

    public function executeMtfCycle(UuidInterface $runId): \Generator
    {
        return $this->runCoordinator->executeMtfCycle($runId);
    }

    public function runForSymbol(
        UuidInterface $runId,
        string $symbol,
        \DateTimeImmutable $now,
        ?string $currentTf = null,
        bool $forceTimeframeCheck = false,
        bool $forceRun = false,
        bool $skipContextValidation = false
    ): \Generator {
        return $this->runCoordinator->runForSymbol(
            $runId,
            $symbol,
            $now,
            $currentTf,
            $forceTimeframeCheck,
            $forceRun,
            $skipContextValidation
        );
    }

    public function getTimeService(): MtfTimeService
    {
        return $this->runCoordinator->getTimeService();
    }

    public function getTimeframeCacheService(): TimeframeCacheService
    {
        return $this->timeframeCacheService;
    }

    public function getSnapshotPersister(): SnapshotPersister
    {
        return $this->snapshotPersister;
    }
}
