<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;

final class PaperDatasetFormatLimits
{
    public const MAX_CANONICAL_EVENT_LINE_BYTES = (CanonicalJson::MAX_BYTES * 6) + 200_000;
    public const MAX_MANIFEST_BYTES = 65_536;

    /** Bounds the optional in-memory backtest snapshot without restricting streamed verification. */
    public const MAX_BACKTEST_SNAPSHOT_EVENTS = 100_000;

    /** Allows a substantial candle corpus while keeping retained canonical source bytes finite. */
    public const MAX_BACKTEST_SNAPSHOT_BYTES = 128 * 1024 * 1024;
}
