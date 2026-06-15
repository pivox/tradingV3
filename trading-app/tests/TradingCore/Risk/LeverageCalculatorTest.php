<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Risk;

use App\TradingCore\Risk\Dto\LeverageCalculationRequest;
use App\TradingCore\Risk\Dto\LeverageCalculationResult;
use App\TradingCore\Risk\Service\LeverageCalculator;
use App\TradingCore\Risk\Service\LeverageCapResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeverageCalculator::class)]
#[CoversClass(LeverageCapResolver::class)]
#[CoversClass(LeverageCalculationRequest::class)]
#[CoversClass(LeverageCalculationResult::class)]
final class LeverageCalculatorTest extends TestCase
{
    public function testRawLeverageIsDerivedFromRiskPctAndStopPct(): void
    {
        $calculator = new LeverageCalculator(new LeverageCapResolver());

        $result = $calculator->calculate(new LeverageCalculationRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            stopPct: 0.02,
            riskPct: 0.04,
            rawLeverage: null,
            exchangeCap: 100.0,
            symbolCap: null,
            profileCap: null,
            timeframeMultiplier: 1.0,
            liquidityMultiplier: null,
            maxLossPct: null,
            floor: 1.0,
            minLeverage: 1,
            maxLeverage: 100,
            roundingMode: 'ceil',
        ));

        self::assertSame(2.0, $result->rawLeverage);
        self::assertSame(2.0, $result->cappedLeverage);
        self::assertSame(2, $result->finalLeverage);
    }

    public function testAppliesExchangeProfileSymbolCapsFloorAndRounding(): void
    {
        $calculator = new LeverageCalculator(new LeverageCapResolver());

        $result = $calculator->calculate(new LeverageCalculationRequest(
            symbol: 'NVDAXUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            stopPct: 0.01,
            riskPct: 0.1,
            rawLeverage: null,
            exchangeCap: 20.0,
            symbolCap: 5.0,
            profileCap: 8.0,
            timeframeMultiplier: 1.25,
            liquidityMultiplier: 1.0,
            maxLossPct: null,
            floor: 2.0,
            minLeverage: 1,
            maxLeverage: 100,
            roundingMode: 'floor',
        ));

        self::assertSame(10.0, $result->rawLeverage);
        self::assertSame(5.0, $result->cappedLeverage);
        self::assertSame(5, $result->finalLeverage);
        self::assertSame(['exchange_cap', 'profile_cap', 'symbol_cap'], $result->capsApplied);
    }

    public function testFloorCannotBypassExchangeOrProfileOrSymbolCaps(): void
    {
        $calculator = new LeverageCalculator(new LeverageCapResolver());

        // rawLeverage = 0.2/0.01 = 20, cappedLeverage = min(20, 5) = 5
        // floor = 10 > exchangeCap = 5 — must NOT raise finalLeverage above 5
        $result = $calculator->calculate(new LeverageCalculationRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            stopPct: 0.01,
            riskPct: 0.2,
            rawLeverage: null,
            exchangeCap: 5.0,
            symbolCap: null,
            profileCap: null,
            timeframeMultiplier: 1.0,
            liquidityMultiplier: null,
            maxLossPct: null,
            floor: 10.0,
            minLeverage: 1,
            maxLeverage: 100,
            roundingMode: 'ceil',
        ));

        self::assertSame(20.0, $result->rawLeverage);
        self::assertSame(5.0, $result->cappedLeverage);
        self::assertSame(5, $result->finalLeverage);
        self::assertContains('exchange_cap', $result->capsApplied);
    }

    public function testCeilRoundingCannotExceedFractionalFloatCap(): void
    {
        $calculator = new LeverageCalculator(new LeverageCapResolver());

        // exchangeCap = 5.5 (fractional), rawLeverage >> cap → cappedLeverage = 5.5
        // ceil(5.5) = 6 would exceed the cap — finalLeverage must be floor(5.5) = 5
        $result = $calculator->calculate(new LeverageCalculationRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            stopPct: 0.01,
            riskPct: 0.5,
            rawLeverage: null,
            exchangeCap: 5.5,
            symbolCap: null,
            profileCap: null,
            timeframeMultiplier: 1.0,
            liquidityMultiplier: null,
            maxLossPct: null,
            floor: null,
            minLeverage: 1,
            maxLeverage: 100,
            roundingMode: 'ceil',
        ));

        self::assertSame(5.5, $result->cappedLeverage);
        self::assertSame(5, $result->finalLeverage);
    }

    public function testCeilRoundingIsPreservedWhenNoCapsReducedLeverage(): void
    {
        $calculator = new LeverageCalculator(new LeverageCapResolver());

        // rawLeverage = 0.04/0.02 = 2.0 — exchange cap is 100 (not binding).
        // ceil(2.0) = 2 must NOT be clipped to floor(2.0) = 2 (no-op here).
        // More critically: fractional raw = 1.5, cap = 100 → ceil(1.5) must stay 2.
        $result = $calculator->calculate(new LeverageCalculationRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            stopPct: 0.02,
            riskPct: 0.03,
            rawLeverage: null,
            exchangeCap: 100.0,
            symbolCap: null,
            profileCap: null,
            timeframeMultiplier: 1.0,
            liquidityMultiplier: null,
            maxLossPct: null,
            floor: null,
            minLeverage: 1,
            maxLeverage: 100,
            roundingMode: 'ceil',
        ));

        // rawLeverage = 0.03/0.02 = 1.5, exchangeCap not binding → ceil(1.5) = 2
        self::assertSame(1.5, $result->rawLeverage);
        self::assertSame(1.5, $result->cappedLeverage);
        self::assertSame(2, $result->finalLeverage);
    }

    public function testFloorDoesNotInventLeverageWithoutPositiveRawInput(): void
    {
        $calculator = new LeverageCalculator(new LeverageCapResolver());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stopPct must be positive');

        $calculator->calculate(new LeverageCalculationRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            stopPct: 0.0,
            riskPct: 0.02,
            rawLeverage: null,
            exchangeCap: 20.0,
            symbolCap: null,
            profileCap: null,
            timeframeMultiplier: 1.0,
            liquidityMultiplier: null,
            maxLossPct: null,
            floor: 2.0,
            minLeverage: 1,
            maxLeverage: 20,
            roundingMode: 'ceil',
        ));
    }

    public function testDocumentsMaxLossPctAsExecutionTimeCapWarning(): void
    {
        $calculator = new LeverageCalculator(new LeverageCapResolver());

        $result = $calculator->calculate(new LeverageCalculationRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            stopPct: 0.01,
            riskPct: 0.02,
            rawLeverage: null,
            exchangeCap: 20.0,
            symbolCap: null,
            profileCap: null,
            timeframeMultiplier: 1.0,
            liquidityMultiplier: null,
            maxLossPct: 0.004,
            floor: 1.0,
            minLeverage: 1,
            maxLeverage: 20,
            roundingMode: 'ceil',
        ));

        self::assertContains('maxLossPct is represented for execution-time size/leverage capping; it is not applied to raw leverage in this preparatory module.', $result->warnings);
    }
}
