<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalProtectionRequest
{
    public function __construct(
        public CanonicalExecutionPolicy $policy,
        public CanonicalEntryZone $entryZone,
        public ?CanonicalPriceObservation $atr,
        public ?CanonicalPriceObservation $pivot,
    ) {
    }
}
