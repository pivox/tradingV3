<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

final readonly class PaperBacktestDataset
{
    /** @var array<string, string> */
    public array $sourceIdentity;

    /** @var list<NormalizedBacktestCandle> */
    public array $candles;

    /** @var list<NormalizedBacktestPublicTrade> */
    public array $publicTrades;

    /** @var list<NormalizedBacktestPublicBook> */
    public array $publicBooks;

    /**
     * @param array<string, string> $sourceIdentity
     * @param array<array-key, mixed> $candles
     * @param array<array-key, mixed> $publicTrades
     * @param array<array-key, mixed> $publicBooks
     */
    public function __construct(
        array $sourceIdentity,
        array $candles,
        array $publicTrades,
        array $publicBooks,
    )
    {
        $expectedKeys = [
            'source', 'source_schema_version', 'source_build_version', 'source_checksum',
            'source_network', 'market_data_venue', 'market_type',
        ];
        $actualKeys = array_keys($sourceIdentity);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys
            || ($sourceIdentity['source'] ?? null) !== 'paper_market_dataset'
            || ($sourceIdentity['source_schema_version'] ?? null) !== 'paper-market-dataset.v2'
            || !\is_string($sourceIdentity['source_build_version'] ?? null)
            || trim($sourceIdentity['source_build_version']) !== $sourceIdentity['source_build_version']
            || $sourceIdentity['source_build_version'] === ''
            || !\is_string($sourceIdentity['source_checksum'] ?? null)
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $sourceIdentity['source_checksum']) !== 1
            || !\in_array($sourceIdentity['source_network'] ?? null, ['mainnet', 'testnet'], true)
            || !\in_array($sourceIdentity['market_data_venue'] ?? null, ['okx', 'hyperliquid'], true)
            || ($sourceIdentity['market_type'] ?? null) !== 'perpetual'
        ) {
            throw new \InvalidArgumentException('paper_backtest_source_identity_invalid');
        }
        foreach ($candles as $candle) {
            if (!$candle instanceof NormalizedBacktestCandle) {
                throw new \InvalidArgumentException('paper_backtest_candles_invalid');
            }
            if ($candle->sourceNetwork !== $sourceIdentity['source_network']
                || $candle->marketDataVenue !== $sourceIdentity['market_data_venue']
            ) {
                throw new \InvalidArgumentException('paper_backtest_candle_source_mismatch');
            }
        }
        $tradeIds = [];
        $sourceIds = [];
        foreach ($publicTrades as $trade) {
            if (!$trade instanceof NormalizedBacktestPublicTrade
                || $trade->sourceNetwork !== $sourceIdentity['source_network']
                || $trade->marketDataVenue !== $sourceIdentity['market_data_venue']
                || $trade->sourceChecksum !== $sourceIdentity['source_checksum']
                || isset($sourceIds[$trade->sourceRecordId])
                || isset($tradeIds[$trade->symbol . '|' . $trade->venueTradeId])
            ) {
                throw new \InvalidArgumentException('paper_backtest_public_trades_invalid');
            }
            $sourceIds[$trade->sourceRecordId] = true;
            $tradeIds[$trade->symbol . '|' . $trade->venueTradeId] = true;
        }
        $bookSourceIds = [];
        foreach ($publicBooks as $book) {
            if (!$book instanceof NormalizedBacktestPublicBook
                || $book->sourceNetwork !== $sourceIdentity['source_network']
                || $book->marketDataVenue !== $sourceIdentity['market_data_venue']
                || $book->sourceChecksum !== $sourceIdentity['source_checksum']
                || isset($bookSourceIds[$book->sourceRecordId])
            ) {
                throw new \InvalidArgumentException('paper_backtest_public_books_invalid');
            }
            $bookSourceIds[$book->sourceRecordId] = true;
        }
        if ($candles === []) {
            throw new \InvalidArgumentException('paper_backtest_candles_empty');
        }

        $this->sourceIdentity = [...$sourceIdentity];
        $this->candles = array_values($candles);
        $this->publicTrades = array_values($publicTrades);
        $this->publicBooks = array_values($publicBooks);
    }
}
