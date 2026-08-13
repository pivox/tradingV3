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
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorCandle;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorWindow;
use App\TradingCore\Backtesting\Indicator\CanonicalPhpIndicatorCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalPhpIndicatorCalculator::class)]
final class CanonicalPhpIndicatorCalculatorTest extends TestCase
{
    private const GOLDEN_DELTA = 1.0e-12;

    public function testItCalculatesFiniteDeterministicScalarIndicators(): void
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
        ], array_keys($first));
        self::assertSame([14, 15], array_keys($first['adx']));

        foreach ($first as $name => $value) {
            if ($name === 'adx') {
                foreach ($value as $adx) {
                    self::assertIsFloat($adx);
                    self::assertTrue(is_finite($adx));
                }
                continue;
            }

            self::assertIsFloat($value);
            self::assertTrue(is_finite($value));
        }

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
        self::assertGreaterThan($first['bb_lower'], $first['bb_middle']);
        self::assertGreaterThan($first['bb_middle'], $first['bb_upper']);
        self::assertGreaterThan(0.0, $first['atr']);
        self::assertGreaterThanOrEqual(0.0, $first['rsi']);
        self::assertLessThanOrEqual(100.0, $first['rsi']);
    }

    private function window(): CanonicalIndicatorWindow
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
                'volume' => $this->decimal(10.0 + (($i * 13) % 29) + (($i % 4) * 0.25)),
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
