<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use App\Trading\Paper\MarketData\CanonicalJson;

final readonly class CanonicalIndicatorWindow
{
    private const RECORD_COUNT = 250;
    private const SOURCE_BINDING_KEYS = ['source_network', 'market_data_venue', 'market_type'];

    /** @var list<CanonicalIndicatorCandle> */
    private array $canonicalCandles;
    private string $hash;

    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed>       $sourceBinding
     */
    public function __construct(
        array $records,
        array $sourceBinding,
        private string $windowSymbol,
        private string $windowTimeframe,
        string $evaluatedAt,
    ) {
        if (!\in_array($windowTimeframe, ['1m', '5m', '15m', '1h'], true)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_timeframe_invalid');
        }
        if (!\in_array($windowSymbol, ['BTCUSDT', 'ETHUSDT'], true)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_symbol_invalid');
        }
        if (\count($records) !== self::RECORD_COUNT || !array_is_list($records)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_window_count_invalid');
        }
        self::validateSourceBinding($sourceBinding);
        $evaluationTime = CanonicalIndicatorCandle::parseTimestamp($evaluatedAt);

        $candles = [];
        $canonicalRecords = [];
        $previous = null;
        foreach ($records as $record) {
            $candle = CanonicalIndicatorCandle::fromArray($record);
            if ($candle->sourceNetwork !== $sourceBinding['source_network']
                || $candle->marketDataVenue !== $sourceBinding['market_data_venue']
                || $candle->marketType !== $sourceBinding['market_type']
            ) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_source_binding_mismatch');
            }
            if ($candle->symbol !== $windowSymbol) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_symbol_mismatch');
            }
            if ($candle->timeframe !== $windowTimeframe) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_timeframe_mismatch');
            }
            if ($previous !== null && $candle->openTimestamp() != $previous->closeTimestamp()) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_window_chronology_invalid');
            }
            if ($candle->closeTimestamp() > $evaluationTime) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_future_close');
            }
            if ($candle->availableTimestamp() > $evaluationTime) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_future_availability');
            }
            $candles[] = $candle;
            $canonicalRecords[] = $candle->toArray();
            $previous = $candle;
        }

        $this->canonicalCandles = $candles;
        $this->hash = 'sha256:' . hash('sha256', CanonicalJson::encode($canonicalRecords));
    }

    /** @return list<CanonicalIndicatorCandle> */
    public function candles(): array
    {
        return $this->canonicalCandles;
    }

    public function timeframe(): string
    {
        return $this->windowTimeframe;
    }

    public function symbol(): string
    {
        return $this->windowSymbol;
    }

    public function windowHash(): string
    {
        return $this->hash;
    }

    /** @param array<string, mixed> $sourceBinding */
    private static function validateSourceBinding(array $sourceBinding): void
    {
        $keys = array_keys($sourceBinding);
        sort($keys, SORT_STRING);
        $expectedKeys = self::SOURCE_BINDING_KEYS;
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || !\is_string($sourceBinding['source_network'])
            || !\is_string($sourceBinding['market_data_venue'])
            || !\is_string($sourceBinding['market_type'])
            || !\in_array($sourceBinding['source_network'], ['mainnet', 'testnet'], true)
            || !\in_array($sourceBinding['market_data_venue'], ['okx', 'hyperliquid'], true)
            || $sourceBinding['market_type'] !== 'perpetual'
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_source_binding_invalid');
        }
    }
}
