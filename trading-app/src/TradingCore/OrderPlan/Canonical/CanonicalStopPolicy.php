<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalStopPolicy
{
    public function __construct(
        public string $kind,
        public string $timeframe,
        public ?float $atrMultiplier,
        public ?string $pivotId,
        public float $bufferRate,
    ) {
    }
}
