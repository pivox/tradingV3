<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalEntryZonePolicy
{
    public function __construct(
        public string $anchorSource,
        public string $anchorTimeframe,
        public string $atrTimeframe,
        public float $atrMultiplier,
        public float $minimumHalfWidthRate,
        public float $maximumHalfWidthRate,
        public float $asymmetryRate,
        public int $ttlSeconds,
        public int $maximumInputAgeSeconds,
        public bool $quantizeOutward,
    ) {
    }
}
