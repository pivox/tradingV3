<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionState;
use App\TradingCore\Execution\Hyperliquid\HyperliquidExecutionStatePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidExecutionStatePolicy::class)]
final class HyperliquidExecutionStatePolicyTest extends TestCase
{
    /** @return iterable<string, array{float, string, string, float}> */
    public static function protectiveCapCases(): iterable
    {
        yield 'large sell cap' => [49872.7, 'long', '0.1', 49623.0];
        yield 'large buy cap' => [49872.7, 'short', '0.1', 50123.0];
        yield 'small sell cap' => [0.0000123456, 'long', '0.0000000001', 0.000012283];
        yield 'small buy cap' => [0.0000123456, 'short', '0.0000000001', 0.000012408];
        yield 'non power of ten sell tick' => [49872.7, 'long', '0.3', 49623.0];
        yield 'non power of ten buy tick' => [49872.7, 'short', '0.3', 50124.0];
    }

    #[DataProvider('protectiveCapCases')]
    public function testProtectiveCapRespectsTickAndFiveSignificantFigures(
        float $stopPrice,
        string $positionSide,
        string $priceTick,
        float $expected,
    ): void {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));

        $cap = $policy->protectiveStopCap($stopPrice, $positionSide, $priceTick);

        self::assertSame($expected, $cap);
    }

    public function testEmergencyCloseCapRespectsTickAndFiveSignificantFigures(): void
    {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));
        $state = new HyperliquidExecutionState(
            'BTCUSDT',
            49872.6,
            49872.7,
            new \DateTimeImmutable('2026-07-12T11:59:59Z'),
            5,
            'isolated',
        );

        self::assertSame(50123.0, $policy->emergencyCloseCap($state, 'short', '0.1'));
    }

    public function testCapRejectsAResultThatCannotBeRepresentedAsAFiniteFloat(): void
    {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_execution_cap_price_invalid');

        $policy->protectiveStopCap(1.0, 'short', '1e400');
    }

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
            'isolated',
        );

        $cap = $policy->emergencyCloseCap($state, 'long', '0.1');

        self::assertSame(0.1, $cap);
        self::assertGreaterThan(0.0, $cap);
    }

    public function testCrossPositionRequiresIsolatedModeUpdate(): void
    {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));
        $state = new HyperliquidExecutionState(
            'BTCUSDT', 99.0, 100.0, new \DateTimeImmutable('2026-07-12T11:59:59Z'), 5, 'cross', true,
        );

        self::assertSame([], $policy->blockingReasons($state, 'BTCUSDT'));
        self::assertTrue($policy->requiresIsolatedModeUpdate($state));
    }

    public function testPositionWithoutAuthoritativeMarginModeBlocks(): void
    {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));
        $state = new HyperliquidExecutionState(
            'BTCUSDT', 99.0, 100.0, new \DateTimeImmutable('2026-07-12T11:59:59Z'), 5, null, true,
        );

        self::assertContains('hyperliquid_execution_margin_mode_invalid', $policy->blockingReasons($state, 'BTCUSDT'));
    }

    public function testFlatNullLeverageAndMarginModeAreValidAndRequireUpdate(): void
    {
        $policy = new HyperliquidExecutionStatePolicy(new MockClock('2026-07-12T12:00:00Z'));
        $state = new HyperliquidExecutionState(
            'BTCUSDT', 99.0, 100.0, new \DateTimeImmutable('2026-07-12T11:59:59Z'), null, null, false,
        );

        self::assertSame([], $policy->blockingReasons($state, 'BTCUSDT'));
        self::assertTrue($policy->requiresIsolatedModeUpdate($state));
    }
}
