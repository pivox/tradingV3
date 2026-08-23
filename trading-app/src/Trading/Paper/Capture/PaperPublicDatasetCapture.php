<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetAppendResult;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;

final class PaperPublicDatasetCapture
{
    private ?\Throwable $lastStopFailure = null;
    private ?\Throwable $lastIncompletePersistenceFailure = null;

    public function run(
        PaperDatasetRecorder $recorder,
        PaperLiveMarketDataSourceInterface $source,
    ): PaperDatasetManifest {
        $this->lastStopFailure = null;
        $this->lastIncompletePersistenceFailure = null;

        try {
            foreach ($source->events() as $event) {
                $result = $recorder->append($event);
                assert(
                    /** @phpstan-ignore-next-line the enum assertion documents the durable append boundary. */
                    $result === PaperDatasetAppendResult::APPENDED
                    || $result === PaperDatasetAppendResult::REPLAYED,
                );
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
