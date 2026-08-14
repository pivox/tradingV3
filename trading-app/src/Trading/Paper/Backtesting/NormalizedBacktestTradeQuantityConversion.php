<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

final readonly class NormalizedBacktestTradeQuantityConversion
{
    public const SCHEMA_VERSION = 'backtest-trade-quantity-conversion.v1';

    public string $metadataRecordId;
    public int $metadataEventPosition;
    public string $metadataAvailableAt;
    public string $baseQuantity;

    public function __construct(
        public string $sourceRecordId,
        public int $sourceEventPosition,
        public string $sourceChecksum,
        public string $sourceNetwork,
        public string $marketDataVenue,
        public string $symbol,
        public string $happenedAt,
        public string $availableAt,
        NormalizedBacktestInstrumentMetadata $metadata,
        public string $sourceQuantity,
        public string $sourceQuantityUnit,
    ) {
        if ($metadata->sourceChecksum !== $sourceChecksum
            || $metadata->sourceNetwork !== $sourceNetwork
            || $metadata->marketDataVenue !== $marketDataVenue
            || $metadata->symbol !== $symbol
            || $metadata->quantityUnit !== $sourceQuantityUnit
            || $metadata->sourceEventPosition >= $sourceEventPosition
            || $metadata->availableAt > $availableAt
        ) {
            throw new \InvalidArgumentException('paper_backtest_trade_quantity_conversion_invalid');
        }
        $this->metadataRecordId = $metadata->sourceRecordId;
        $this->metadataEventPosition = $metadata->sourceEventPosition;
        $this->metadataAvailableAt = $metadata->availableAt;
        $this->baseQuantity = $metadata->convert($sourceQuantity);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_channel' => 'public_trade',
            'source_record_id' => $this->sourceRecordId,
            'source_event_position' => $this->sourceEventPosition,
            'source_checksum' => $this->sourceChecksum,
            'source_network' => $this->sourceNetwork,
            'market_data_venue' => $this->marketDataVenue,
            'market_type' => 'perpetual',
            'symbol' => $this->symbol,
            'happened_at' => $this->happenedAt,
            'available_at' => $this->availableAt,
            'metadata_record_id' => $this->metadataRecordId,
            'metadata_event_position' => $this->metadataEventPosition,
            'metadata_available_at' => $this->metadataAvailableAt,
            'source_quantity' => $this->sourceQuantity,
            'source_quantity_unit' => $this->sourceQuantityUnit,
            'base_quantity' => $this->baseQuantity,
            'base_quantity_unit' => 'base_asset',
        ];
    }
}
