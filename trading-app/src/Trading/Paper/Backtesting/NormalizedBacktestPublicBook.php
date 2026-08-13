<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

use Brick\Math\BigDecimal;

final readonly class NormalizedBacktestPublicBook
{
    public const SCHEMA_VERSION = 'backtest-public-book.v1';
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public function __construct(
        public string $sourceRecordId,
        public string $sourceChecksum,
        public string $sourceNetwork,
        public string $marketDataVenue,
        public string $symbol,
        public string $happenedAt,
        public string $availableAt,
        public string $bidPrice,
        public string $bidQuantity,
        public string $askPrice,
        public string $askQuantity,
        public string $quantityUnit,
        public ?string $bidOrderCount,
        public ?string $askOrderCount,
        public string $origin,
    ) {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $sourceRecordId) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $sourceChecksum) !== 1
            || !\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || !\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)
            || !\in_array($quantityUnit, ['contracts', 'base_asset'], true)
            || ($marketDataVenue === 'okx' && ($quantityUnit !== 'contracts'
                || !self::positiveCount($bidOrderCount)
                || !self::positiveCount($askOrderCount)
                || !\in_array($origin, [
                    'rest_initial_snapshot',
                    'rest_resync_snapshot',
                    'ws_books',
                ], true)))
            || ($marketDataVenue === 'hyperliquid' && ($quantityUnit !== 'base_asset'
                || $bidOrderCount !== null
                || $askOrderCount !== null
                || $origin !== 'ws_l2_book'))
        ) {
            throw new \InvalidArgumentException('paper_backtest_public_book_invalid');
        }
        $bid = self::decimal($bidPrice);
        $ask = self::decimal($askPrice);
        self::decimal($bidQuantity);
        self::decimal($askQuantity);
        if ($bid->isGreaterThanOrEqualTo($ask)) {
            throw new \InvalidArgumentException('paper_backtest_public_book_invalid');
        }
        $happened = self::timestamp($happenedAt);
        $available = self::timestamp($availableAt);
        if ($available < $happened) {
            throw new \InvalidArgumentException('paper_backtest_public_book_invalid');
        }
    }

    /** @return array<string, string|null> */
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
            'happened_at' => $this->happenedAt,
            'available_at' => $this->availableAt,
            'bid_price' => $this->bidPrice,
            'bid_quantity' => $this->bidQuantity,
            'ask_price' => $this->askPrice,
            'ask_quantity' => $this->askQuantity,
            'quantity_unit' => $this->quantityUnit,
            'bid_order_count' => $this->bidOrderCount,
            'ask_order_count' => $this->askOrderCount,
            'origin' => $this->origin,
        ];
    }

    private static function positiveCount(?string $value): bool
    {
        return $value !== null
            && \strlen($value) <= 128
            && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1;
    }

    private static function decimal(string $value): BigDecimal
    {
        if (\strlen($value) > 256
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('paper_backtest_public_book_invalid');
        }
        $decimal = BigDecimal::of($value);
        if (!$decimal->isPositive()) {
            throw new \InvalidArgumentException('paper_backtest_public_book_invalid');
        }
        return $decimal;
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
            throw new \InvalidArgumentException('paper_backtest_public_book_invalid');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new \InvalidArgumentException('paper_backtest_public_book_invalid');
        }
        return $timestamp;
    }
}
