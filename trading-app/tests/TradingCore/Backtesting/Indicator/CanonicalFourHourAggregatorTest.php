<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting\Indicator;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\TradingCore\Backtesting\Indicator\CanonicalFourHourAggregator;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorCandle;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjectionException;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorWindow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalFourHourAggregator::class)]
#[CoversClass(CanonicalIndicatorCandle::class)]
#[CoversClass(CanonicalIndicatorWindow::class)]
final class CanonicalFourHourAggregatorTest extends TestCase
{
    private const BINDING = [
        'source_network' => 'mainnet',
        'market_data_venue' => 'okx',
        'market_type' => 'perpetual',
    ];
    private const EVALUATED_AT = '2026-02-12T00:00:00.000000Z';

    public function testAggregatesExactlyOneThousandHourlyCandlesIntoCanonicalUtcFourHourBars(): void
    {
        $records = $this->records();
        $records[0] = $this->withPrices($records[0], '100', '103', '99', '102', '0.1', 18_007);
        $records[1] = $this->withPrices($records[1], '102', '105', '98', '101', '0.2', 7);
        $records[2] = $this->withPrices($records[2], '101', '107', '100', '106', '1.25', 3);
        $records[3] = $this->withPrices($records[3], '106', '106', '97', '104', '2.45', 5);
        for ($index = 4; $index < 8; ++$index) {
            $records[$index]['volume'] = '0';
        }

        $window = (new CanonicalFourHourAggregator())->aggregate(
            $records,
            self::BINDING,
            'BTCUSDT',
            self::EVALUATED_AT,
        );

        self::assertCount(250, $window->candles());
        self::assertSame('BTCUSDT', $window->symbol());
        self::assertSame('4h', $window->timeframe());

        $first = $window->candles()[0];
        $expectedWithoutId = [
            'schema_version' => 'canonical-derived-indicator-candle.v1',
            'component_source_record_ids' => array_column(array_slice($records, 0, 4), 'source_record_id'),
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'timeframe' => '4h',
            'open_at' => '2026-01-01T00:00:00.000000Z',
            'close_at' => '2026-01-01T04:00:00.000000Z',
            'available_at' => '2026-01-01T06:00:07.000000Z',
            'open' => '100',
            'high' => '107',
            'low' => '97',
            'close' => '104',
            'volume' => '4',
            'complete' => true,
            'origin' => 'aggregate_1h_utc',
        ];
        $expected = ['derived_record_id' => hash('sha256', CanonicalJson::encode($expectedWithoutId))] + $expectedWithoutId;

        self::assertSame($expected, $first->toArray());
        self::assertSame('2026-01-01T00:00:00.000000Z', $first->openTimestamp()->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-01-01T04:00:00.000000Z', $first->closeTimestamp()->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-01-01T06:00:07.000000Z', $first->availableTimestamp()->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame(14_400, $first->durationSeconds());
        self::assertSame('2026-01-01T04:00:00.000000Z', $window->candles()[1]->openAt);
        self::assertSame('0', $window->candles()[1]->volume);
        self::assertSame('2026-01-01T08:00:00.000000Z', $window->candles()[2]->openAt);
        self::assertSame('2026-01-02T00:00:00.000000Z', $window->candles()[6]->openAt);

        $derivedRecords = array_map(
            static fn (CanonicalIndicatorCandle $candle): array => $candle->toArray(),
            $window->candles(),
        );
        self::assertSame('sha256:' . hash('sha256', CanonicalJson::encode($derivedRecords)), $window->windowHash());

        $replay = (new CanonicalFourHourAggregator())->aggregate(
            $records,
            self::BINDING,
            'BTCUSDT',
            self::EVALUATED_AT,
        );
        self::assertSame($derivedRecords, array_map(
            static fn (CanonicalIndicatorCandle $candle): array => $candle->toArray(),
            $replay->candles(),
        ));
        self::assertSame($window->windowHash(), $replay->windowHash());
    }

    public function testRejectsAnythingOtherThanExactlyOneThousandHourlyRecords(): void
    {
        $this->assertFailure(array_slice($this->records(), 0, 999), 'canonical_indicator_four_hour_count_invalid');
    }

    public function testRejectsARehashedDerivedWindowThatReusesEvidenceAcrossFourHourBars(): void
    {
        $window = (new CanonicalFourHourAggregator())->aggregate(
            $this->records(),
            self::BINDING,
            'BTCUSDT',
            self::EVALUATED_AT,
        );
        $derivedRecords = array_map(
            static fn (CanonicalIndicatorCandle $candle): array => $candle->toArray(),
            $window->candles(),
        );
        $derivedRecords[1]['component_source_record_ids'][0] = $derivedRecords[0]['component_source_record_ids'][0];
        $forged = $derivedRecords[1];
        unset($forged['derived_record_id']);
        $derivedRecords[1]['derived_record_id'] = hash('sha256', CanonicalJson::encode($forged));

        try {
            CanonicalIndicatorWindow::fromDerivedRecords(
                $derivedRecords,
                self::BINDING,
                'BTCUSDT',
                self::EVALUATED_AT,
            );
            self::fail('Expected duplicate derived component evidence rejection.');
        } catch (CanonicalIndicatorProjectionException $exception) {
            self::assertSame('canonical_indicator_derived_component_duplicate', $exception->getMessage());
        }
    }

    /** @param callable(list<array<string, mixed>>): void $mutate */
    #[DataProvider('invalidInputProvider')]
    public function testRejectsInvalidHourlyComponents(callable $mutate, string $reason): void
    {
        $records = $this->records();
        $mutate($records);

        $this->assertFailure($records, $reason);
    }

    /** @return iterable<string, array{callable(list<array<string, mixed>>): void, string}> */
    public static function invalidInputProvider(): iterable
    {
        yield 'first hour not on UTC four-hour boundary' => [static function (array &$records): void {
            $records = self::recordsFrom('2026-01-01T01:00:00.000000Z');
        }, 'canonical_indicator_four_hour_alignment_invalid'];
        yield 'missing hour creates gap' => [static function (array &$records): void {
            $replacement = $records[501];
            array_splice($records, 500, 1);
            $records[] = $replacement;
        }, 'canonical_indicator_window_chronology_invalid'];
        yield 'duplicate source identity' => [static function (array &$records): void {
            $records[500]['source_record_id'] = $records[499]['source_record_id'];
        }, 'canonical_indicator_source_record_duplicate'];
        yield 'reversed components' => [static function (array &$records): void {
            [$records[499], $records[500]] = [$records[500], $records[499]];
        }, 'canonical_indicator_window_chronology_invalid'];
        yield 'source candle timeframe is not hourly' => [static function (array &$records): void {
            $records[500]['timeframe'] = '15m';
            $records[500]['close_at'] = (new \DateTimeImmutable($records[500]['open_at']))
                ->modify('+900 seconds')
                ->format('Y-m-d\TH:i:s.u\Z');
            $records[500]['available_at'] = $records[500]['close_at'];
        }, 'canonical_indicator_timeframe_mismatch'];
        yield 'source binding mismatch' => [static function (array &$records): void {
            $records[500]['market_data_venue'] = 'hyperliquid';
        }, 'canonical_indicator_source_binding_mismatch'];
        yield 'symbol mismatch' => [static function (array &$records): void {
            $records[500]['symbol'] = 'ETHUSDT';
        }, 'canonical_indicator_symbol_mismatch'];
        yield 'incomplete source candle' => [static function (array &$records): void {
            $records[500]['complete'] = false;
        }, 'canonical_indicator_candle_incomplete'];
    }

    public function testRejectsFutureClose(): void
    {
        $this->assertFailure(
            $this->records(),
            'canonical_indicator_future_close',
            '2026-02-11T15:59:59.999999Z',
        );
    }

    public function testRejectsFutureAvailability(): void
    {
        $records = $this->records();
        $records[999]['available_at'] = '2026-02-12T00:00:01.000000Z';

        $this->assertFailure($records, 'canonical_indicator_future_availability');
    }

    public function testRejectsInvalidExpectedBinding(): void
    {
        $binding = self::BINDING;
        $binding['market_type'] = 'spot';

        $this->assertFailure($this->records(), 'canonical_indicator_source_binding_invalid', binding: $binding);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed>       $binding
     */
    private function assertFailure(
        array $records,
        string $reason,
        string $evaluatedAt = self::EVALUATED_AT,
        array $binding = self::BINDING,
    ): void {
        try {
            (new CanonicalFourHourAggregator())->aggregate($records, $binding, 'BTCUSDT', $evaluatedAt);
            self::fail('Expected canonical four-hour aggregation rejection.');
        } catch (CanonicalIndicatorProjectionException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        return self::recordsFrom('2026-01-01T00:00:00.000000Z');
    }

    /** @return list<array<string, mixed>> */
    private static function recordsFrom(string $startAt): array
    {
        $start = new \DateTimeImmutable($startAt);
        $records = [];
        for ($index = 0; $index < 1000; ++$index) {
            $openAt = $start->modify('+' . ($index * 3600) . ' seconds');
            $closeAt = $openAt->modify('+3600 seconds');
            $records[] = [
                'schema_version' => 'backtest-candle.v1',
                'source_record_id' => hash('sha256', 'hour-' . $startAt . '-' . $index),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
                'symbol' => 'BTCUSDT',
                'timeframe' => '1h',
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

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function withPrices(
        array $record,
        string $open,
        string $high,
        string $low,
        string $close,
        string $volume,
        int $availabilityDelaySeconds,
    ): array {
        $record['open'] = $open;
        $record['high'] = $high;
        $record['low'] = $low;
        $record['close'] = $close;
        $record['volume'] = $volume;
        $record['available_at'] = (new \DateTimeImmutable($record['close_at']))
            ->modify('+' . $availabilityDelaySeconds . ' seconds')
            ->format('Y-m-d\TH:i:s.u\Z');

        return $record;
    }
}
