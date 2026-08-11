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
use App\Indicator\Exception\InvalidKlineChronologyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndicatorContextBuilder::class)]
final class IndicatorContextBuilderSeriesOrderTest extends TestCase
{
    public function testMacdAndPriceSeriesAreAlwaysOldestToNewest(): void
    {
        $closes = [];
        for ($index = 0; $index < 220; ++$index) {
            $closes[] = 100.0 + (0.12 * $index) + sin($index / 4.0);
        }
        $macd = new Macd();
        $histogram = array_values($macd->calculateFull($closes)['hist']);
        $expectedHistogram = array_slice(array_map('floatval', $histogram), -60);
        $highs = array_map(static fn (float $close): float => $close + 1.0, $closes);
        $lows = array_map(static fn (float $close): float => $close - 1.0, $closes);
        $timestamps = array_map(static fn (int $index): int => 1_786_435_200 + ($index * 300), array_keys($closes));

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
            ->timestamps($timestamps)
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
        self::assertSame($timestamps, $context['series_timestamps']);
        self::assertSame(
            array_slice($timestamps, -count($expectedHistogram)),
            $context['macd_hist_series_timestamps'],
        );
        self::assertSame($expectedHistogram, $context['macd_line_signal_series']);
        self::assertSame(
            $context['macd_hist_series_timestamps'],
            $context['macd_line_signal_series_timestamps'],
        );
        self::assertSame([$context['ema_prev'][200], $context['ema'][200]], $context['ema_200_series']);
        self::assertSame(array_slice($timestamps, -2), $context['ema_200_series_timestamps']);
        self::assertArrayHasKey('pullback_age_bars', $context);
    }

    public function testDirectBuilderWithoutTimestampsDoesNotClaimCanonicalSeriesOrder(): void
    {
        $context = (new IndicatorContextBuilder(
            new Rsi(),
            new Macd(),
            new Adx(),
            new Vwap(),
            new AtrCalculator(),
            new Sma(),
        ))
            ->timeframe('5m')
            ->closes([100.0, 101.0])
            ->build();

        self::assertArrayNotHasKey('series_order', $context);
        self::assertArrayNotHasKey('series_timestamps', $context);
        self::assertArrayNotHasKey('macd_hist_series_timestamps', $context);
        self::assertArrayNotHasKey('macd_line_signal_series', $context);
        self::assertArrayNotHasKey('macd_line_signal_series_timestamps', $context);
        self::assertArrayNotHasKey('ema_200_series', $context);
        self::assertArrayNotHasKey('ema_200_series_timestamps', $context);
    }

    public function testSingleTimestampCannotProveSeriesDirection(): void
    {
        $builder = new IndicatorContextBuilder(
            new Rsi(),
            new Macd(),
            new Adx(),
            new Vwap(),
            new AtrCalculator(),
            new Sma(),
        );

        $this->expectException(InvalidKlineChronologyException::class);
        $this->expectExceptionMessage('ambiguous_timestamp_order');

        $builder
            ->timeframe('5m')
            ->timestamps([1_786_435_200])
            ->closes([100.0])
            ->build();
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
        self::assertArrayNotHasKey('series_order', $second);
        self::assertArrayHasKey('pullback_age_bars', $second);
        self::assertNull($second['pullback_age_bars']);
    }

    public function testMismatchedRawOhlcvCannotBeSuffixAlignedIntoAPullback(): void
    {
        $context = (new IndicatorContextBuilder(
            new Rsi(),
            new Macd(),
            new Adx(),
            new Vwap(),
            new AtrCalculator(),
            new Sma(),
        ))
            ->closes([100.0, 90.0, 110.0, 120.0])
            ->highs([101.0, 91.0, 111.0])
            ->lows([99.0, 89.0, 109.0])
            ->volumes([1.0, 1.0, 1.0])
            ->build();

        self::assertNull($context['pullback_age_bars']);
    }
}
