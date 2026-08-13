<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use Brick\Math\BigDecimal;

final readonly class CanonicalIndicatorCandle
{
    public const SCHEMA_VERSION = 'backtest-candle.v1';

    /** @var array<string, int> */
    private const DURATIONS = ['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600];
    private const MAX_DECIMAL_LENGTH = 256;
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';
    private const KEYS = [
        'schema_version',
        'source_record_id',
        'source_network',
        'market_data_venue',
        'market_type',
        'symbol',
        'timeframe',
        'open_at',
        'close_at',
        'available_at',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'complete',
    ];

    private \DateTimeImmutable $openTime;
    private \DateTimeImmutable $closeTime;
    private \DateTimeImmutable $availableTime;

    private function __construct(
        public string $sourceRecordId,
        public string $sourceNetwork,
        public string $marketDataVenue,
        public string $marketType,
        public string $symbol,
        public string $timeframe,
        public string $openAt,
        public string $closeAt,
        public string $availableAt,
        public string $open,
        public string $high,
        public string $low,
        public string $close,
        public string $volume,
        public bool $complete,
    ) {
        if (!isset(self::DURATIONS[$timeframe])) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_timeframe_invalid');
        }
        if ($sourceRecordId === '' || preg_match('/\A[0-9a-f]{64}\z/D', $sourceRecordId) !== 1
            || !\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || $marketType !== 'perpetual'
            || !\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_identity_invalid');
        }
        if (!$complete) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_incomplete');
        }

        $this->openTime = self::timestamp($openAt);
        $this->closeTime = self::timestamp($closeAt);
        $this->availableTime = self::timestamp($availableAt);
        $duration = self::DURATIONS[$timeframe];
        if ($this->openTime->format('u') !== '000000'
            || ((int) $this->openTime->format('U')) % $duration !== 0
            || $this->closeTime != $this->openTime->modify('+' . $duration . ' seconds')
            || $this->availableTime < $this->closeTime
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_time_invalid');
        }

        foreach ([$open, $high, $low, $close, $volume] as $decimal) {
            if (\strlen($decimal) > self::MAX_DECIMAL_LENGTH
                || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]*[1-9])?\z/D', $decimal) !== 1
            ) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_decimal_invalid');
            }
        }

        $openValue = BigDecimal::of($open);
        $highValue = BigDecimal::of($high);
        $lowValue = BigDecimal::of($low);
        $closeValue = BigDecimal::of($close);
        if (!$openValue->isPositive() || !$highValue->isPositive()
            || !$lowValue->isPositive() || !$closeValue->isPositive()
            || BigDecimal::of($volume)->isNegative()
            || $lowValue->isGreaterThan($openValue) || $lowValue->isGreaterThan($closeValue)
            || $highValue->isLessThan($openValue) || $highValue->isLessThan($closeValue)
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_geometry_invalid');
        }
    }

    /** @param array<string, mixed> $record */
    public static function fromArray(array $record): self
    {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        $expectedKeys = self::KEYS;
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || !\is_string($record['schema_version'])
            || !\is_string($record['source_record_id'])
            || !\is_string($record['source_network'])
            || !\is_string($record['market_data_venue'])
            || !\is_string($record['market_type'])
            || !\is_string($record['symbol'])
            || !\is_string($record['timeframe'])
            || !\is_string($record['open_at'])
            || !\is_string($record['close_at'])
            || !\is_string($record['available_at'])
            || !\is_string($record['open'])
            || !\is_string($record['high'])
            || !\is_string($record['low'])
            || !\is_string($record['close'])
            || !\is_string($record['volume'])
            || !\is_bool($record['complete'])
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_shape_invalid');
        }
        if ($record['schema_version'] !== self::SCHEMA_VERSION) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_schema_invalid');
        }

        return new self(
            $record['source_record_id'],
            $record['source_network'],
            $record['market_data_venue'],
            $record['market_type'],
            $record['symbol'],
            $record['timeframe'],
            $record['open_at'],
            $record['close_at'],
            $record['available_at'],
            $record['open'],
            $record['high'],
            $record['low'],
            $record['close'],
            $record['volume'],
            $record['complete'],
        );
    }

    /** @return array<string, bool|string> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_record_id' => $this->sourceRecordId,
            'source_network' => $this->sourceNetwork,
            'market_data_venue' => $this->marketDataVenue,
            'market_type' => $this->marketType,
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'open_at' => $this->openAt,
            'close_at' => $this->closeAt,
            'available_at' => $this->availableAt,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'complete' => $this->complete,
        ];
    }

    public function openTimestamp(): \DateTimeImmutable
    {
        return $this->openTime;
    }

    public function closeTimestamp(): \DateTimeImmutable
    {
        return $this->closeTime;
    }

    public function availableTimestamp(): \DateTimeImmutable
    {
        return $this->availableTime;
    }

    public function durationSeconds(): int
    {
        return self::DURATIONS[$this->timeframe];
    }

    public static function parseTimestamp(string $value): \DateTimeImmutable
    {
        return self::timestamp($value);
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
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_time_invalid');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(self::TIMESTAMP_FORMAT) !== $value
        ) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_candle_time_invalid');
        }

        return $timestamp;
    }
}
