<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPrudentBookModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPrudentBookModel::class)]
#[CoversClass(HyperliquidCandle::class)]
final class HyperliquidPrudentBookModelTest extends TestCase
{
    public function testExposesPinnedModelIdentityAndPrivatePolicyConstants(): void
    {
        self::assertSame('hl_candle_atr_top_v1', HyperliquidPrudentBookModel::NAME);
        self::assertSame('1.0.0', HyperliquidPrudentBookModel::VERSION);

        $reflection = new \ReflectionClass(HyperliquidPrudentBookModel::class);
        foreach ([
            'ATR_PERIOD' => 14,
            'MIN_SPREAD_BPS' => '2',
            'MAX_SPREAD_BPS' => '50',
            'VOLATILITY_MULTIPLIER' => '0.15',
        ] as $name => $expected) {
            $constant = $reflection->getReflectionConstant($name);
            self::assertNotFalse($constant);
            self::assertTrue($constant->isPrivate());
            self::assertSame($expected, $constant->getValue());
        }
    }

    public function testProducesOneDeterministicPrudentLevelWithExactFields(): void
    {
        $result = (new HyperliquidPrudentBookModel())->push($this->candle(
            start: 0,
            open: '100',
            high: '101',
            low: '99',
            close: '100',
            volume: '5',
            trades: 10,
        ));

        self::assertSame([
            'bid' => '99.85',
            'ask' => '100.15',
            'size' => '0.5',
            'spread_bps' => '30',
            'atr' => '2',
            'model_name' => 'hl_candle_atr_top_v1',
            'model_version' => '1.0.0',
        ], $result);
        self::assertNotNull($result);
        self::assertLessThan(100.0, (float) $result['bid']);
        self::assertGreaterThan(100.0, (float) $result['ask']);
        self::assertSame(
            ['bid', 'ask', 'size', 'spread_bps', 'atr', 'model_name', 'model_version'],
            array_keys($result),
        );
    }

    public function testUsesAvailableAtrAndEvictsTheOldestTrueRangeAfterFourteenCandles(): void
    {
        $model = new HyperliquidPrudentBookModel();
        $results = [];

        for ($range = 1; $range <= 15; ++$range) {
            $results[] = $model->push($this->candle(
                start: ($range - 1) * 60_000,
                open: '100',
                high: (string) (100 + $range),
                low: '100',
                close: '100',
                volume: '14',
                trades: 14,
            ));
        }

        self::assertSame('1', $results[0]['atr'] ?? null);
        self::assertSame('7.5', $results[13]['atr'] ?? null);
        self::assertSame('8.5', $results[14]['atr'] ?? null);
    }

    public function testTrueRangeUsesPreviousCloseWhenItExceedsTheIntracandleRange(): void
    {
        $model = new HyperliquidPrudentBookModel();
        self::assertNotNull($model->push($this->candle(
            start: 0,
            open: '90',
            high: '95',
            low: '88',
            close: '90',
        )));

        $result = $model->push($this->candle(
            start: 60_000,
            open: '100',
            high: '110',
            low: '95',
            close: '105',
        ));

        self::assertSame('13.5', $result['atr'] ?? null);
    }

    public function testClampsSpreadAtTwoBasisPoints(): void
    {
        $result = (new HyperliquidPrudentBookModel())->push($this->candle(
            start: 0,
            open: '1000',
            high: '1000.001',
            low: '1000',
            close: '1000',
        ));

        self::assertNotNull($result);
        self::assertSame('2', $result['spread_bps']);
        self::assertSame('999.9', $result['bid']);
        self::assertSame('1000.1', $result['ask']);
    }

    public function testClampsSpreadAtFiftyBasisPoints(): void
    {
        $result = (new HyperliquidPrudentBookModel())->push($this->candle(
            start: 0,
            open: '100',
            high: '150',
            low: '50',
            close: '100',
        ));

        self::assertNotNull($result);
        self::assertSame('50', $result['spread_bps']);
        self::assertSame('99.75', $result['bid']);
        self::assertSame('100.25', $result['ask']);
    }

    public function testRoundsRepeatingVolatilityToScaleEighteenBeforeRenderingSpread(): void
    {
        $result = (new HyperliquidPrudentBookModel())->push($this->candle(
            start: 0,
            open: '3',
            high: '3.01',
            low: '3',
            close: '3',
        ));

        self::assertNotNull($result);
        self::assertSame('5', $result['spread_bps']);
        self::assertSame('2.99925', $result['bid']);
        self::assertSame('3.00075', $result['ask']);
    }

    public function testRoundsHighPrecisionBidAndAskToAtMostEighteenFractionalDigits(): void
    {
        $result = (new HyperliquidPrudentBookModel())->push($this->candle(
            start: 0,
            open: '100',
            high: '150',
            low: '50',
            close: '100.1234567890123456789',
        ));

        self::assertNotNull($result);
        self::assertSame('50', $result['spread_bps']);
        self::assertSame('99.873148147039814815', $result['bid']);
        self::assertSame('100.373765430984876543', $result['ask']);
        self::assertMatchesRegularExpression('/\A[0-9]+(?:\.[0-9]{1,18})?\z/D', $result['bid']);
        self::assertMatchesRegularExpression('/\A[0-9]+(?:\.[0-9]{1,18})?\z/D', $result['ask']);
    }

