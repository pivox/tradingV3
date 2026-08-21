<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Replay\PaperReplayCheckpoint;

final readonly class PaperReplayPreparation
{
    public const READINESS_SCHEMA = 'paper-replay-readiness-v1';

    public function __construct(
        public PaperDatasetManifest $manifest,
        public PaperConfigurationSnapshot $snapshot,
        public PaperProfileEligibility $eligibility,
        public PaperExecutionCell $cell,
        public string $consumerId,
        public ?PaperReplayCheckpoint $checkpoint,
        public ?string $blocker = null,
    ) {
    }

    public function assertRunnable(): void
    {
        if ($this->blocker !== null) {
            throw new \LogicException($this->blocker);
        }
    }

    /** @return array<string, mixed> */
    public function readinessPayload(): array
    {
        if ($this->manifest->eventsFileSha256 === null) {
            throw new \LogicException('paper_execution_dataset_checksum_missing');
        }

        $ready = $this->blocker === null;
        $payload = [
            'schema_version' => self::READINESS_SCHEMA,
            'ready' => $ready,
            'runtime_ready' => $ready,
            'baseline_eligible' => $this->eligibility === PaperProfileEligibility::BASELINE_ELIGIBLE,
            'profile_eligibility' => $this->eligibility->value,
            'profile' => $this->cell->strategyProfile,
            'run_id' => $this->cell->runId,
            'configuration_snapshot_id' => $this->snapshot->id,
            'execution_cell_id' => $this->cell->id,
            'source' => [
                'kind' => 'verified_public_replay',
                'dataset_id' => $this->manifest->datasetId,
                'events_file_sha256' => $this->manifest->eventsFileSha256,
                'schema_version' => $this->manifest->schemaVersion,
                'network' => $this->manifest->network->value,
                'venue' => $this->manifest->venue->value,
                'quality' => $this->manifest->quality->value,
                'symbols' => array_keys($this->manifest->symbols),
                'channels' => $this->manifest->channels,
            ],
            'clock' => [
                'type' => 'paper_replay_clock',
                'controlled' => true,
            ],
            'execution' => [
                'mode' => 'paper',
                'exchange' => 'fake',
                'private_clients_enabled' => false,
                'mainnet_write_enabled' => false,
                'demo_testnet_write_enabled' => false,
            ],
        ];
        if ($this->cell->modernIdentity !== null) {
            $identity = $this->cell->modernIdentity;
            $payload['strategy'] = [
                'schema_version' => 2,
                'mode_id' => $identity->modeId,
                'mode_version' => $identity->modeVersion,
                'setup_id' => $identity->setupId,
                'setup_version' => $identity->setupVersion,
                'side' => $identity->side,
                'config_hash' => $identity->configHash,
                'condition_catalog_hash' => $identity->conditionCatalogHash,
            ];
        }
        if ($this->blocker !== null) {
            $payload['blocker'] = $this->blocker;
        }

        return $payload;
    }
}
