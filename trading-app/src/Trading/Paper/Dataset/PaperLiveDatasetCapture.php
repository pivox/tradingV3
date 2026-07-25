<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;

final class PaperLiveDatasetCapture
{
    private ?\Throwable $lastStopFailure = null;
    private ?\Throwable $lastIncompletePersistenceFailure = null;

    public function run(
        PaperDatasetRecorder $recorder,
        PaperLiveMarketDataSourceInterface $source,
        PaperLiveEventConsumerInterface $consumer,
    ): PaperDatasetManifest {
        $this->lastStopFailure = null;
        $this->lastIncompletePersistenceFailure = null;

        try {
            foreach ($source->events() as $event) {
                $appendResult = $recorder->append($event);
                assert(
                    /** @phpstan-ignore-next-line the exhaustive enum assertion documents this boundary contract. */
                    $appendResult === PaperDatasetAppendResult::APPENDED
                    || $appendResult === PaperDatasetAppendResult::REPLAYED,
                );
                $consumer->consume($recorder->manifest()->datasetId, $event);
                $source->acknowledge($event->eventId);
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
                'paper_live_capture_incomplete_persist_failed',
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
