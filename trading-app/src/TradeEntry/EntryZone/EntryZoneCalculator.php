<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\EntryZone;
use App\TradeEntry\Dto\TradeEntryRequest;
 
final class EntryZoneCalculator
{
    public function compute(TradeEntryRequest $req): EntryZone
    {
        $low  = $req->pivotPrice - $req->kLow  * $req->atrValue;
        $high = $req->pivotPrice + $req->kHigh * $req->atrValue;
        $expires = (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $req->zoneTtlSec));
        $zone = new EntryZone($low, $high, $expires);
        return $zone->clampToTick($req->tickSize);
    }
}
