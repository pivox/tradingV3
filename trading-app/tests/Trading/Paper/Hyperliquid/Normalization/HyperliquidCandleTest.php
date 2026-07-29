<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidCandle::class)]
final class HyperliquidCandleTest extends TestCase
{
    public function testNormalizesTheExactPublicCandleVectorIntoAnImmutableValue(): void
    {
        $candle = HyperliquidCandle::fromApiRow([
            'T' => 1_681_924_499_999,
            'c' => '29258.0',
            'h' => '29309.0',
            'i' => '15m',
            'l' => '29250.0',
            'n' => 189,
            'o' => '29295.0',
            's' => 'BTC',
            't' => 1_681_923_600_000,
            'v' => '0.98639',
        ], 'BTC', '15m');

        self::assertSame('BTC', $candle->coin);
        self::assertSame('15m', $candle->interval);
        self::assertSame(1_681_923_600_000, $candle->startTime);
        self::assertSame(1_681_924_499_999, $candle->closeTime);
        self::assertSame('29295', (string) $candle->open);
        self::assertSame('29309', (string) $candle->high);
        self::assertSame('29250', (string) $candle->low);
        self::assertSame('29258', (string) $candle->close);
        self::assertSame('0.98639', (string) $candle->volume);
        self::assertSame(189, $candle->tradeCount);
        self::assertTrue((new \ReflectionClass($candle))->isReadOnly());
    }

    public function testCanonicalizesAcceptedPlainDecimalsIncludingZero(): void
    {
        $candle = HyperliquidCandle::fromApiRow($this->row([
            'o' => '100.000000',
            'h' => '100.5000',
            'l' => '99.25000',
            'c' => '100.0',
            'v' => '0.000000',
        ]), 'BTC', '1m');

        self::assertSame('100', (string) $candle->open);
        self::assertSame('100.5', (string) $candle->high);
        self::assertSame('99.25', (string) $candle->low);
        self::assertSame('100', (string) $candle->close);
        self::assertSame('0', (string) $candle->volume);
    }

    /** @return iterable<string, array{mixed, string, string, string}> */
    public static function invalidRootsAndShapes(): iterable
    {
        $row = self::validRow();

        yield 'scalar root' => ['not-an-array', 'BTC', '1m', 'hyperliquid_candle_shape_invalid'];
        yield 'null root' => [null, 'BTC', '1m', 'hyperliquid_candle_shape_invalid'];
        yield 'list root' => [[$row], 'BTC', '1m', 'hyperliquid_candle_shape_invalid'];
        yield 'empty associative root' => [['bad' => 'shape'], 'BTC', '1m', 'hyperliquid_candle_shape_invalid'];

        $missing = $row;
        unset($missing['v']);
        yield 'missing key' => [$missing, 'BTC', '1m', 'hyperliquid_candle_shape_invalid'];

        yield 'extra key' => [
            $row + ['x' => 'secret'],
            'BTC',
            '1m',
            'hyperliquid_candle_shape_invalid',
        ];
    }

