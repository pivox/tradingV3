<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;

final readonly class PaperReplayPreparation
{
    public const READINESS_SCHEMA = 'paper-replay-readiness-v1';

    public function __construct(
        public PaperDatasetManifest $manifest,
        public PaperConfigurationSnapshot $snapshot,
        public PaperProfileEligibility $eligibility,
        public PaperExecutionCell $cell,
    ) {
    }

    /** @return array<string, mixed> */
    public function readinessPayload(): array
    {
        if ($this->manifest->eventsFileSha256 === null) {
            throw new \LogicException('paper_execution_dataset_checksum_missing');
        }

        return [
            'schema_version' => self::READINESS_SCHEMA,
            'ready' => true,
            'runtime_ready' => true,
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
                'recorder_version' => $this->manifest->recorderVersion,
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
    }
}
