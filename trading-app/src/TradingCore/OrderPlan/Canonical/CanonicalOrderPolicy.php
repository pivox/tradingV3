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
        $frozenEnvelope = [$ttlSeconds, $cancelAfterSeconds, $maximumSpreadBps];
        if ($type !== 'limit' || $liquidityRole !== 'maker' || $marketFallback
            || !\in_array($frozenEnvelope, [[90, 120, 6.0], [45, 75, 6.0], [30, 60, 8.0]], true)
            || $maximumSlippageBps !== 8.0) {
            throw new CanonicalOrderPlanException('canonical_day_trading_order_policy_invalid');
        }
    }
}