    #[DataProvider('invalidRootsAndShapes')]
    public function testRejectsInvalidRootsAndExactKeyShapes(
        mixed $row,
        string $coin,
        string $interval,
        string $reason,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        HyperliquidCandle::fromApiRow($row, $coin, $interval);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string, string}> */
    public static function invalidContextRows(): iterable
    {
        yield 'unsupported expected coin' => [
            self::validRow(),
            'SOL',
            '1m',
            'hyperliquid_candle_expected_coin_invalid',
        ];
        yield 'normalized symbol is not native coin' => [
            self::validRow(),
            'BTCUSDT',
            '1m',
            'hyperliquid_candle_expected_coin_invalid',
        ];
        yield 'unsupported expected interval' => [
            self::validRow(),
            'BTC',
            '3m',
            'hyperliquid_candle_expected_interval_invalid',
        ];
        yield 'row coin mismatch' => [
            self::validRow(['s' => 'ETH']),
            'BTC',
            '1m',
            'hyperliquid_candle_coin_mismatch',
        ];
        yield 'row coin wrong type' => [
            self::validRow(['s' => 123]),
            'BTC',
            '1m',
            'hyperliquid_candle_coin_mismatch',
        ];
        yield 'row interval mismatch' => [
            self::validRow(['i' => '5m']),
            'BTC',
            '1m',
            'hyperliquid_candle_interval_mismatch',
        ];
        yield 'row interval wrong type' => [
            self::validRow(['i' => null]),
            'BTC',
            '1m',
            'hyperliquid_candle_interval_mismatch',
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidContextRows')]
    public function testRejectsUnsupportedOrMismatchedCoinAndInterval(
        array $row,
        string $coin,
        string $interval,
        string $reason,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        HyperliquidCandle::fromApiRow($row, $coin, $interval);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidIntegerRows(): iterable
    {
        foreach (['t', 'T', 'n'] as $field) {
            yield $field . ' is a string' => [
                self::validRow([$field => '1']),
                'hyperliquid_candle_integer_invalid',
            ];
            yield $field . ' is negative' => [
                self::validRow([$field => -1]),
                'hyperliquid_candle_integer_invalid',
            ];
            yield $field . ' is a float' => [
                self::validRow([$field => 1.0]),
                'hyperliquid_candle_integer_invalid',
            ];
        }
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidIntegerRows')]
    public function testRejectsNonIntegerOrNegativeTimesAndTradeCounts(array $row, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        HyperliquidCandle::fromApiRow($row, 'BTC', '1m');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidDecimalRows(): iterable
    {
        $invalid = [
            'integer' => 100,
            'float' => 100.0,
            'null' => null,
            'blank' => '',
            'whitespace' => ' 100',
            'explicit plus' => '+100',
            'negative zero' => '-0',
            'negative decimal zero' => '-0.0',
            'leading zero' => '0100',
            'leading decimal point' => '.5',
            'trailing decimal point' => '1.',
            'lower exponent' => '1e2',
            'upper exponent' => '1E2',
            'NaN' => 'NaN',
            'positive infinity' => 'INF',
            'negative infinity' => '-INF',
            'unsafe length' => str_repeat('9', 129),
        ];

        foreach (['o', 'h', 'l', 'c', 'v'] as $field) {
            foreach ($invalid as $label => $value) {
                yield $field . ' ' . $label => [
                    self::validRow([$field => $value]),
                    'hyperliquid_candle_decimal_invalid',
                ];
            }
        }
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidDecimalRows')]
    public function testRejectsEveryNonPlainOrUnsafeDecimal(array $row, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        HyperliquidCandle::fromApiRow($row, 'BTC', '1m');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPriceAndVolumeRows(): iterable
    {
        foreach (['o', 'h', 'l', 'c'] as $field) {
            yield $field . ' zero' => [
                self::validRow([$field => '0']),
                'hyperliquid_candle_price_invalid',
            ];
            yield $field . ' negative' => [
                self::validRow([$field => '-0.1']),
                'hyperliquid_candle_price_invalid',
            ];
        }

        yield 'negative volume' => [
            self::validRow(['v' => '-0.1']),
            'hyperliquid_candle_volume_invalid',
        ];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidPriceAndVolumeRows')]
    public function testRejectsNonPositivePricesAndNegativeVolume(array $row, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        HyperliquidCandle::fromApiRow($row, 'BTC', '1m');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidGeometryRows(): iterable
    {
        yield 'high below open' => [self::validRow(['o' => '102', 'h' => '101'])];
        yield 'high below close' => [self::validRow(['c' => '102', 'h' => '101'])];
        yield 'high below low' => [self::validRow(['h' => '98', 'l' => '99'])];
        yield 'low above open' => [self::validRow(['o' => '98', 'l' => '99'])];
        yield 'low above close' => [self::validRow(['c' => '98', 'l' => '99'])];
        yield 'low above high' => [self::validRow(['h' => '101', 'l' => '102'])];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('invalidGeometryRows')]
    public function testRejectsImpossibleOhlcGeometry(array $row): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_candle_geometry_invalid');

        HyperliquidCandle::fromApiRow($row, 'BTC', '1m');
    }

    public function testRejectsCloseTimeThatDoesNotCloseTheExactInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_candle_close_time_invalid');

        HyperliquidCandle::fromApiRow($this->row(['T' => 60_000]), 'BTC', '1m');
    }

    public function testRejectsCloseTimeCalculationOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_candle_close_time_invalid');

        HyperliquidCandle::fromApiRow($this->row([
            't' => \PHP_INT_MAX - 59_998,
            'T' => \PHP_INT_MAX,
        ]), 'BTC', '1m');
    }

    public function testAcceptsTheLargestStartWhoseOneMinuteCloseFitsInAnInteger(): void
    {
        $candle = HyperliquidCandle::fromApiRow($this->row([
            't' => \PHP_INT_MAX - 59_999,
            'T' => \PHP_INT_MAX,
        ]), 'BTC', '1m');

        self::assertSame(\PHP_INT_MAX - 59_999, $candle->startTime);
        self::assertSame(\PHP_INT_MAX, $candle->closeTime);
    }

    public function testComputesRangeAndTrueRangeAcrossAHistoryGap(): void
    {
        $previous = HyperliquidCandle::fromApiRow($this->row([
            't' => 0,
            'T' => 59_999,
            'o' => '92',
            'h' => '95',
            'l' => '88',
            'c' => '90',
        ]), 'BTC', '1m');
        $current = HyperliquidCandle::fromApiRow($this->row([
            't' => 180_000,
            'T' => 239_999,
            'o' => '100',
            'h' => '110',
            'l' => '95',
            'c' => '105',
        ]), 'BTC', '1m');

        self::assertSame('15', (string) $current->range());
        self::assertSame('20', (string) $current->trueRange($previous));
        self::assertSame('15', (string) $current->trueRange(null));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function mismatchedPreviousCandles(): iterable
    {
        yield 'other coin' => ['ETH', '1m', 0];
        yield 'other interval' => ['BTC', '5m', 0];
        yield 'same start' => ['BTC', '1m', 60_000];
        yield 'later start' => ['BTC', '1m', 120_000];
    }

    #[DataProvider('mismatchedPreviousCandles')]
    public function testRejectsAContextOrChronologyMismatchForTrueRange(
        string $previousCoin,
        string $previousInterval,
        int $previousStart,
    ): void {
        $intervalMs = $previousInterval === '5m' ? 300_000 : 60_000;
        $previous = HyperliquidCandle::fromApiRow(self::validRow([
            's' => $previousCoin,
            'i' => $previousInterval,
            't' => $previousStart,
            'T' => $previousStart + $intervalMs - 1,
        ]), $previousCoin, $previousInterval);
        $current = HyperliquidCandle::fromApiRow(self::validRow([
            't' => 60_000,
            'T' => 119_999,
        ]), 'BTC', '1m');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_candle_previous_mismatch');

        $current->trueRange($previous);
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    private function row(array $changes = []): array
    {
        return self::validRow($changes);
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    private static function validRow(array $changes = []): array
    {
        return array_replace([
            'T' => 59_999,
            'c' => '100',
            'h' => '101',
            'i' => '1m',
            'l' => '99',
            'n' => 10,
            'o' => '100',
            's' => 'BTC',
            't' => 0,
            'v' => '5',
        ], $changes);
    }
}
