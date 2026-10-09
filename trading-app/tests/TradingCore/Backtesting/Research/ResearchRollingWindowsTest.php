<?php
declare(strict_types=1);
namespace App\Tests\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\Research\ResearchCandle;
use App\TradingCore\Backtesting\Research\ResearchRollingWindows;
use App\TradingCore\Backtesting\Research\ResearchSignalSession;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(ResearchCandle::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ResearchRollingWindows::class)]
final class ResearchRollingWindowsTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function candle(int $minute, string $symbol = 'BTCUSDT', int $start = 1667260800000): array
    {
        return ['symbol' => $symbol, 'open_ms' => $start + $minute * 60000, 'open' => '100', 'high' => '102', 'low' => '99', 'close' => '101', 'volume' => '0.1', 'source_record_id' => hash('sha256', $symbol . ':' . ($start + $minute * 60000))];
    }

    public function testClosedAggregatesAndRejectedGapPreserveState(): void
    {
        self::assertTrue(class_exists(ResearchRollingWindows::class));
        $windows = new ResearchRollingWindows('BTCUSDT', 1667260800000, 1667260800000 + 600000);
        for ($i = 0; $i < 4; ++$i) { $windows->append(ResearchCandle::fromArray(self::candle($i))); }
        self::assertSame([], $windows->bars('5m'));
        try { $windows->append(ResearchCandle::fromArray(self::candle(5))); self::fail('Gap accepted'); } catch (\InvalidArgumentException $e) { self::assertSame('research_candle_chronology_invalid', $e->getMessage()); }
        $windows->append(ResearchCandle::fromArray(self::candle(4)));
        $bar = $windows->bars('5m')[0];
        self::assertSame('100', $bar['open']);
        self::assertSame('102', $bar['high']);
        self::assertSame('99', $bar['low']);
        self::assertSame('101', $bar['close']);
        self::assertSame('0.5', $bar['volume']);
        self::assertSame(1667261100000, $bar['available_ms']);
    }

    public function testEarlierExtremaSurviveEveryAggregationLevel(): void
    {
        $start = 1667260800000;
        $windows = new ResearchRollingWindows('BTCUSDT', $start, $start + 240 * 60000);
        for ($minute = 0; $minute < 240; ++$minute) {
            $record = self::candle($minute);
            if ($minute === 0) {
                $record['open'] = '180';
                $record['high'] = '200';
                $record['low'] = '50';
            }
            $windows->append(ResearchCandle::fromArray($record));
        }
        foreach (['5m', '15m', '1h', '4h'] as $timeframe) {
            $bar = $windows->bars($timeframe)[0];
            self::assertSame('180', $bar['open'], $timeframe);
            self::assertSame('200', $bar['high'], $timeframe);
            self::assertSame('50', $bar['low'], $timeframe);
            self::assertSame('101', $bar['close'], $timeframe);
        }
    }

    public function testWarmupIsBoundedAndOnlyCompleteUtcBucketsExist(): void
    {
        self::assertTrue(class_exists(ResearchRollingWindows::class));
        $start = 1672444740000; // 2022-12-31 00:59 UTC, deliberately incomplete first hour/4h.
        $windows = new ResearchRollingWindows('BTCUSDT', $start, $start + 61000 * 60000);
        for ($i = 0; $i < 61000; ++$i) { $windows->append(ResearchCandle::fromArray(self::candle($i, start: $start))); }
        self::assertTrue($windows->ready());
        foreach (ResearchRollingWindows::STEPS as $timeframe => $step) {
            self::assertCount(250, $windows->bars($timeframe));
            foreach ($windows->bars($timeframe) as $bar) {
                self::assertSame(0, $bar['open_ms'] % $step);
                self::assertLessThanOrEqual($start + 61000 * 60000, $bar['available_ms']);
            }
            self::assertCount(250, $windows->numericSeries($timeframe)->closes);
        }
        $before = $windows->bars('4h');
        $this->expectException(\InvalidArgumentException::class);
        try { $windows->append(ResearchCandle::fromArray(self::candle(61000, start: $start))); } finally { self::assertSame($before, $windows->bars('4h')); }
    }

    public function testMalformedObservationsFailClosed(): void
    {
        self::assertTrue(class_exists(ResearchCandle::class));
        foreach (['symbol' => 'SHIBUSDT', 'open_ms' => 1667260800001, 'close' => 'NaN', 'volume' => '-1', 'high' => '90', 'source_record_id' => str_repeat('A', 64), 'extra' => true] as $key => $value) {
            $record = self::candle(0); $record[$key] = $value;
            try { ResearchCandle::fromArray($record); self::fail('Malformed observation accepted: ' . $key); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testDecimalOhlcValidationDoesNotHideInconsistencyBehindFloatRounding(): void
    {
        $record = self::candle(0);
        $record['high'] = '100.99999999999999999999';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('research_candle_ohlc_invalid');
        ResearchCandle::fromArray($record);
    }

    public function testFourHourValuesMatchCanonicalHourlyAggregationWithoutBorrowingItsSourceIdentity(): void
    {
        $start = 1667260800000;
        $end = $start + 60000 * 60000;
        $windows = new ResearchRollingWindows('BTCUSDT', $start, $end);
        for ($i = 0; $i < 60000; ++$i) {
            $record = self::candle($i);
            if ($i % 60 === 0) {
                $hour = intdiv($i, 60);
                $record['high'] = (string) (110 + $hour % 7);
                $record['low'] = (string) (90 - $hour % 5);
            }
            $windows->append(ResearchCandle::fromArray($record));
        }
        $hourly = [];
        for ($i = 0; $i < 1000; ++$i) {
            $open = $start + $i * 3600000;
            $hourly[] = ['schema_version' => \App\TradingCore\Backtesting\Indicator\CanonicalIndicatorCandle::SCHEMA_VERSION,
                'source_record_id' => hash('sha256', 'canonical-hour:' . $i), 'source_network' => 'mainnet', 'market_data_venue' => 'okx', 'market_type' => 'perpetual', 'symbol' => 'BTCUSDT', 'timeframe' => '1h',
                'open_at' => ResearchSignalSession::instant($open), 'close_at' => ResearchSignalSession::instant($open + 3600000), 'available_at' => ResearchSignalSession::instant($open + 3600000),
                'open' => '100', 'high' => (string) (110 + $i % 7), 'low' => (string) (90 - $i % 5), 'close' => '101', 'volume' => '6', 'complete' => true];
        }
        $canonical = (new \App\TradingCore\Backtesting\Indicator\CanonicalFourHourAggregator())->aggregate($hourly, ['source_network' => 'mainnet', 'market_data_venue' => 'okx', 'market_type' => 'perpetual'], 'BTCUSDT', ResearchSignalSession::instant($end));
        foreach ($canonical->candles() as $i => $candle) {
            $bar = $windows->bars('4h')[$i];
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) { self::assertSame((float) $candle->$field, (float) $bar[$field]); }
            self::assertSame($candle->openTimestamp()->getTimestamp() * 1000, $bar['open_ms']);
            self::assertNotSame($candle->sourceRecordId, $bar['source_record_id']);
        }
    }
}
