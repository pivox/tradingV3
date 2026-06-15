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
