<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalOrderPolicy
{
    public function __construct(
        public string $type,
        public string $liquidityRole,
        public int $ttlSeconds,
        public int $cancelAfterSeconds,
        public bool $marketFallback,
        public float $maximumSpreadBps,
        public float $maximumSlippageBps,
    ) {
        $deadlinePair = [$ttlSeconds, $cancelAfterSeconds];
        if ($type !== 'limit' || $liquidityRole !== 'maker' || $marketFallback
            || !\in_array($deadlinePair, [[90, 120], [45, 75]], true)
            || $maximumSpreadBps !== 6.0 || $maximumSlippageBps !== 8.0) {
            throw new CanonicalOrderPlanException('canonical_day_trading_order_policy_invalid');
        }
    }
}
