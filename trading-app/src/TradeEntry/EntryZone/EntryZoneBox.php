<?php
declare(strict_types=1);

namespace App\TradeEntry\EntryZone;

use App\TradeEntry\Dto\TradeEntryRequest;
use App\TradeEntry\Dto\EntryZone;
 
final class EntryZoneBox
{
    public function __construct(
        private EntryZoneCalculator $calculator,
        private EntryZoneFilters $filters
    ) {}

    public function compute(TradeEntryRequest $req): ?EntryZone
    {
        $zone = $this->calculator->compute($req);
        return $this->filters->passes($req, $zone) ? $zone : null;
    }
}
