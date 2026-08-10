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
        if ($type !== 'limit' || $liquidityRole !== 'maker' || $marketFallback
            || $ttlSeconds !== 90 || $cancelAfterSeconds !== 120
            || $maximumSpreadBps !== 6.0 || $maximumSlippageBps !== 8.0) {
            throw new CanonicalOrderPlanException('canonical_day_trading_order_policy_invalid');
        }
    }
}
