<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Core\Momentum;

use App\Indicator\Core\Momentum\Macd;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Macd::class)]
final class MacdPhpAlignmentTest extends TestCase
{
    private const DELTA = 1.0e-12;

    public function testPhpSeriesAlignsFastAndSlowEmaBySourceCandle(): void
    {
        $closes = [];
        for ($i = 0; $i < 90; ++$i) {
            $closes[] = 80.0 + ($i * 0.17) + (($i % 8) ** 2 * 0.025) - (($i % 5) * 0.04);
        }

        $expected = $this->expectedMacd($closes, 12, 26, 9);
        $calculator = new Macd();
        $actual = $calculator->calculateFullPhp($closes, 12, 26, 9);

        $this->assertSeriesEquals($expected['macd'], $actual['macd']);
        $this->assertSeriesEquals($expected['signal'], $actual['signal']);
        $this->assertSeriesEquals($expected['hist'], $actual['hist']);

        $scalar = $calculator->calculatePhp($closes, 12, 26, 9);
        self::assertEqualsWithDelta($expected['macd'][array_key_last($expected['macd'])], $scalar['macd'], self::DELTA);
        self::assertEqualsWithDelta($expected['signal'][array_key_last($expected['signal'])], $scalar['signal'], self::DELTA);
        self::assertEqualsWithDelta($expected['hist'][array_key_last($expected['hist'])], $scalar['hist'], self::DELTA);
    }

    /**
     * Independently keys each EMA by the source candle index, intersects those
     * keys, then seeds signal EMA over the time-aligned MACD sequence.
     *
     * @param list<float> $closes
     * @return array{macd: list<float>, signal: list<float>, hist: list<float>}
     */
    private function expectedMacd(array $closes, int $fast, int $slow, int $signal): array
    {
        $fastEma = $this->emaBySourceIndex($closes, $fast);
        $slowEma = $this->emaBySourceIndex($closes, $slow);
        $macdByIndex = [];
        foreach ($slowEma as $sourceIndex => $slowValue) {
            if (isset($fastEma[$sourceIndex])) {
                $macdByIndex[$sourceIndex] = $fastEma[$sourceIndex] - $slowValue;
            }
        }

        $macd = array_values($macdByIndex);
        $signalSeries = array_values($this->emaBySourceIndex($macd, $signal));
        $macd = array_slice($macd, count($macd) - count($signalSeries));
        $hist = [];
        foreach ($macd as $index => $value) {
            $hist[] = $value - $signalSeries[$index];
        }

        return ['macd' => $macd, 'signal' => $signalSeries, 'hist' => $hist];
    }

    /**
     * @param list<float> $values
     * @return array<int, float>
     */
    private function emaBySourceIndex(array $values, int $period): array
    {
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        $series = [$period - 1 => $ema];
        $alpha = 2.0 / ($period + 1.0);
        for ($sourceIndex = $period, $count = count($values); $sourceIndex < $count; ++$sourceIndex) {
            $ema = ($alpha * $values[$sourceIndex]) + ((1.0 - $alpha) * $ema);
            $series[$sourceIndex] = $ema;
        }

        return $series;
    }

    /**
     * @param list<float> $expected
     * @param list<float> $actual
     */
    private function assertSeriesEquals(array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual);
        foreach ($expected as $index => $value) {
            self::assertEqualsWithDelta($value, $actual[$index], self::DELTA, 'Mismatch at series index ' . $index);
        }
    }
}
