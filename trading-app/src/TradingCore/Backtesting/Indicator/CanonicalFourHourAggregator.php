<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use App\Trading\Paper\MarketData\CanonicalJson;
use Brick\Math\BigDecimal;

final readonly class CanonicalFourHourAggregator
{
    private const SOURCE_RECORD_COUNT = 1000;
    private const FOUR_HOURS_IN_SECONDS = 14_400;

    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed>       $sourceBinding
     */
    public function aggregate(
        array $records,
        array $sourceBinding,
        string $symbol,
        string $evaluatedAt,
    ): CanonicalIndicatorWindow {
        if (!array_is_list($records) || \count($records) !== self::SOURCE_RECORD_COUNT) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_four_hour_count_invalid');
        }
        CanonicalIndicatorWindow::validateSourceBinding($sourceBinding);
        if (!\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_symbol_invalid');
        }

        $evaluationTime = CanonicalIndicatorCandle::parseTimestamp($evaluatedAt);
        $candles = [];
        $sourceRecordIds = [];
        $previous = null;
        foreach ($records as $record) {
            $candle = CanonicalIndicatorCandle::fromArray($record);
            if ($candle->sourceNetwork !== $sourceBinding['source_network']
                || $candle->marketDataVenue !== $sourceBinding['market_data_venue']
                || $candle->marketType !== $sourceBinding['market_type']
            ) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_source_binding_mismatch');
            }
            if ($candle->symbol !== $symbol) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_symbol_mismatch');
            }
            if ($candle->timeframe !== '1h') {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_timeframe_mismatch');
            }
            if (isset($sourceRecordIds[$candle->sourceRecordId])) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_source_record_duplicate');
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
            $sourceRecordIds[$candle->sourceRecordId] = true;
            $previous = $candle;
        }

        if (((int) $candles[0]->openTimestamp()->format('U')) % self::FOUR_HOURS_IN_SECONDS !== 0) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_four_hour_alignment_invalid');
        }

        $derivedRecords = [];
        foreach (array_chunk($candles, 4) as $components) {
            $derivedRecords[] = $this->aggregateComponents($components);
        }

        return CanonicalIndicatorWindow::fromDerivedRecords(
            $derivedRecords,
            $sourceBinding,
            $symbol,
            $evaluatedAt,
        );
    }

    /**
     * @param list<CanonicalIndicatorCandle> $components
     *
     * @return array<string, mixed>
     */
    private function aggregateComponents(array $components): array
    {
        $high = BigDecimal::of($components[0]->high);
        $low = BigDecimal::of($components[0]->low);
        $volume = BigDecimal::zero();
        $availableAt = $components[0]->availableTimestamp();
        $componentIds = [];

        foreach ($components as $component) {
            $componentHigh = BigDecimal::of($component->high);
            $componentLow = BigDecimal::of($component->low);
            if ($componentHigh->isGreaterThan($high)) {
                $high = $componentHigh;
            }
            if ($componentLow->isLessThan($low)) {
                $low = $componentLow;
            }
            $volume = $volume->plus($component->volume);
            if ($component->availableTimestamp() > $availableAt) {
                $availableAt = $component->availableTimestamp();
            }
            $componentIds[] = $component->sourceRecordId;
        }

        $last = $components[3];
        $record = [
            'schema_version' => CanonicalIndicatorCandle::DERIVED_SCHEMA_VERSION,
            'component_source_record_ids' => $componentIds,
            'source_network' => $components[0]->sourceNetwork,
            'market_data_venue' => $components[0]->marketDataVenue,
            'market_type' => $components[0]->marketType,
            'symbol' => $components[0]->symbol,
            'timeframe' => '4h',
            'open_at' => $components[0]->openAt,
            'close_at' => $last->closeAt,
            'available_at' => $availableAt->format('Y-m-d\TH:i:s.u\Z'),
            'open' => $components[0]->open,
            'high' => (string) $high->stripTrailingZeros(),
            'low' => (string) $low->stripTrailingZeros(),
            'close' => $last->close,
            'volume' => $volume->isZero() ? '0' : (string) $volume->stripTrailingZeros(),
            'complete' => true,
            'origin' => 'aggregate_1h_utc',
        ];

        return ['derived_record_id' => hash('sha256', CanonicalJson::encode($record))] + $record;
    }
}
