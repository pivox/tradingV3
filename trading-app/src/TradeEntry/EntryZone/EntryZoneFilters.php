<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Dto\EntryZone;
 
final class EntryZoneFilters
{
    public function passes(TradeEntryRequest $req, EntryZone $zone): bool
    {
        if (!$zone->isValid()) return false;

        if ($req->rsi !== null && $req->rsi > $req->rsiCap) return false;

        if ($req->requirePullback && $req->pullbackConfirmed === false) return false;

        if ($req->volumeRatio !== null && $req->volumeRatio < $req->minVolumeRatio) return false;

        // Garde simple pour éviter d'acheter une extension trop loin du pivot :
        $maxDistPct = 2.0 * $req->atrValue / max(1e-9, $req->pivotPrice);
        // Si la base d'entrée dépasse pivot + 2*ATR → invalide.
        if ($req->entryPriceBase > ($req->pivotPrice + 2.0 * $req->atrValue)) return false;

        return true;
    }
}
