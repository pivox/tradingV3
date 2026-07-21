<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;

final class PaperDatasetFormatLimits
{
    public const MAX_CANONICAL_EVENT_LINE_BYTES = (CanonicalJson::MAX_BYTES * 6) + 200_000;
    public const MAX_MANIFEST_BYTES = 65_536;
}
