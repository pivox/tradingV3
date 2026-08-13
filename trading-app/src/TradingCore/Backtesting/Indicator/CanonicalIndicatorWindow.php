<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use App\Trading\Paper\MarketData\CanonicalJson;

final readonly class CanonicalIndicatorWindow
{
    private const RECORD_COUNT = 250;
    private const SOURCE_BINDING_KEYS = ['source_network', 'market_data_venue', 'market_type'];
    private const DERIVED_CONSTRUCTION = 'canonical-derived-indicator-window.v1';

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
        ?string $internalConstruction = null,
    ) {
        if ($internalConstruction !== null && $internalConstruction !== self::DERIVED_CONSTRUCTION) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_window_construction_invalid');
        }
        $derived = $internalConstruction === self::DERIVED_CONSTRUCTION;
        if (!\in_array($windowTimeframe, $derived ? ['4h'] : ['1m', '5m', '15m', '1h'], true)) {
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
        $recordIds = [];
        $previous = null;
        foreach ($records as $record) {
            $candle = $derived
                ? CanonicalIndicatorCandle::fromDerivedArray($record)
                : CanonicalIndicatorCandle::fromArray($record);
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
            if (isset($recordIds[$candle->sourceRecordId])) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_source_record_duplicate');
            }
            if ($candle->closeTimestamp() > $evaluationTime) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_future_close');
            }
            if ($candle->availableTimestamp() > $evaluationTime) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_future_availability');
            }
            $candles[] = $candle;
            $canonicalRecords[] = $candle->toArray();
            $recordIds[$candle->sourceRecordId] = true;
            $previous = $candle;
        }

        $this->canonicalCandles = $candles;
        $this->hash = 'sha256:' . hash('sha256', CanonicalJson::encode($canonicalRecords));
    }

    /**
     * Internal construction path for records derived from validated native candles.
     *
     * @internal Only CanonicalFourHourAggregator may call this.
     *
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed>       $sourceBinding
     */
    public static function fromDerivedRecords(
        array $records,
        array $sourceBinding,
        string $symbol,
        string $evaluatedAt,
    ): self {
        return new self($records, $sourceBinding, $symbol, '4h', $evaluatedAt, self::DERIVED_CONSTRUCTION);
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

    /**
     * @internal Shared strict validation for native and derived construction.
     *
     * @param array<string, mixed> $sourceBinding
     */
    public static function validateSourceBinding(array $sourceBinding): void
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
