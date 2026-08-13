<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

final readonly class PaperBacktestDataset
{
    /** @var array<string, string> */
    public array $sourceIdentity;

    /** @var list<NormalizedBacktestCandle> */
    public array $candles;

    /**
     * @param array<string, string> $sourceIdentity
     * @param array<array-key, mixed> $candles
     */
    public function __construct(array $sourceIdentity, array $candles)
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
        }

        $this->sourceIdentity = [...$sourceIdentity];
        $this->candles = array_values($candles);
    }
}
