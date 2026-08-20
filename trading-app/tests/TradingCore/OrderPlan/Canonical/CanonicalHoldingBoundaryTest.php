<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalHoldingBoundary;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalHoldingBoundary::class)]
final class CanonicalHoldingBoundaryTest extends TestCase
{
    private const HORIZON = [
        'maximum_duration' => 'PT8H',
        'daily_boundary_time' => '00:00:00',
        'daily_boundary_timezone' => 'UTC',
        'close_before_boundary' => true,
    ];

    public function testUsesEightHoursWhenItEndsBeforeUtcMidnight(): void
    {
        self::assertSame(
            '2026-08-10T20:00:00+00:00',
            CanonicalHoldingBoundary::expiresAt(new \DateTimeImmutable('2026-08-10T12:00:00Z'), 28_800, self::HORIZON)->format('c'),
        );
    }

    public function testUsesUtcMidnightWhenItComesFirst(): void
    {
        self::assertSame(
            '2026-08-11T00:00:00+00:00',
            CanonicalHoldingBoundary::expiresAt(new \DateTimeImmutable('2026-08-10T23:59:59Z'), 28_800, self::HORIZON)->format('c'),
        );
    }

    public function testRejectsNonUtcBoundaryContract(): void
    {
        $horizon = self::HORIZON;
        $horizon['daily_boundary_timezone'] = 'Europe/Paris';

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_holding_boundary_invalid');
        CanonicalHoldingBoundary::expiresAt(new \DateTimeImmutable('2026-08-10T12:00:00Z'), 28_800, $horizon);
    }

    public function testSupportsFrozenThirtyMinuteMicroScalpingBoundary(): void
    {
        self::assertSame(
            '2026-08-10T12:30:00+00:00',
            CanonicalHoldingBoundary::expiresAt(new \DateTimeImmutable('2026-08-10T12:00:00Z'), 1800, [
                'maximum_duration' => 'PT30M',
                'daily_boundary_time' => '00:00:00',
                'daily_boundary_timezone' => 'UTC',
                'close_before_boundary' => true,
            ])->format('c'),
        );
    }
}
