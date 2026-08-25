<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetAppendResult;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\MarketData\PaperDurableBatchSourceInterface;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final class PaperPublicDatasetCapture
{
    private ?\Throwable $lastStopFailure = null;
    private ?\Throwable $lastIncompletePersistenceFailure = null;

    public function run(
        PaperDatasetRecorder $recorder,
        PaperLiveMarketDataSourceInterface $source,
        ?\Closure $afterDurableEvent = null,
    ): PaperDatasetManifest {
        $this->lastStopFailure = null;
        $this->lastIncompletePersistenceFailure = null;

        try {
            /** @var list<PaperMarketEvent> $pendingBatch */
            $pendingBatch = [];
            $expectedRemaining = null;
            foreach ($source->events() as $event) {
                if ($source instanceof PaperDurableBatchSourceInterface) {
                    $remaining = $source->pendingDurableBatchSize();
                    if ($remaining < 1
                        || ($expectedRemaining !== null && $remaining !== $expectedRemaining)
                    ) {
                        throw new \LogicException('paper_public_capture_batch_boundary_invalid');
                    }
                    $pendingBatch[] = $event;
                    if ($remaining > 1) {
                        $source->acknowledge($event->eventId);
                        $expectedRemaining = $remaining - 1;

                        continue;
                    }

                    $results = $recorder->appendBatch($pendingBatch);
                    foreach ($results as $result) {
                        assert(
                            /** @phpstan-ignore-next-line the enum assertion documents the durable append boundary. */
                            $result === PaperDatasetAppendResult::APPENDED
                            || $result === PaperDatasetAppendResult::REPLAYED,
                        );
                    }
                    $source->acknowledge($event->eventId);
                    if ($afterDurableEvent !== null) {
                        foreach ($pendingBatch as $durableEvent) {
                            $afterDurableEvent($durableEvent);
                        }
                    }
                    $pendingBatch = [];
                    $expectedRemaining = null;

                    continue;
                }

                $result = $recorder->append($event);
                assert(
                    /** @phpstan-ignore-next-line the enum assertion documents the durable append boundary. */
                    $result === PaperDatasetAppendResult::APPENDED
                    || $result === PaperDatasetAppendResult::REPLAYED,
                );
                $source->acknowledge($event->eventId);
                if ($afterDurableEvent !== null) {
                    $afterDurableEvent($event);
                }
            }
            if ($pendingBatch !== []) {
                throw new \LogicException('paper_public_capture_batch_boundary_invalid');
            }

            $isComplete = $source->isComplete();
        } catch (\Throwable $failure) {
            return $this->stopAndMarkIncomplete($recorder, $source, $failure);
        }

        if ($isComplete) {
            return $recorder->complete();
        }

        return $this->stopAndMarkIncomplete($recorder, $source, null);
    }

    private function stopAndMarkIncomplete(
        PaperDatasetRecorder $recorder,
        PaperLiveMarketDataSourceInterface $source,
        ?\Throwable $originalFailure,
    ): PaperDatasetManifest {
        try {
            $source->stop();
        } catch (\Throwable $stopFailure) {
            $this->lastStopFailure = $stopFailure;
        }

        try {
            $manifest = $recorder->markIncomplete();
        } catch (\Throwable $incompletePersistenceFailure) {
            $this->lastIncompletePersistenceFailure = $incompletePersistenceFailure;

            throw new \RuntimeException(
                'paper_public_capture_incomplete_persist_failed',
                0,
                $originalFailure
                    ?? $this->lastStopFailure
                    ?? $this->lastIncompletePersistenceFailure,
            );
        }

        if ($originalFailure !== null) {
            throw $originalFailure;
        }

        return $manifest;
    }
}
