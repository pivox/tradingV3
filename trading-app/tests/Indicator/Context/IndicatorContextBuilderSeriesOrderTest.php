<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Context;

use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volume\Vwap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndicatorContextBuilder::class)]
final class IndicatorContextBuilderSeriesOrderTest extends TestCase
{
    public function testMacdAndPriceSeriesAreAlwaysOldestToNewest(): void
    {
        $closes = [];
        for ($index = 0; $index < 120; ++$index) {
            $closes[] = 100.0 + (0.12 * $index) + sin($index / 4.0);
        }
        $macd = new Macd();
        $histogram = array_values($macd->calculateFull($closes)['hist']);
        $expectedHistogram = array_slice(array_map('floatval', $histogram), -60);
        $highs = array_map(static fn (float $close): float => $close + 1.0, $closes);
        $lows = array_map(static fn (float $close): float => $close - 1.0, $closes);

        $context = (new IndicatorContextBuilder(
            new Rsi(),
            $macd,
            new Adx(),
            new Vwap(),
            new AtrCalculator(),
            new Sma(),
        ))
            ->symbol('BTCUSDT')
            ->timeframe('5m')
            ->closes($closes)
            ->highs($highs)
            ->lows($lows)
            ->volumes(array_fill(0, count($closes), 1.0))
            ->build();

        self::assertSame($expectedHistogram, $context['macd_hist_series']);
        self::assertSame(array_slice($expectedHistogram, -3), $context['macd_hist_last3']);
        self::assertSame(array_slice($highs, -60), $context['high_series']);
        self::assertSame(array_slice($lows, -60), $context['low_series']);
        self::assertSame('oldest_to_newest', $context['series_order']);
    }

    public function testBuildResetsAllMutableInputAndOverrideState(): void
    {
        $builder = new IndicatorContextBuilder(
            new Rsi(),
            new Macd(),
            new Adx(),
            new Vwap(),
            new AtrCalculator(),
            new Sma(),
        );
        $first = $builder
            ->symbol('BTCUSDT')
            ->timeframe('5m')
            ->closes([100.0, 101.0])
            ->highs([101.0, 102.0])
            ->lows([99.0, 100.0])
            ->volumes([1.0, 1.0])
            ->entryPrice(100.5)
            ->stopLoss(99.5)
            ->withDefaults()
            ->build();
        $second = $builder
            ->symbol('ETHUSDT')
            ->timeframe('1m')
            ->closes([200.0])
            ->build();

        self::assertSame('BTCUSDT', $first['symbol']);
        self::assertSame(100.5, $first['entry_price']);
        self::assertSame('ETHUSDT', $second['symbol']);
        self::assertSame(200.0, $second['close']);
        self::assertArrayNotHasKey('entry_price', $second);
        self::assertArrayNotHasKey('stop_loss', $second);
        self::assertArrayNotHasKey('min_atr_pct', $second);
        self::assertArrayNotHasKey('high_series', $second);
    }
}
