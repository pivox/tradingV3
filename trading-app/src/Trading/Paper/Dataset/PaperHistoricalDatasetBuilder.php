<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\AcknowledgedPaperMarketDataSourceInterface;

final class PaperHistoricalDatasetBuilder
{
    public function build(
        PaperDatasetRecorder $recorder,
        AcknowledgedPaperMarketDataSourceInterface $source,
    ): PaperDatasetManifest {
        try {
            foreach ($source->events() as $event) {
                $recorder->append($event);
                $source->acknowledge($event->eventId);
            }

            return $source->isComplete() ? $recorder->complete() : $recorder->manifest();
        } catch (PaperHistoricalSourceIntegrityException) {
            return $recorder->markIncomplete();
        }
    }
}
