<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

final class MakerTakerSwitchPolicy
{
    public function __construct(
        public readonly int  $ttlThresholdSec = 25, // bascule quand TTL zone ≤ 25s
        public readonly int  $maxSpreadBps    = 8,  // spread admissible pour "taker de fin de zone"
        public readonly int  $maxSlippageBps  = 10, // cap de slippage pour IOC limit
        public readonly bool $onlyIfWithinZone = true // n’autorise le switch que si le prix est encore dans la zone
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
