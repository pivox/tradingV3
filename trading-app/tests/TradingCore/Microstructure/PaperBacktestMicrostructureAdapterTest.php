<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Microstructure;

use App\Trading\Paper\Backtesting\NormalizedBacktestCandle;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\Trading\Paper\Backtesting\PaperBacktestDataset;
use App\TradingCore\Microstructure\CanonicalMicrostructureException;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\PaperBacktestMicrostructureAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperBacktestMicrostructureAdapter::class)]
final class PaperBacktestMicrostructureAdapterTest extends TestCase
{
    public function testBuildsSealedSnapshotForTheExactDatasetSymbol(): void
    {
        $snapshot = (new PaperBacktestMicrostructureAdapter())->adapt(
            $this->dataset(),
            new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
            'BTCUSDT',
        );

        self::assertSame('BTCUSDT', $snapshot->symbol);
        self::assertSame('mainnet', $snapshot->sourceNetwork);
        self::assertSame('okx', $snapshot->marketDataVenue);
        self::assertSame('contracts', $snapshot->quantityUnit);
        self::assertSame('0.666666666667', $snapshot->orderFlowImbalance);
        self::assertSame($snapshot, $snapshot->verify());
    }

    public function testMissingExactSymbolNeverFallsBackToAnotherDatasetSymbol(): void
    {
        $this->expectException(CanonicalMicrostructureException::class);
        $this->expectExceptionMessage('canonical_microstructure_book_unavailable');

        (new PaperBacktestMicrostructureAdapter())->adapt(
            $this->dataset(),
            new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
            'ETHUSDT',
        );
    }

    private function dataset(): PaperBacktestDataset
    {
        $checksum = 'sha256:' . str_repeat('f', 64);

        return new PaperBacktestDataset(
            [
                'source' => 'paper_market_dataset',
                'source_schema_version' => 'paper-market-dataset.v2',
                'source_build_version' => 'test-fixture-v1',
                'source_checksum' => $checksum,
                'source_network' => 'mainnet',
                'market_data_venue' => 'okx',
                'market_type' => 'perpetual',
            ],
            [new NormalizedBacktestCandle(
                str_repeat('c', 64), 'mainnet', 'okx', 'BTCUSDT', '1m',
                '2026-08-14T12:00:00.000000Z', '2026-08-14T12:01:00.000000Z',
                '2026-08-14T12:01:00.000000Z', '100', '101', '99', '100', '10',
            )],
            [
                $this->trade('1', '2026-08-14T12:00:10.000000Z', 'buy', '3', $checksum),
                $this->trade('2', '2026-08-14T12:00:30.000000Z', 'sell', '1', $checksum),
                $this->trade('3', '2026-08-14T12:00:55.000000Z', 'buy', '2', $checksum),
            ],
            [new NormalizedBacktestPublicBook(
                str_repeat('a', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT',
                '2026-08-14T12:00:59.000000Z', '2026-08-14T12:00:59.000000Z',
                '99', '10', '101', '12', 'contracts', '2', '3', 'ws_books',
            )],
        );
    }

    private function trade(string $id, string $time, string $side, string $quantity, string $checksum): NormalizedBacktestPublicTrade
    {
        return new NormalizedBacktestPublicTrade(
            str_repeat($id, 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', $id,
            $time, $time, $side, '100', $quantity, 'contracts',
        );
    }
}
