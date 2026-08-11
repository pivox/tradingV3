<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Provider;

use App\Config\IndicatorConfig;
use App\Indicator\Context\IndicatorContextBuilder;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volume\Vwap;
use App\Indicator\Exception\InvalidKlineChronologyException;
use App\Indicator\Provider\IndicatorEngineProvider;
use App\Indicator\Registry\ConditionRegistry as CompiledRegistry;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\ConditionLoader\TimeframeEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(IndicatorEngineProvider::class)]
#[CoversClass(IndicatorContextBuilder::class)]
final class IndicatorEngineProviderSeriesChronologyTest extends TestCase
{
    /** @param list<array<string, int|float|\DateTimeImmutable>> $klines */
    #[DataProvider('invalidChronologyProvider')]
    public function testBuildContextRejectsUnprovenKlineChronology(array $klines, string $reason): void
    {
        $this->expectException(InvalidKlineChronologyException::class);
        $this->expectExceptionMessage($reason);

        $this->provider()->buildContext('BTCUSDT', '5m', $klines);
    }

    /** @return iterable<string, array{list<array<string, int|float|\DateTimeImmutable>>, string}> */
    public static function invalidChronologyProvider(): iterable
    {
        $canonical = self::klines();

        yield 'reversed' => [array_reverse($canonical), 'reversed_timestamp'];

        $duplicate = $canonical;
        $duplicate[20]['open_time'] = $duplicate[19]['open_time'];
        yield 'duplicate' => [$duplicate, 'duplicate_timestamp'];

        $gap = $canonical;
        $gap[20]['open_time'] = $gap[20]['open_time']->modify('+5 minutes');
        yield 'gap' => [$gap, 'timestamp_gap'];

        $missing = $canonical;
        unset($missing[20]['open_time']);
        yield 'missing' => [$missing, 'missing_timestamp'];
    }

    public function testBuildContextPublishesCanonicalOrderOnlyWithContinuousAlignedTimestamps(): void
    {
        $klines = self::klines();

        $context = $this->provider()->buildContext('BTCUSDT', '5m', $klines);

        self::assertSame('oldest_to_newest', $context['series_order']);
        self::assertSame(
            array_map(static fn (array $kline): int => $kline['open_time']->getTimestamp(), $klines),
            $context['series_timestamps'],
        );
        self::assertCount(count($context['macd_hist_series']), $context['macd_hist_series_timestamps']);
        self::assertSame($context['macd_hist_series'], $context['macd_line_signal_series']);
        self::assertSame($context['macd_hist_series_timestamps'], $context['macd_line_signal_series_timestamps']);
        self::assertSame(end($context['series_timestamps']), $context['candle_open_ts']);
        self::assertSame($context['candle_open_ts'], end($context['macd_hist_series_timestamps']));
        self::assertSame($context['candle_open_ts'], end($context['macd_line_signal_series_timestamps']));
    }

    private function provider(): IndicatorEngineProvider
    {
        $atr = new AtrCalculator();
        $locator = new ServiceLocator([]);

        return new IndicatorEngineProvider(
            new IndicatorContextBuilder(new Rsi(), new Macd(), new Adx(), new Vwap(), $atr, new Sma()),
            new TimeframeEvaluator(new ConditionRegistry([], $locator, new NullLogger())),
            new CompiledRegistry([], $locator),
            $atr,
            new IndicatorConfig(),
            new NullLogger(),
        );
    }

    /** @return list<array<string, int|float|\DateTimeImmutable>> */
    private static function klines(): array
    {
        $start = new \DateTimeImmutable('2026-08-11T00:00:00+00:00');
        $klines = [];
        for ($index = 0; $index < 40; ++$index) {
            $close = 100.0 + $index;
            $klines[] = [
                'open_time' => $start->modify(sprintf('+%d minutes', $index * 5)),
                'open' => $close - 0.5,
                'high' => $close + 1.0,
                'low' => $close - 1.0,
                'close' => $close,
                'volume' => 10.0,
            ];
        }

        return $klines;
    }
}
