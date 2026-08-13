<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;

final class PaperDatasetFormatLimits
{
    public const MAX_CANONICAL_EVENT_LINE_BYTES = (CanonicalJson::MAX_BYTES * 6) + 200_000;
    public const MAX_MANIFEST_BYTES = 65_536;

    /** Keeps object/index overhead bounded under the supported 128 MiB PHP memory limit. */
    public const MAX_BACKTEST_SNAPSHOT_EVENTS = 10_000;

    /** Leaves at least seven raw-byte budgets for decoding, objects, verifier indexes and PHP runtime. */
    public const MAX_BACKTEST_SNAPSHOT_BYTES = 16 * 1024 * 1024;
}
