<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionState;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionStatePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidExecutionStatePolicy::class)]
final class HyperliquidExecutionStatePolicyTest extends TestCase
{
    public function testProtectiveSellCapClampsToOneTickInsteadOfZero(): void
    {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));

        $cap = $policy->protectiveStopCap(0.1, 'long', '0.1');

        self::assertSame(0.1, $cap);
        self::assertGreaterThan(0.0, $cap);
    }

    public function testEmergencySellCapClampsToOneTickInsteadOfZero(): void
    {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));
        $state = new HyperliquidExecutionState(
            'BTCUSDT',
            0.1,
            0.2,
            new \DateTimeImmutable('2026-07-12T11:59:59Z'),
            5,
        );

        $cap = $policy->emergencyCloseCap($state, 'long', '0.1');

        self::assertSame(0.1, $cap);
        self::assertGreaterThan(0.0, $cap);
    }
}
