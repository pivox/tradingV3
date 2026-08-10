<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalEntryZoneRequest
{
    public function __construct(
        public CanonicalExecutionPolicy $policy,
        public string $symbol,
        public CanonicalPriceObservation $anchor,
        public CanonicalPriceObservation $atr,
        public CanonicalMarketSnapshot $market,
        public CanonicalTickSnapshot $tick,
    ) {
    }
}
