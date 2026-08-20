<?php

declare(strict_types=1);

namespace App\Trading\Paper\Runtime;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Replay\PaperReplayCheckpoint;

final readonly class PaperReplayCheckpointResolver
{
    public function __construct(private PaperExecutionStoreInterface $store)
    {
    }

    public function consumerId(PaperExecutionCell $cell): string
    {
        return 'paper-exec-' . substr($cell->id, 7, 16);
    }

    public function resolve(
        PaperExecutionCell $cell,
        PaperDatasetManifest $manifest,
        string $consumerId,
    ): ?PaperReplayCheckpoint {
        $checkpoint = $this->store->checkpoint($cell);
        $pending = $this->store->pendingEffects($cell);
        $position = $pending[0]->sourcePosition ?? $checkpoint->nextSourcePosition;
        if ($position === 0) {
            return null;
        }
        $dataset = $this->store->datasetIdentity($cell);
        if ($dataset['dataset_id'] !== $manifest->datasetId
            || $manifest->eventsFileSha256 === null
            || !hash_equals($dataset['events_file_sha256'], $manifest->eventsFileSha256)
        ) {
            throw new \LogicException('paper_execution_dataset_identity_conflict');
        }
        $events = $this->store->acknowledgedSources($cell);
        $last = $events[$position - 1] ?? null;
        if ($last === null) {
            throw new \LogicException('paper_execution_checkpoint_corrupt');
        }

        return new PaperReplayCheckpoint(
            $manifest->network,
            $dataset['dataset_id'],
            $consumerId,
            $last->eventId,
            $position - 1,
            $last->exchangeTimestamp,
            $dataset['events_file_sha256'],
        );
    }
}
