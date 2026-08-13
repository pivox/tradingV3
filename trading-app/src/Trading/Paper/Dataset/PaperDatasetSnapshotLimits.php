<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

final readonly class PaperDatasetSnapshotLimits
{
    public function __construct(
        public int $maximumEvents = PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_EVENTS,
        public int $maximumBytes = PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_BYTES,
        public int $maximumNodes = PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_NODES,
        public int $maximumKeys = PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_KEYS,
    ) {
        if ($maximumEvents < 1
            || $maximumEvents > PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_EVENTS
            || $maximumBytes < 1
            || $maximumBytes > PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_BYTES
            || $maximumNodes < 1
            || $maximumNodes > PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_NODES
            || $maximumKeys < 1
            || $maximumKeys > PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_KEYS
        ) {
            throw new \InvalidArgumentException('paper_dataset_snapshot_limits_invalid');
        }
    }
}
