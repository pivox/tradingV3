<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

final class MakerTakerSwitchPolicy
{
    public function __construct(
        public readonly int  $ttlThresholdSec = 25, // switch when zone TTL ≤ 25s
        public readonly int  $maxSpreadBps    = 8,  // allowable spread for end-of-zone taker order
        public readonly int  $maxSlippageBps  = 10, // maximum slippage for IOC limit
        public readonly bool $onlyIfWithinZone = true // only switch if the price remains within the zone
    ) {}

    public function shouldSwitch(
        \DateTimeImmutable $now,
        ?\DateTimeImmutable $expiresAt,
        float $spreadBps,
        bool $withinZone
    ): bool {
        if ($expiresAt === null) return false;
        $ttl = $expiresAt->getTimestamp() - $now->getTimestamp();
        if ($ttl > $this->ttlThresholdSec) return false;
        if ($spreadBps > $this->maxSpreadBps) return false;
        if ($this->onlyIfWithinZone && !$withinZone) return false;
        return true;
    }
}