    public function testUsesHalfEvenForExactBidAndAskTiesAtScaleEighteen(): void
    {
        $result = (new HyperliquidPrudentBookModel())->push($this->candle(
            start: 0,
            open: '1.000000000000005',
            high: '1.000000000000006',
            low: '1.000000000000004',
            close: '1.000000000000005',
        ));

        self::assertNotNull($result);
        self::assertSame('2', $result['spread_bps']);
        // Bid's odd retained digit rounds up (unlike DOWN); ask's even digit stays (unlike HALF_UP).
        self::assertSame('0.999900000000005', $result['bid']);
        self::assertSame('1.000100000000005', $result['ask']);
        self::assertMatchesRegularExpression('/\A[0-9]+(?:\.[0-9]{1,18})?\z/D', $result['bid']);
        self::assertMatchesRegularExpression('/\A[0-9]+(?:\.[0-9]{1,18})?\z/D', $result['ask']);
    }

    public function testZeroVolumeReturnsNoBookButStillAdvancesAtrAndPreviousClose(): void
    {
        $model = new HyperliquidPrudentBookModel();

        self::assertNull($model->push($this->candle(
            start: 0,
            open: '100',
            high: '101',
            low: '99',
            close: '100',
            volume: '0',
            trades: 10,
        )));

        $result = $model->push($this->candle(
            start: 60_000,
            open: '110',
            high: '111',
            low: '109',
            close: '110',
            volume: '2',
            trades: 2,
        ));

        self::assertSame('6.5', $result['atr'] ?? null);
    }

    public function testZeroTradesReturnsNoBookButStillAdvancesAtrAndPreviousClose(): void
    {
        $model = new HyperliquidPrudentBookModel();

        self::assertNull($model->push($this->candle(
            start: 0,
            open: '100',
            high: '102',
            low: '99',
            close: '101',
            volume: '2',
            trades: 0,
        )));

        $result = $model->push($this->candle(
            start: 60_000,
            open: '105',
            high: '106',
            low: '104',
            close: '105',
            volume: '2',
            trades: 2,
        ));

        self::assertSame('4', $result['atr'] ?? null);
    }

    public function testRejectsWrongStreamWithoutCorruptingTheExpectedNextCandle(): void
    {
        $model = new HyperliquidPrudentBookModel();
        self::assertNotNull($model->push($this->candle(start: 0)));

        try {
            $model->push($this->candle(start: 60_000, coin: 'ETH'));
            self::fail('Expected wrong stream rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_prudent_book_stream_mismatch', $exception->getMessage());
        }

        self::assertNotNull($model->push($this->candle(start: 60_000)));
    }

    public function testRejectsDuplicateDecreasingAndGapStartsWithoutStateCorruption(): void
    {
        $model = new HyperliquidPrudentBookModel();
        self::assertNotNull($model->push($this->candle(start: 60_000)));

        foreach ([60_000, 0, 180_000] as $rejectedStart) {
            try {
                $model->push($this->candle(start: $rejectedStart));
                self::fail('Expected sequence rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('hyperliquid_prudent_book_sequence_invalid', $exception->getMessage());
            }
        }

        $result = $model->push($this->candle(start: 120_000));
        self::assertSame('2', $result['atr'] ?? null);
    }

    public function testReconstructedModelsProduceByteIdenticalOutput(): void
    {
        $first = new HyperliquidPrudentBookModel();
        $second = new HyperliquidPrudentBookModel();
        $firstOutput = [];
        $secondOutput = [];

        for ($index = 0; $index < 18; ++$index) {
            $candle = $this->candle(
                start: $index * 60_000,
                open: '100.123456789012345678',
                high: (string) (101 + ($index % 5)),
                low: '99.125',
                close: '100.123456789012345678',
                volume: '3.141592653589793238',
                trades: 7,
            );
            $firstOutput[] = $first->push($candle);
            $secondOutput[] = $second->push($candle);
        }

        $firstBytes = json_encode($firstOutput, \JSON_THROW_ON_ERROR);
        $secondBytes = json_encode($secondOutput, \JSON_THROW_ON_ERROR);

        self::assertSame($firstBytes, $secondBytes);
    }

    private function candle(
        int $start,
        string $open = '100',
        string $high = '101',
        string $low = '99',
        string $close = '100',
        string $volume = '5',
        int $trades = 10,
        string $coin = 'BTC',
        string $interval = '1m',
    ): HyperliquidCandle {
        $intervalMs = match ($interval) {
            '1m' => 60_000,
            '5m' => 300_000,
            '15m' => 900_000,
            '1h' => 3_600_000,
            default => throw new \InvalidArgumentException('test_interval_invalid'),
        };

        return HyperliquidCandle::fromApiRow([
            'T' => $start + $intervalMs - 1,
            'c' => $close,
            'h' => $high,
            'i' => $interval,
            'l' => $low,
            'n' => $trades,
            'o' => $open,
            's' => $coin,
            't' => $start,
            'v' => $volume,
        ], $coin, $interval);
    }
}
