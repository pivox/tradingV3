<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final class CanonicalHoldingBoundary
{
    /** @param array<string, mixed> $horizon */
    public static function expiresAt(\DateTimeImmutable $createdAt, int $holdingWindowSeconds, array $horizon): \DateTimeImmutable
    {
        $expected = [
            'maximum_duration' => 'PT8H',
            'daily_boundary_time' => '00:00:00',
            'daily_boundary_timezone' => 'UTC',
            'close_before_boundary' => true,
        ];
        ksort($horizon, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($holdingWindowSeconds !== 28_800 || $horizon !== $expected) {
            throw new CanonicalOrderPlanException('canonical_holding_boundary_invalid');
        }

        $utc = $createdAt->setTimezone(new \DateTimeZone('UTC'));
        $durationExpiry = $utc->modify('+' . $holdingWindowSeconds . ' seconds');
        $dailyBoundary = $utc->modify('tomorrow')->setTime(0, 0, 0);
        $expiry = $durationExpiry < $dailyBoundary ? $durationExpiry : $dailyBoundary;
        if ($expiry <= $utc) {
            throw new CanonicalOrderPlanException('canonical_holding_window_expired');
        }

        return $expiry;
    }
}
