<?php

declare(strict_types=1);

namespace App\Trading\Paper\Backtesting;

use Brick\Math\BigDecimal;

final readonly class NormalizedBacktestCandle
{
    public const SCHEMA_VERSION = 'backtest-candle.v1';
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var array<string, int> */
    private const DURATIONS = ['1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600];

    public function __construct(
        public string $sourceRecordId,
        public string $sourceNetwork,
        public string $marketDataVenue,
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
    ) {
        if (preg_match('/\A[0-9a-f]{64}\z/D', $sourceRecordId) !== 1
            || !\in_array($sourceNetwork, ['mainnet', 'testnet'], true)
            || !\in_array($marketDataVenue, ['okx', 'hyperliquid'], true)
            || !\in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)
            || !isset(self::DURATIONS[$timeframe])
        ) {
            throw new \InvalidArgumentException('paper_backtest_candle_identity_invalid');
        }
        $openTime = self::timestamp($openAt);
        $closeTime = self::timestamp($closeAt);
        $availableTime = self::timestamp($availableAt);
        if ($openTime->format('u') !== '000000'
            || ((int) $openTime->format('U')) % self::DURATIONS[$timeframe] !== 0
            || $closeTime != $openTime->modify('+' . self::DURATIONS[$timeframe] . ' seconds')
            || $availableTime < $closeTime
        ) {
            throw new \InvalidArgumentException('paper_backtest_candle_time_invalid');
        }
        foreach ([$open, $high, $low, $close, $volume] as $decimal) {
            if (preg_match('/\A(?:0|[1-9][0-9]*(?:\.[0-9]*[1-9])?)\z/D', $decimal) !== 1) {
                throw new \InvalidArgumentException('paper_backtest_candle_decimal_invalid');
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
            throw new \InvalidArgumentException('paper_backtest_candle_geometry_invalid');
        }
    }

    /** @return array<string, bool|string> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source_record_id' => $this->sourceRecordId,
            'source_network' => $this->sourceNetwork,
            'market_data_venue' => $this->marketDataVenue,
            'market_type' => 'perpetual',
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
            'complete' => true,
        ];
    }

    private static function timestamp(string $value): \DateTimeImmutable
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
            throw new \InvalidArgumentException('paper_backtest_candle_time_invalid');
        }

        return $timestamp;
    }
}
