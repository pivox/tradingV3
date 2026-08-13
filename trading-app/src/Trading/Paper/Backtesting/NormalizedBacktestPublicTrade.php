<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

use Brick\Math\BigDecimal;

final readonly class NormalizedBacktestPublicTrade
{
    public const SCHEMA_VERSION = 'backtest-public-trade.v1';
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function __construct(
        public string $sourceRecordId,
        public string $sourceNetwork,
        public string $marketDataVenue,
        public string $symbol,
        public string $venueTradeId,
        public string $happenedAt,
        public string $availableAt,
        public string $aggressorSide,
        public string $price,
        public string $quantity,
        public string $quantityUnit,
    ) {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $sourceRecordId) !== 1
            || !\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || !\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)
            || \strlen($venueTradeId) > 128
            || preg_match('/\A(?:0|[1-9][0-9]*)(?::(?:0|[1-9][0-9]*))?\z/D', $venueTradeId) !== 1
            || !\in_array($aggressorSide, ['buy', 'sell'], true)
            || !\in_array($quantityUnit, ['contracts', 'base_asset'], true)
            || ($marketDataVenue === 'okx' && $quantityUnit !== 'contracts')
            || ($marketDataVenue === 'hyperliquid' && $quantityUnit !== 'base_asset')
            || ($marketDataVenue === 'okx' && str_contains($venueTradeId, ':'))
            || ($marketDataVenue === 'hyperliquid' && !str_contains($venueTradeId, ':'))
            || !self::decimal($price)->isPositive()
            || !self::decimal($quantity)->isPositive()
        ) {
            throw new \InvalidArgumentException('paper_backtest_public_trade_invalid');
        }
        $happened = self::timestamp($happenedAt);
        $available = self::timestamp($availableAt);
        if ($available < $happened) {
            throw new \InvalidArgumentException('paper_backtest_public_trade_invalid');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_record_id' => $this->sourceRecordId,
            'source_network' => $this->sourceNetwork,
            'market_data_venue' => $this->marketDataVenue,
            'market_type' => 'perpetual',
            'symbol' => $this->symbol,
            'venue_trade_id' => $this->venueTradeId,
            'happened_at' => $this->happenedAt,
            'available_at' => $this->availableAt,
            'aggressor_side' => $this->aggressorSide,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'quantity_unit' => $this->quantityUnit,
        ];
    }

    private static function decimal(string $value): BigDecimal
    {
        if (\strlen($value) > 256
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('paper_backtest_public_trade_invalid');
        }
        return BigDecimal::of($value);
    }

    private static function timestamp(string $value): \DateTimeImmutable
    {
        try {
            $timestamp = \DateTimeImmutable::createFromFormat(
                '!' . self::TIMESTAMP_FORMAT,
                $value,
                new \DateTimeZone('UTC'),
            );
        } catch (\ValueError) {
            throw new \InvalidArgumentException('paper_backtest_public_trade_invalid');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \InvalidArgumentException('paper_backtest_public_trade_invalid');
        }
        return $timestamp;
    }
}
