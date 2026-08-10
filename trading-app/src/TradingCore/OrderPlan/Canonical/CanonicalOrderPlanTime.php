<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final class CanonicalOrderPlanTime
{
    public static function isOlderThan(
        \DateTimeImmutable $observedAt,
        \DateTimeImmutable $now,
        int $maximumAgeSeconds,
    ): bool {
        if ($maximumAgeSeconds <= 0) {
            throw new CanonicalOrderPlanException('canonical_order_plan_maximum_age_invalid');
        }

        return $observedAt->modify(sprintf('+%d seconds', $maximumAgeSeconds)) < $now;
    }
}
