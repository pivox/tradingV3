<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidOrderNotionalLimits;
use Brick\Math\BigDecimal;

final readonly class NormalizedBacktestInstrumentMetadata
{
    public const SCHEMA_VERSION = 'backtest-instrument-metadata.v3';
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
        public string $metadataSchemaVersion,
        public string $happenedAt,
        public string $baseAsset,
        public string $quoteAsset,
        public string $settlementAsset,
        public string $priceTick,
        public string $quantityStep,
        public string $minQuantity,
        public ?string $maxMarketQuantity,
        public ?string $maxLimitQuantity,
        public ?string $maxLeverage,
        public ?string $maxMarketNotional,
        public ?string $maxLimitNotional,
        public ?string $orderNotionalLimitModel,
    ) {
        $expectedBaseAsset = match ($symbol) {
            'BTCUSDT' => 'BTC',
            'ETHUSDT' => 'ETH',
            default => null,
        };
        if (preg_match('/\A[0-9a-f]{64}\z/D', $sourceRecordId) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $sourceChecksum) !== 1
            || !\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || $expectedBaseAsset === null
            || $sourceEventPosition < 0
            || $sourceEpoch < 1
            || !\in_array($metadataSchemaVersion, [
                'paper-instrument-metadata.v1',
                'paper-instrument-metadata.v2',
            ], true)
            || $baseAsset !== $expectedBaseAsset
            || $contractValueUnit !== $expectedBaseAsset
            || !self::decimal($contractValue)->isPositive()
            || !self::decimal($contractMultiplier)->isPositive()
            || !self::decimal($priceTick)->isPositive()
            || !self::decimal($quantityStep)->isPositive()
            || !self::decimal($minQuantity)->isPositive()
            || self::decimal($minQuantity)->isLessThan(self::decimal($quantityStep))
            || ($marketDataVenue === 'okx' && $quantityUnit !== 'contracts')
            || ($marketDataVenue === 'hyperliquid' && ($quantityUnit !== 'base_asset'
                || $contractValue !== '1' || $contractMultiplier !== '1'))
        ) {
            throw new \InvalidArgumentException('paper_backtest_instrument_metadata_invalid');
        }
        if (($marketDataVenue === 'okx' && ($quoteAsset !== 'USDT' || $settlementAsset !== 'USDT'))
            || ($marketDataVenue === 'hyperliquid'
                && (($metadataSchemaVersion === 'paper-instrument-metadata.v1'
                        && ($quoteAsset !== 'USDC' || $settlementAsset !== 'USDC'))
                    || ($metadataSchemaVersion === 'paper-instrument-metadata.v2'
                        && ($quoteAsset !== 'USDT' || $settlementAsset !== 'USDC'))))
            || ($maxMarketQuantity !== null && !self::decimal($maxMarketQuantity)->isPositive())
            || ($maxLimitQuantity !== null && !self::decimal($maxLimitQuantity)->isPositive())
            || ($maxLeverage !== null && !self::decimal($maxLeverage)->isPositive())
            || ($maxMarketNotional !== null && !self::decimal($maxMarketNotional)->isPositive())
            || ($maxLimitNotional !== null && !self::decimal($maxLimitNotional)->isPositive())
            || ($maxMarketQuantity !== null
                && self::decimal($maxMarketQuantity)->isLessThan(self::decimal($minQuantity)))
            || ($maxLimitQuantity !== null
                && self::decimal($maxLimitQuantity)->isLessThan(self::decimal($minQuantity)))
            || ($marketDataVenue === 'okx' && ($maxMarketQuantity === null || $maxLimitQuantity === null))
            || ($marketDataVenue === 'okx'
                && $metadataSchemaVersion === 'paper-instrument-metadata.v2'
                && $maxLeverage === null)
            || ($marketDataVenue === 'okx'
                && $metadataSchemaVersion === 'paper-instrument-metadata.v1'
                && $maxLeverage !== null)
            || ($marketDataVenue === 'okx'
                && ($maxMarketNotional !== null
                    || $maxLimitNotional !== null
                    || $orderNotionalLimitModel !== null))
            || ($marketDataVenue === 'hyperliquid'
                && ($maxMarketQuantity !== null
                    || $maxLimitQuantity !== null
                    || $maxLeverage === null
                    || ($metadataSchemaVersion === 'paper-instrument-metadata.v1'
                        && ($maxMarketNotional !== null
                            || $maxLimitNotional !== null
                            || $orderNotionalLimitModel !== null))
                    || ($metadataSchemaVersion === 'paper-instrument-metadata.v2'
                        && ($maxMarketNotional === null
                            || $maxLimitNotional === null
                            || $orderNotionalLimitModel
                                !== HyperliquidOrderNotionalLimits::MODEL
                            || !self::decimal($maxLimitNotional)->isEqualTo(
                                self::decimal($maxMarketNotional)->multipliedBy(10),
                            )))))
        ) {
            throw new \InvalidArgumentException('paper_backtest_instrument_metadata_invalid');
        }
        if ($marketDataVenue === 'hyperliquid'
            && $metadataSchemaVersion === 'paper-instrument-metadata.v2'
            && (preg_match('/\A[1-9][0-9]*\z/D', (string) $maxLeverage) !== 1
                || $maxMarketNotional
                    !== HyperliquidOrderNotionalLimits::maximumMarketNotional((int) $maxLeverage))
        ) {
            throw new \InvalidArgumentException('paper_backtest_instrument_metadata_invalid');
        }
        self::timestamp($happenedAt);
        self::timestamp($availableAt);
    }

    /** @return array<string, int|string|null> */
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
            'metadata_schema_version' => $this->metadataSchemaVersion,
            'happened_at' => $this->happenedAt,
            'available_at' => $this->availableAt,
            'source_epoch' => $this->sourceEpoch,
            'base_asset' => $this->baseAsset,
            'quote_asset' => $this->quoteAsset,
            'settlement_asset' => $this->settlementAsset,
            'price_tick' => $this->priceTick,
            'quantity_unit' => $this->quantityUnit,
            'quantity_step' => $this->quantityStep,
            'minimum_quantity' => $this->minQuantity,
            'maximum_market_quantity' => $this->maxMarketQuantity,
            'maximum_limit_quantity' => $this->maxLimitQuantity,
            'maximum_leverage' => $this->maxLeverage,
            'maximum_market_notional' => $this->maxMarketNotional,
            'maximum_limit_notional' => $this->maxLimitNotional,
            'order_notional_limit_model' => $this->orderNotionalLimitModel,
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
