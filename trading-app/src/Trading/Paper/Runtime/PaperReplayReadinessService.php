<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Configuration\PaperPrivateConfigurationReader;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;

final readonly class PaperReplayReadinessService
{
    /** @var list<string> */
    private const CELL_STATE_FAILURES = [
        'paper_execution_cell_identity_conflict',
        'paper_execution_checkpoint_missing',
        'paper_execution_checkpoint_corrupt',
        'paper_execution_dataset_identity_missing',
        'paper_execution_dataset_identity_corrupt',
        'paper_execution_dataset_identity_conflict',
        'paper_execution_cell_state_invalid',
    ];

    public function __construct(
        private PaperDatasetVerifier $verifier,
        private PaperPrivateConfigurationReader $configurationReader,
        private PaperConfigurationSnapshotFactory $snapshots,
        private PaperProfileRegistry $profiles,
        private PaperReplayClock $clock,
        private PaperEventCoordinatorInterface $coordinator,
        private PaperReplayReader $reader,
        private PaperExecutionStoreInterface $store,
        private PaperReplayCheckpointResolver $checkpoints,
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
        try {
            $manifest = $this->verifier->verifyForBaseline($datasetPath, $this->reader->eventLimit());
        } catch (\RuntimeException $failure) {
            if ($failure->getMessage() === 'paper_dataset_event_limit_exceeded') {
                throw new \RuntimeException('paper_replay_event_limit_exceeded');
            }

            throw $failure;
        }
        if ($manifest->startExchangeTimestamp === null) {
            throw new \LogicException('paper_replay_clock_start_missing');
        }
        $this->clock->assertCanAdvanceTo($manifest->startExchangeTimestamp);

        $eligibility = $this->profiles->require($profile);
        $cell = PaperExecutionCell::create($manifest->network, $manifest->venue, $snapshot->id, $profile, $runId);
        $this->coordinator->assertReady($cell, $eligibility, array_keys($manifest->symbols));
        try {
            $state = $this->store->inspectCell($cell, $eligibility);
        } catch (\Throwable $failure) {
            $this->throwNormalizedStateFailure($failure);
        }
        if ($state->killed) {
            throw new \LogicException('paper_execution_cell_killed');
        }
        if ($state->datasetId !== null
            && ($manifest->eventsFileSha256 === null
                || $state->datasetId !== $manifest->datasetId
                || !hash_equals((string) $state->eventsFileSha256, $manifest->eventsFileSha256))
        ) {
            throw new \LogicException('paper_execution_dataset_identity_conflict');
        }
        $consumerId = $this->checkpoints->consumerId($cell);
        try {
            $checkpoint = $state->registered
                ? $this->checkpoints->resolve($cell, $manifest, $consumerId)
                : null;
        } catch (\Throwable $failure) {
            $this->throwNormalizedStateFailure($failure);
        }
        if ($checkpoint !== null) {
            $this->reader->assertCanResume($datasetPath, $consumerId, $checkpoint, $manifest);
        }

        return new PaperReplayPreparation(
            $manifest,
            $snapshot,
            $eligibility,
            $cell,
            $consumerId,
            $checkpoint,
        );
    }

    private function assertAbsolute(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('paper_execution_path_must_be_absolute');
        }
    }

    private function throwNormalizedStateFailure(\Throwable $failure): never
    {
        if (in_array($failure->getMessage(), self::CELL_STATE_FAILURES, true)) {
            throw new \LogicException($failure->getMessage());
        }

        throw new \RuntimeException('paper_execution_state_inspection_failed');
    }
}
