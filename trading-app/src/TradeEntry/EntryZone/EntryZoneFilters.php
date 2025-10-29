<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\EntryZone;

final class EntryZoneFilters
{
    public function __construct() {}

    /** @param array{request:mixed, preflight:mixed, plan:mixed, zone:EntryZone} $context */
    public function passAll(array $context): bool
    {
        return true; // TODO règles MTF (RSI<70, MA21+2×ATR, pullback confirmé, scaling progressif)
    }
}
