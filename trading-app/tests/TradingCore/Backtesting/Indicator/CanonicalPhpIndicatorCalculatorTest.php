<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Indicator;

use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorCandle;
use App\TradingCore\Backtesting\Indicator\CanonicalFiniteSeriesValidator;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectionException;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorWindow;
use App\TradingCore\Backtesting\Indicator\CanonicalPhpIndicatorCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalPhpIndicatorCalculator::class)]
#[CoversClass(CanonicalFiniteSeriesValidator::class)]
final class CanonicalPhpIndicatorCalculatorTest extends TestCase
{
    private const GOLDEN_DELTA = 1.0e-12;

    public function testItCalculatesFiniteDeterministicCanonicalProviderContext(): void
    {
        $window = $this->window();
        $calculator = new CanonicalPhpIndicatorCalculator(
            new Rsi(),
            new Macd(),
            new Ema(),
            new Adx(),
            new Sma(),
            new AtrCalculator(null),
            new Vwap(),
            new Bollinger(),
        );

        $first = $calculator->calculate($window);
        $second = $calculator->calculate($window);

        self::assertSame([
            'close',
            'high_series',
            'low_series',
            'rsi',
            'ema_20',
            'ema_50',
            'ema_200',
            'macd_hist',
            'vwap',
            'atr',
            'adx',
            'ma9',
            'ma21',
            'bb_upper',
            'bb_middle',
            'bb_lower',
            'ema',
            'ema_prev',
            'ema_200_slope',
            'ema_200_series',
            'ema_200_series_timestamps',
            'macd',
            'macd_hist_series',
            'macd_hist_series_timestamps',
            'macd_line_signal_series',
            'macd_line_signal_series_timestamps',
            'macd_hist_last3',
            'series_order',
            'series_timestamps',
            'pullback_age_bars',
            'volume_ratio',
            'ma_21_plus_k_atr',
        ], array_keys($first));
        self::assertSame([14, 15], array_keys($first['adx']));
        $this->assertFiniteNumericContext($first);

        $expectedHighs = array_map(
            static fn (CanonicalIndicatorCandle $candle): float => (float) $candle->high,
            array_slice($window->candles(), -60),
        );
        $expectedLows = array_map(
            static fn (CanonicalIndicatorCandle $candle): float => (float) $candle->low,
            array_slice($window->candles(), -60),
        );
        self::assertCount(60, $first['high_series']);
        self::assertCount(60, $first['low_series']);
        self::assertSame($expectedHighs, $first['high_series']);
        self::assertSame($expectedLows, $first['low_series']);

        self::assertSame(125.23, $first['close']);
        // Golden vector independently derived from the documented PHP/Wilder
        // fallback formulas against the exact 250-candle fixture below (v1).
        self::assertEqualsWithDelta(92.231526237754949, $first['rsi'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(124.054413712443889, $first['ema_20'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(122.545613533078054, $first['ema_50'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(115.852119009884632, $first['ema_200'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(0.014185413043459105, $first['macd_hist'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(112.568424120520518, $first['vwap'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(0.388296351355937, $first['atr'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(82.900568639307565, $first['adx'][14], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(82.797417887093317, $first['adx'][15], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(124.612222222222215, $first['ma9'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(124.000952380952384, $first['ma21'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(125.230046021109644, $first['bb_upper'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(124.048500000000018, $first['bb_middle'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(122.866953978890393, $first['bb_lower'], self::GOLDEN_DELTA);
        self::assertSame($first, $second);
        self::assertSame(CanonicalJson::encode($first), CanonicalJson::encode($second));
        self::assertGreaterThan($first['bb_lower'], $first['bb_middle']);
        self::assertGreaterThan($first['bb_middle'], $first['bb_upper']);
        self::assertGreaterThan(0.0, $first['atr']);
        self::assertGreaterThanOrEqual(0.0, $first['rsi']);
        self::assertLessThanOrEqual(100.0, $first['rsi']);

        self::assertSame([9, 20, 21, 50, 200], array_keys($first['ema']));
        self::assertSame([9, 20, 21, 50, 200], array_keys($first['ema_prev']));
        self::assertEqualsWithDelta($first['ema_20'], $first['ema'][20], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta($first['ema_50'], $first['ema'][50], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta($first['ema_200'], $first['ema'][200], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(115.75786894968248, $first['ema_prev'][200], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(
            $first['ema'][200] - $first['ema_prev'][200],
            $first['ema_200_slope'],
            self::GOLDEN_DELTA,
        );
        self::assertSame([$first['ema_prev'][200], $first['ema'][200]], $first['ema_200_series']);

        $timestamps = array_map(
            static fn (CanonicalIndicatorCandle $candle): int => $candle->openTimestamp()->getTimestamp(),
            $window->candles(),
        );
        self::assertCount(250, $first['series_timestamps']);
        self::assertSame($timestamps, $first['series_timestamps']);
        self::assertSame(array_slice($timestamps, -2), $first['ema_200_series_timestamps']);
        self::assertSame('oldest_to_newest', $first['series_order']);

        self::assertSame(['macd', 'signal', 'hist'], array_keys($first['macd']));
        self::assertEqualsWithDelta(0.71426160007723638, $first['macd']['macd'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(0.70007618703377728, $first['macd']['signal'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta($first['macd_hist'], $first['macd']['hist'], self::GOLDEN_DELTA);
        self::assertCount(60, $first['macd_hist_series']);
        self::assertSame(array_slice($timestamps, -60), $first['macd_hist_series_timestamps']);
        self::assertSame($first['macd_hist_series'], $first['macd_line_signal_series']);
        self::assertSame(
            $first['macd_hist_series_timestamps'],
            $first['macd_line_signal_series_timestamps'],
        );
        self::assertEqualsWithDelta(
            $first['macd']['hist'],
            $first['macd_hist_series'][array_key_last($first['macd_hist_series'])],
            self::GOLDEN_DELTA,
        );
        $expectedMacdTail = [
            -0.007722336305286959,
            -0.0030020603344795838,
            0.014185413043459105,
        ];
        foreach ($expectedMacdTail as $index => $expected) {
            self::assertEqualsWithDelta($expected, $first['macd_hist_last3'][$index], self::GOLDEN_DELTA);
        }
        self::assertSame($first['macd_hist_last3'], array_slice($first['macd_hist_series'], -3));

        self::assertNull($first['pullback_age_bars']);
        self::assertEqualsWithDelta(1.1857292759706191, $first['volume_ratio'], self::GOLDEN_DELTA);
        self::assertEqualsWithDelta(124.5057376377151, $first['ma_21_plus_k_atr'], self::GOLDEN_DELTA);
    }

    public function testMacdFullSeriesValidationRejectsInvalidValuesInsteadOfFilteringThem(): void
    {
        self::assertTrue(
            class_exists(CanonicalFiniteSeriesValidator::class),
            'The calculator needs a directly testable strict finite-series boundary.',
        );
        $validator = new CanonicalFiniteSeriesValidator();
        self::assertSame([0.25, -0.5, 0.75], $validator->validate([0.25, -0.5, 0.75]));

        foreach ([null, 1, '1.0', INF, -INF, NAN] as $invalid) {
            try {
                $validator->validate([0.25, $invalid, 0.75]);
                self::fail('Invalid MACD series values must not be silently filtered.');
            } catch (CanonicalIndicatorProjectionException $exception) {
                self::assertSame('canonical_indicator_calculation_invalid', $exception->getMessage());
            }
        }
    }

    public function testItRejectsAnAllZeroVolumeWindowBecauseVwapIsUnavailable(): void
    {
        $calculator = new CanonicalPhpIndicatorCalculator(
            new Rsi(),
            new Macd(),
            new Ema(),
            new Adx(),
            new Sma(),
            new AtrCalculator(null),
            new Vwap(),
            new Bollinger(),
        );

        $this->expectException(CanonicalIndicatorProjectionException::class);
        $this->expectExceptionMessage('canonical_indicator_calculation_invalid');
        $calculator->calculate($this->window('0'));
    }

    /** @param array<string|int, mixed> $context */
    private function assertFiniteNumericContext(array $context): void
    {
        foreach ($context as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                $this->assertFiniteNumericContext($value);
                continue;
            }
            if ($value === null) {
                self::assertContains($key, ['pullback_age_bars', 'volume_ratio']);
                continue;
            }
            if (is_float($value)) {
                self::assertTrue(is_finite($value), sprintf('%s must be finite', $key));
                continue;
            }
            if (is_int($value)) {
                continue;
            }

            self::assertSame('series_order', $key);
            self::assertSame('oldest_to_newest', $value);
        }
    }

    private function window(?string $volume = null): CanonicalIndicatorWindow
    {
        $records = [];
        $start = new \DateTimeImmutable('2026-01-01T00:00:00.000000Z');

        for ($i = 0; $i < 250; ++$i) {
            $open = 100.0 + ($i * 0.1) + (($i % 7) * 0.03);
            $close = $open + ((($i % 9) - 4) * 0.02);
            if ($i === 249) {
                $close = 125.23;
            }
            $high = max($open, $close) + 0.17 + (($i % 5) * 0.01);
            $low = min($open, $close) - 0.13 - (($i % 3) * 0.01);
            $openAt = $start->modify(sprintf('+%d minutes', $i));
            $closeAt = $openAt->modify('+1 minute');

            $records[] = [
                'schema_version' => CanonicalIndicatorCandle::SCHEMA_VERSION,
                'source_record_id' => hash('sha256', 'canonical-php-indicator-' . $i),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
                'symbol' => 'BTCUSDT',
                'timeframe' => '1m',
                'open_at' => $openAt->format('Y-m-d\TH:i:s.u\Z'),
                'close_at' => $closeAt->format('Y-m-d\TH:i:s.u\Z'),
                'available_at' => $closeAt->format('Y-m-d\TH:i:s.u\Z'),
                'open' => $this->decimal($open),
                'high' => $this->decimal($high),
                'low' => $this->decimal($low),
                'close' => $this->decimal($close),
                'volume' => $volume ?? $this->decimal(10.0 + (($i * 13) % 29) + (($i % 4) * 0.25)),
                'complete' => true,
            ];
        }

        return new CanonicalIndicatorWindow(
            $records,
            ['source_network' => 'mainnet', 'market_data_venue' => 'okx', 'market_type' => 'perpetual'],
            'BTCUSDT',
            '1m',
            '2026-01-01T04:10:00.000000Z',
        );
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8F', $value), '0'), '.');
    }
}
