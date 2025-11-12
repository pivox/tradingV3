<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class FallbackEndOfZoneConfig
{
    public function __construct(
        public bool $enabled,
        public int $ttlThresholdSec,
        public float $maxSpreadBps,
        public bool $onlyIfWithinZone,
        public string $takerOrderType,
        public float $maxSlippageBps,
    ) {}
}

