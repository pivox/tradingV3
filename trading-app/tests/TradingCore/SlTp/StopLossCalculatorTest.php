<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\SlTp;

use App\TradingCore\SlTp\Dto\StopLossRequest;
use App\TradingCore\SlTp\Dto\StopLossResult;
use App\TradingCore\SlTp\Service\StopLossCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StopLossCalculator::class)]
#[CoversClass(StopLossRequest::class)]
#[CoversClass(StopLossResult::class)]
final class StopLossCalculatorTest extends TestCase
{
    public function testCalculatesAtrStopBelowEntryForLong(): void
    {
        $calculator = new StopLossCalculator();

        $result = $calculator->calculate(new StopLossRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopFrom: 'atr',
            stopFallback: 'risk',
            atr: 2.0,
            atrK: 1.5,
            pivotPrice: null,
            pivotSlPolicy: 'nearest',
            pivotSlBufferPct: 0.003,
            pivotSlMinKeepRatio: 0.8,
            slFullSize: true,
            positionSize: 12.0,
        ));

        self::assertSame(97.0, $result->stopPrice);
        self::assertSame(0.03, $result->stopPct);
        self::assertSame(3.0, $result->stopDistance);
        self::assertSame('atr', $result->stopSource);
        self::assertTrue($result->isFullSize);
    }

    public function testCalculatesAtrStopAboveEntryForShort(): void
    {
        $calculator = new StopLossCalculator();

        $result = $calculator->calculate(new StopLossRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'short',
            entryPrice: 100.0,
            stopFrom: 'atr',
            stopFallback: 'risk',
            atr: 2.0,
            atrK: 1.5,
            pivotPrice: null,
            pivotSlPolicy: 'nearest',
            pivotSlBufferPct: 0.003,
            pivotSlMinKeepRatio: 0.8,
            slFullSize: true,
            positionSize: 12.0,
        ));

        self::assertSame(103.0, $result->stopPrice);
        self::assertSame(0.03, $result->stopPct);
        self::assertSame(3.0, $result->stopDistance);
        self::assertSame('atr', $result->stopSource);
        self::assertTrue($result->isFullSize);
    }

    public function testAppliesPivotBufferWithoutChangingConfigValues(): void
    {
        $calculator = new StopLossCalculator();
        $buffer = 0.003;

        $result = $calculator->calculate(new StopLossRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopFrom: 'pivot',
            stopFallback: 'atr',
            atr: 2.0,
            atrK: 1.5,
            pivotPrice: 95.0,
            pivotSlPolicy: 'nearest_below',
            pivotSlBufferPct: $buffer,
            pivotSlMinKeepRatio: 0.8,
            slFullSize: true,
            positionSize: 12.0,
        ));

        self::assertSame(94.715, $result->stopPrice);
        self::assertSame($buffer, $result->metadata['pivot_sl_buffer_pct']);
        self::assertSame('pivot', $result->stopSource);
    }

    public function testRejectsStopOnWrongSideOfEntry(): void
    {
        $calculator = new StopLossCalculator();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stop loss must be below entry for long positions');

        $calculator->calculate(new StopLossRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            entryPrice: 100.0,
            stopFrom: 'provided',
            stopFallback: null,
            atr: null,
            atrK: null,
            pivotPrice: null,
            pivotSlPolicy: 'nearest',
            pivotSlBufferPct: null,
            pivotSlMinKeepRatio: null,
            slFullSize: true,
            positionSize: 12.0,
            providedStopPrice: 101.0,
        ));
    }
}
