<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalIndicatorDatasetBindingBuilder;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalIndicatorDatasetBindingBuilder::class)]
final class PaperCanonicalIndicatorDatasetBindingBuilderTest extends TestCase
{
    public function testMatchesCanonicalPythonDatasetSerializerGoldenVector(): void
    {
        $binding = (new PaperCanonicalIndicatorDatasetBindingBuilder())->build(
            $this->cell(),
            'BTCUSDT',
            'paper-dataset-recorder.v2',
            str_repeat('a', 64),
            ['1m' => $this->candles(250)],
        );

        self::assertSame([
            'dataset_id' => 'backtest-dataset-0722f8e7dc3571d860c2a1db3c2ae05ac89f2b5e24ac2c1b6ce078b06e2e0b31',
            'dataset_checksum' => 'sha256:0722f8e7dc3571d860c2a1db3c2ae05ac89f2b5e24ac2c1b6ce078b06e2e0b31',
            'candles_checksum' => 'sha256:238e9b8a36c994f274d787c3914bc3642d324288918f260fdd5b68fcdb0a1cb7',
            'quality_report_checksum' => 'sha256:cf2a09561a3fec56d5055eee959f86dd1aa3e85f86ecbee59982f7ba46acb4a4',
            'source_checksum' => 'sha256:' . str_repeat('a', 64),
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
        ], $binding);
    }

    public function testCanonicalOrderingDoesNotDependOnInputOrder(): void
    {
        $builder = new PaperCanonicalIndicatorDatasetBindingBuilder();
        $candles = $this->candles(250);

        self::assertSame(
            $builder->build($this->cell(), 'BTCUSDT', 'recorder.v2', str_repeat('b', 64), ['1m' => $candles]),
            $builder->build($this->cell(), 'BTCUSDT', 'recorder.v2', str_repeat('b', 64), ['1m' => array_reverse($candles)]),
        );
    }

    public function testMatchesPythonVectorAcrossMultipleStreams(): void
    {
        $binding = (new PaperCanonicalIndicatorDatasetBindingBuilder())->build(
            $this->cell(),
            'BTCUSDT',
            'paper-dataset-recorder.v2',
            str_repeat('a', 64),
            [
                '5m' => $this->candles(250, '5m', 300, '5m-'),
                '1m' => $this->candles(250, '1m', 60, '1m-'),
            ],
        );

        self::assertSame('sha256:2266b344fdc3eb17e33843fd9f39d9ebe2b71b5e9f9fad3e5e76e86219e2c304', $binding['dataset_checksum']);
        self::assertSame('sha256:b7c86c98cab97a210ef5d75c3b44fc3025de73e0c7d38506b4855cec07e0de11', $binding['candles_checksum']);
        self::assertSame('sha256:7b8485b9aab33cc266340f1d6d50ec090b897227f313219be8a852e6cf71ebc1', $binding['quality_report_checksum']);
    }

    public function testRejectsDuplicateSourceRecord(): void
    {
        $candles = $this->candles(250);
        $candles[249] = $candles[248];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_indicator_dataset_invalid');

        (new PaperCanonicalIndicatorDatasetBindingBuilder())->build(
            $this->cell(), 'BTCUSDT', 'recorder.v2', str_repeat('c', 64), ['1m' => $candles],
        );
    }

    public function testRejectsDiscontinuousStream(): void
    {
        $candles = $this->candles(251);
        unset($candles[125]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_indicator_dataset_invalid');

        (new PaperCanonicalIndicatorDatasetBindingBuilder())->build(
            $this->cell(), 'BTCUSDT', 'recorder.v2', str_repeat('d', 64), ['1m' => array_values($candles)],
        );
    }

    public function testRejectsSourceScopeDrift(): void
    {
        $candles = $this->candles(250);
        $candles[10]['market_data_venue'] = 'hyperliquid';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_indicator_dataset_invalid');

        (new PaperCanonicalIndicatorDatasetBindingBuilder())->build(
            $this->cell(), 'BTCUSDT', 'recorder.v2', str_repeat('e', 64), ['1m' => $candles],
        );
    }

    /** @return list<array<string, bool|string>> */
    private function candles(
        int $count,
        string $timeframe = '1m',
        int $durationSeconds = 60,
        string $recordPrefix = '',
    ): array
    {
        $candles = [];
        $start = new \DateTimeImmutable('2026-08-01T00:00:00.000000Z');
        for ($index = 0; $index < $count; ++$index) {
            $open = $start->modify('+' . ($index * $durationSeconds) . ' seconds');
            $close = $open->modify('+' . $durationSeconds . ' seconds');
            $candles[] = [
                'schema_version' => 'backtest-candle.v1',
                'source_record_id' => hash('sha256', $recordPrefix . 'record-' . $index),
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
                'symbol' => 'BTCUSDT',
                'timeframe' => $timeframe,
                'open_at' => $open->format('Y-m-d\TH:i:s.u\Z'),
                'close_at' => $close->format('Y-m-d\TH:i:s.u\Z'),
                'available_at' => $close->format('Y-m-d\TH:i:s.u\Z'),
                'open' => '30000',
                'high' => '30100',
                'low' => '29900',
                'close' => '30050',
                'volume' => '12.5',
                'complete' => true,
            ];
        }

        return $candles;
    }

    private function cell(): PaperExecutionCell
    {
        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('1', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('2', 64),
                'sha256:' . str_repeat('3', 64),
            ),
            'paper-binding-run',
        );
    }
}
