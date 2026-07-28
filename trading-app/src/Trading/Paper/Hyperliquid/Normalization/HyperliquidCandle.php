<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use Brick\Math\BigDecimal;

final readonly class HyperliquidCandle
{
    /** @var list<string> */
    private const API_KEYS = ['T', 'c', 'h', 'i', 'l', 'n', 'o', 's', 't', 'v'];
    private const MAX_DECIMAL_LENGTH = 128;

    private function __construct(
        public string $coin,
        public string $interval,
        public int $startTime,
        public int $closeTime,
        public BigDecimal $open,
        public BigDecimal $high,
        public BigDecimal $low,
        public BigDecimal $close,
        public BigDecimal $volume,
        public int $tradeCount,
    ) {
    }

    public static function fromApiRow(mixed $row, string $expectedCoin, string $expectedInterval): self
    {
        if (!\is_array($row)) {
            throw new \InvalidArgumentException('hyperliquid_candle_shape_invalid');
        }

        $keys = array_keys($row);
        sort($keys, \SORT_STRING);
        if ($keys !== self::API_KEYS) {
            throw new \InvalidArgumentException('hyperliquid_candle_shape_invalid');
        }

        $instruments = new HyperliquidPaperInstrumentMap();
        try {
            $instruments->normalizedSymbol($expectedCoin);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('hyperliquid_candle_expected_coin_invalid');
        }

        try {
            $intervalMilliseconds = $instruments->intervalMilliseconds($expectedInterval);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('hyperliquid_candle_expected_interval_invalid');
        }

        if ($row['s'] !== $expectedCoin) {
            throw new \InvalidArgumentException('hyperliquid_candle_coin_mismatch');
        }
        if ($row['i'] !== $expectedInterval) {
            throw new \InvalidArgumentException('hyperliquid_candle_interval_mismatch');
        }

        foreach (['t', 'T', 'n'] as $key) {
            if (!\is_int($row[$key]) || $row[$key] < 0) {
                throw new \InvalidArgumentException('hyperliquid_candle_integer_invalid');
            }
        }

        $startTime = $row['t'];
        $closeTime = $row['T'];
        $tradeCount = $row['n'];
        $durationMinusOne = $intervalMilliseconds - 1;
        if ($startTime > \PHP_INT_MAX - $durationMinusOne
            || $closeTime !== $startTime + $durationMinusOne
        ) {
            throw new \InvalidArgumentException('hyperliquid_candle_close_time_invalid');
        }

        $open = self::decimal($row['o']);
        $high = self::decimal($row['h']);
        $low = self::decimal($row['l']);
        $close = self::decimal($row['c']);
        $volume = self::decimal($row['v']);

        foreach ([$open, $high, $low, $close] as $price) {
            if (!$price->isGreaterThan(0)) {
                throw new \InvalidArgumentException('hyperliquid_candle_price_invalid');
            }
        }
        if ($volume->isLessThan(0)) {
            throw new \InvalidArgumentException('hyperliquid_candle_volume_invalid');
        }

        if ($high->isLessThan($open)
            || $high->isLessThan($close)
            || $high->isLessThan($low)
            || $low->isGreaterThan($open)
            || $low->isGreaterThan($close)
            || $low->isGreaterThan($high)
        ) {
            throw new \InvalidArgumentException('hyperliquid_candle_geometry_invalid');
        }

        return new self(
            coin: $expectedCoin,
            interval: $expectedInterval,
            startTime: $startTime,
            closeTime: $closeTime,
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            volume: $volume,
            tradeCount: $tradeCount,
        );
    }

    public function range(): BigDecimal
    {
        return $this->high->minus($this->low);
    }

    public function trueRange(?self $previous): BigDecimal
    {
        if ($previous === null) {
            return $this->range();
        }
        if ($previous->coin !== $this->coin
            || $previous->interval !== $this->interval
            || $previous->startTime >= $this->startTime
        ) {
            throw new \InvalidArgumentException('hyperliquid_candle_previous_mismatch');
        }

        $range = $this->range();
        $fromHigh = $this->high->minus($previous->close)->abs();
        $fromLow = $this->low->minus($previous->close)->abs();

        return self::maximum($range, self::maximum($fromHigh, $fromLow));
    }

    private static function decimal(mixed $value): BigDecimal
    {
        if (!\is_string($value)
            || strlen($value) > self::MAX_DECIMAL_LENGTH
            || preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException('hyperliquid_candle_decimal_invalid');
        }

        $decimal = BigDecimal::of($value);
        if (str_starts_with($value, '-') && $decimal->isZero()) {
            throw new \InvalidArgumentException('hyperliquid_candle_decimal_invalid');
        }

        return $decimal->stripTrailingZeros();
    }

    private static function maximum(BigDecimal $first, BigDecimal $second): BigDecimal
    {
        return $first->isGreaterThanOrEqualTo($second) ? $first : $second;
    }
}
