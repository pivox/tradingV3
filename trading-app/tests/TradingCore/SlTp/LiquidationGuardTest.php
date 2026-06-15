<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\SlTp;

use App\TradingCore\SlTp\Dto\LiquidationCheckRequest;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;
use App\TradingCore\SlTp\Service\LiquidationGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiquidationGuard::class)]
#[CoversClass(LiquidationCheckRequest::class)]
#[CoversClass(LiquidationCheckResult::class)]
final class LiquidationGuardTest extends TestCase
{
    public function testMarksPlanSafeWhenLiquidationIsFarEnoughBeyondStop(): void
    {
        $guard = new LiquidationGuard();

        $result = $guard->check(new LiquidationCheckRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            leverage: 5,
            maintenanceMarginRate: null,
            liquidationPrice: 80.0,
            minDistanceRatio: 3.0,
        ));

        self::assertTrue($result->isSafe);
        self::assertSame(80.0, $result->liquidationPrice);
        self::assertSame(0.2, $result->liquidationDistancePct);
        self::assertSame(4.0, $result->stopToLiquidationRatio);
        self::assertNull($result->reasonIfUnsafe);
    }

    public function testMarksPlanUnsafeWhenLiquidationIsTooCloseToStop(): void
    {
        $guard = new LiquidationGuard();

        $result = $guard->check(new LiquidationCheckRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            leverage: 5,
            maintenanceMarginRate: null,
            liquidationPrice: 90.0,
            minDistanceRatio: 3.0,
        ));

        self::assertFalse($result->isSafe);
        self::assertSame(2.0, $result->stopToLiquidationRatio);
        self::assertSame('liquidation_distance_below_min_ratio', $result->reasonIfUnsafe);
    }

    public function testMarksPlanUnsafeWhenStopIsOnWrongSideOfEntry(): void
    {
        $guard = new LiquidationGuard();

        // Long: stop above entry — invalid regardless of liquidation price.
        $result = $guard->check(new LiquidationCheckRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 105.0,
            leverage: 5,
            maintenanceMarginRate: null,
            liquidationPrice: 80.0,
            minDistanceRatio: 3.0,
        ));

        self::assertFalse($result->isSafe);
        self::assertSame('stop_on_wrong_side_of_entry', $result->reasonIfUnsafe);
    }

    public function testMarksPlanUnsafeWhenLiquidationIsNotBeyondStop(): void
    {
        $guard = new LiquidationGuard();

        // Long: liquidationPrice must be below stopPrice — here it sits between stop and entry.
        $result = $guard->check(new LiquidationCheckRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            leverage: 5,
            maintenanceMarginRate: null,
            liquidationPrice: 96.0,
            minDistanceRatio: 3.0,
        ));

        self::assertFalse($result->isSafe);
        self::assertSame('liquidation_not_beyond_stop', $result->reasonIfUnsafe);
    }

    public function testMarksPlanUnsafeWhenMinDistanceRatioIsInvalid(): void
    {
        $guard = new LiquidationGuard();

        // NAN threshold: ratio < NAN evaluates false in PHP → would pass as safe without guard.
        $result = $guard->check(new LiquidationCheckRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            leverage: 5,
            maintenanceMarginRate: null,
            liquidationPrice: 80.0,
            minDistanceRatio: NAN,
        ));

        self::assertFalse($result->isSafe);
        self::assertSame('invalid_min_distance_ratio', $result->reasonIfUnsafe);

        // Negative threshold: any ratio passes → same fail-closed behaviour.
        $result2 = $guard->check(new LiquidationCheckRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopPrice: 95.0,
            leverage: 5,
            maintenanceMarginRate: null,
            liquidationPrice: 80.0,
            minDistanceRatio: -1.0,
        ));

        self::assertFalse($result2->isSafe);
        self::assertSame('invalid_min_distance_ratio', $result2->reasonIfUnsafe);
    }

    public function testMarksPlanUnsafeWhenLiquidationDataIsUnavailable(): void
    {
        $guard = new LiquidationGuard();

        $result = $guard->check(new LiquidationCheckRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'short',
            entryPrice: 100.0,
            stopPrice: 105.0,
            leverage: null,
            maintenanceMarginRate: null,
            liquidationPrice: null,
            minDistanceRatio: 3.0,
        ));

        self::assertFalse($result->isSafe);
        self::assertSame('insufficient_liquidation_data', $result->reasonIfUnsafe);
        self::assertContains('Liquidation price cannot be derived without leverage or an exchange-provided liquidation price.', $result->warnings);
    }
}
