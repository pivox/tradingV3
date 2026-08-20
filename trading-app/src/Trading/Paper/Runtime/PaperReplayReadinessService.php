<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Configuration\PaperPrivateConfigurationReader;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\Replay\PaperReplayClock;

final readonly class PaperReplayReadinessService
{
    public function __construct(
        private PaperDatasetVerifier $verifier,
        private PaperPrivateConfigurationReader $configurationReader,
        private PaperConfigurationSnapshotFactory $snapshots,
        private PaperProfileRegistry $profiles,
        private PaperReplayClock $clock,
        private PaperEventCoordinatorInterface $coordinator,
    ) {
    }

    public function prepare(
        #[\SensitiveParameter] string $datasetPath,
        #[\SensitiveParameter] string $configurationPath,
        string $profile,
        string $runId,
    ): PaperReplayPreparation {
        $this->assertAbsolute($datasetPath);
        $configuration = $this->configurationReader->read($configurationPath);
        $snapshot = $this->snapshots->create($configuration);
        $manifest = $this->verifier->verifyForBaseline($datasetPath);
        if ($manifest->startExchangeTimestamp === null) {
            throw new \LogicException('paper_replay_clock_start_missing');
        }
        $this->clock->assertCanAdvanceTo($manifest->startExchangeTimestamp);

        $eligibility = $this->profiles->require($profile);
        $cell = PaperExecutionCell::create($manifest->network, $manifest->venue, $snapshot->id, $profile, $runId);
        $this->coordinator->assertReady($cell, $eligibility, array_keys($manifest->symbols));

        return new PaperReplayPreparation($manifest, $snapshot, $eligibility, $cell);
    }

    private function assertAbsolute(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('paper_execution_path_must_be_absolute');
        }
    }
}
