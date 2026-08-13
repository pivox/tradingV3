<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Indicator;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorCandle;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectionException;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalIndicatorCandle::class)]
#[CoversClass(CanonicalIndicatorWindow::class)]
#[CoversClass(CanonicalIndicatorProjectionException::class)]
final class CanonicalIndicatorWindowTest extends TestCase
{
    private const BINDING = [
        'source_network' => 'mainnet',
        'market_data_venue' => 'okx',
        'market_type' => 'perpetual',
    ];

    public function testAcceptsAnExactCanonicalNativeWindowAndExposesStableEvidence(): void
    {
        $records = $this->records();
        $window = new CanonicalIndicatorWindow(
            $records,
            self::BINDING,
            'BTCUSDT',
            '1m',
            '2026-08-01T04:10:00.000000Z',
        );

        self::assertCount(250, $window->candles());
        self::assertSame('BTCUSDT', $window->symbol());
        self::assertSame('1m', $window->timeframe());
        self::assertSame(
            'sha256:' . hash('sha256', CanonicalJson::encode($records)),
            $window->windowHash(),
        );

        $first = $window->candles()[0];
        self::assertSame($records[0], $first->toArray());
        self::assertSame('2026-08-01T00:00:00.000000Z', $first->openTimestamp()->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-08-01T00:01:00.000000Z', $first->closeTimestamp()->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-08-01T00:01:00.000000Z', $first->availableTimestamp()->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame(60, $first->durationSeconds());
    }

    public function testRejectsAnythingOtherThanExactlyTwoHundredAndFiftyRecords(): void
    {
        $this->assertFailure(array_slice($this->records(), 0, 249), 'canonical_indicator_window_count_invalid');
    }

    /** @param callable(list<array<string, mixed>>): void $mutate */
    #[DataProvider('invalidRecordProvider')]
    public function testRejectsInvalidCanonicalRecords(callable $mutate, string $reason): void
    {
        $records = $this->records();
        $mutate($records);

        $this->assertFailure($records, $reason);
    }

    /** @return iterable<string, array{callable(list<array<string, mixed>>): void, string}> */
    public static function invalidRecordProvider(): iterable
    {
        yield 'extra field' => [static function (array &$records): void {
            $records[0]['extra'] = 'forbidden';
        }, 'canonical_indicator_candle_shape_invalid'];
        yield 'missing field' => [static function (array &$records): void {
            unset($records[0]['volume']);
        }, 'canonical_indicator_candle_shape_invalid'];
        yield 'wrong field type' => [static function (array &$records): void {
            $records[0]['complete'] = 1;
        }, 'canonical_indicator_candle_shape_invalid'];
        yield 'incomplete' => [static function (array &$records): void {
            $records[0]['complete'] = false;
        }, 'canonical_indicator_candle_incomplete'];
        yield 'binding mismatch' => [static function (array &$records): void {
            $records[0]['source_network'] = 'testnet';
        }, 'canonical_indicator_source_binding_mismatch'];
        yield 'future close' => [static function (array &$records): void {
            $records[249]['close_at'] = '2026-08-01T04:11:00.000000Z';
        }, 'canonical_indicator_candle_time_invalid'];
        yield 'future availability' => [static function (array &$records): void {
            $records[249]['available_at'] = '2026-08-01T04:11:00.000000Z';
        }, 'canonical_indicator_future_availability'];
        yield 'gap' => [static function (array &$records): void {
            $records[100]['open_at'] = '2026-08-01T01:41:00.000000Z';
            $records[100]['close_at'] = '2026-08-01T01:42:00.000000Z';
            $records[100]['available_at'] = '2026-08-01T01:42:00.000000Z';
        }, 'canonical_indicator_window_chronology_invalid'];
        yield 'duplicate' => [static function (array &$records): void {
            $records[100] = $records[99];
        }, 'canonical_indicator_window_chronology_invalid'];
        yield 'duplicate source identity with contiguous timestamps' => [static function (array &$records): void {
            $records[100]['source_record_id'] = $records[99]['source_record_id'];
        }, 'canonical_indicator_source_record_duplicate'];
        yield 'reversal' => [static function (array &$records): void {
            [$records[99], $records[100]] = [$records[100], $records[99]];
        }, 'canonical_indicator_window_chronology_invalid'];
        yield 'bad grid' => [static function (array &$records): void {
            $records[0]['open_at'] = '2026-08-01T00:00:01.000000Z';
            $records[0]['close_at'] = '2026-08-01T00:01:01.000000Z';
            $records[0]['available_at'] = '2026-08-01T00:01:01.000000Z';
        }, 'canonical_indicator_candle_time_invalid'];
        yield 'bad geometry' => [static function (array &$records): void {
            $records[0]['low'] = '101';
        }, 'canonical_indicator_candle_geometry_invalid'];
        yield 'non-canonical decimal' => [static function (array &$records): void {
            $records[0]['open'] = '100.0';
        }, 'canonical_indicator_candle_decimal_invalid'];
    }

    public function testRejectsFourHourSourceRecords(): void
    {
        $records = $this->records('4h');
        $this->assertFailure($records, 'canonical_indicator_timeframe_invalid', timeframe: '4h');
    }

    public function testRejectsFutureCloseWithoutConfusingItWithMalformedDuration(): void
    {
        $this->assertFailure(
            $this->records(),
            'canonical_indicator_future_close',
            evaluatedAt: '2026-08-01T04:09:59.999999Z',
        );
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function assertFailure(
        array $records,
        string $reason,
        string $timeframe = '1m',
        string $evaluatedAt = '2026-08-01T04:10:00.000000Z',
    ): void {
        try {
            new CanonicalIndicatorWindow($records, self::BINDING, 'BTCUSDT', $timeframe, $evaluatedAt);
            self::fail('Expected canonical indicator window rejection.');
        } catch (CanonicalIndicatorProjectionException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function records(string $timeframe = '1m'): array
    {
        $duration = $timeframe === '4h' ? 14_400 : 60;
        $start = new \DateTimeImmutable('2026-08-01T00:00:00.000000Z');
        $records = [];
        for ($index = 0; $index < 250; ++$index) {
            $openAt = $start->modify('+' . ($index * $duration) . ' seconds');
            $closeAt = $openAt->modify('+' . $duration . ' seconds');
            $records[] = [
                'schema_version' => 'backtest-candle.v1',
                'source_record_id' => hash('sha256', 'record-' . $index),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
                'symbol' => 'BTCUSDT',
                'timeframe' => $timeframe,
                'open_at' => $openAt->format('Y-m-d\TH:i:s.u\Z'),
                'close_at' => $closeAt->format('Y-m-d\TH:i:s.u\Z'),
                'available_at' => $closeAt->format('Y-m-d\TH:i:s.u\Z'),
                'open' => '100',
                'high' => '102',
                'low' => '99',
                'close' => '101',
                'volume' => '5',
                'complete' => true,
            ];
        }

        return $records;
    }
}
