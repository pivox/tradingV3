<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

use Brick\Math\BigDecimal;

final readonly class NormalizedBacktestInstrumentMetadata
{
    public const SCHEMA_VERSION = 'backtest-instrument-metadata.v1';
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function __construct(
        public string $sourceRecordId,
        public string $sourceChecksum,
        public string $sourceNetwork,
        public string $marketDataVenue,
        public string $symbol,
        public int $sourceEventPosition,
        public string $availableAt,
        public int $sourceEpoch,
        public string $quantityUnit,
        public string $contractValue,
        public string $contractMultiplier,
        public string $contractValueUnit,
    ) {
        $baseAsset = match ($symbol) {
            'BTCUSDT' => 'BTC',
            'ETHUSDT' => 'ETH',
            default => null,
        };
        if (preg_match('/\A[0-9a-f]{64}\z/D', $sourceRecordId) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $sourceChecksum) !== 1
            || !\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || $baseAsset === null
            || $sourceEventPosition < 0
            || $sourceEpoch < 1
            || $contractValueUnit !== $baseAsset
            || !self::decimal($contractValue)->isPositive()
            || !self::decimal($contractMultiplier)->isPositive()
            || ($marketDataVenue === 'okx' && $quantityUnit !== 'contracts')
            || ($marketDataVenue === 'hyperliquid' && ($quantityUnit !== 'base_asset'
                || $contractValue !== '1' || $contractMultiplier !== '1'))
        ) {
            throw new \InvalidArgumentException('paper_backtest_instrument_metadata_invalid');
        }
        self::timestamp($availableAt);
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_record_id' => $this->sourceRecordId,
            'source_checksum' => $this->sourceChecksum,
            'source_network' => $this->sourceNetwork,
            'market_data_venue' => $this->marketDataVenue,
            'market_type' => 'perpetual',
            'symbol' => $this->symbol,
            'source_event_position' => $this->sourceEventPosition,
            'available_at' => $this->availableAt,
            'source_epoch' => $this->sourceEpoch,
            'quantity_unit' => $this->quantityUnit,
            'contract_value' => $this->contractValue,
            'contract_multiplier' => $this->contractMultiplier,
            'contract_value_unit' => $this->contractValueUnit,
        ];
    }

    public function convert(string $quantity): string
    {
        $value = self::decimal($quantity);
        if (!$value->isPositive()) {
            throw new \InvalidArgumentException('paper_backtest_quantity_conversion_invalid');
        }
        return self::canonical($value
            ->multipliedBy(self::decimal($this->contractValue))
            ->multipliedBy(self::decimal($this->contractMultiplier)));
    }

    private static function decimal(string $value): BigDecimal
    {
        if (\strlen($value) > 256
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('paper_backtest_instrument_metadata_invalid');
        }
        return BigDecimal::of($value);
    }

    private static function canonical(BigDecimal $value): string
    {
        $value = $value->stripTrailingZeros();
        return $value->getScale() < 0 ? $value->toScale(0)->__toString() : (string) $value;
    }

    private static function timestamp(string $value): void
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!' . self::TIMESTAMP_FORMAT,
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \InvalidArgumentException('paper_backtest_instrument_metadata_invalid');
        }
    }
}
