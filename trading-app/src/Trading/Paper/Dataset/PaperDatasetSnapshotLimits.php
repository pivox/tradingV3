<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

final readonly class PaperDatasetSnapshotLimits
{
    public function __construct(
        public int $maximumEvents = PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_EVENTS,
        public int $maximumBytes = PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_BYTES,
    ) {
        if ($maximumEvents < 1
            || $maximumEvents > PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_EVENTS
            || $maximumBytes < 1
            || $maximumBytes > PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_BYTES
        ) {
            throw new \InvalidArgumentException('paper_dataset_snapshot_limits_invalid');
        }
    }
}
