<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Historical;

final class HyperliquidHistoricalTimeGrid
{
    private const INVALID = 'hyperliquid_historical_time_grid_invalid';

    public static function epochMicroseconds(\DateTimeImmutable $timestamp): int
    {
        $secondsValue = $timestamp->format('U');
        $microsecondsValue = $timestamp->format('u');
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $secondsValue) !== 1
            || preg_match('/\A[0-9]{6}\z/D', $microsecondsValue) !== 1
            || !self::fitsInteger($secondsValue)
        ) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        $seconds = (int) $secondsValue;
        $microseconds = (int) $microsecondsValue;
        if ($seconds > intdiv(\PHP_INT_MAX - $microseconds, 1_000_000)) {
            throw new \InvalidArgumentException(self::INVALID);
        }

        return ($seconds * 1_000_000) + $microseconds;
    }

    public static function firstGridStartMilliseconds(
        \DateTimeImmutable $from,
        int $intervalMilliseconds,
    ): int {
        if ($intervalMilliseconds <= 0
            || $intervalMilliseconds > intdiv(\PHP_INT_MAX, 1_000)
        ) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        $intervalMicroseconds = $intervalMilliseconds * 1_000;
        $quotient = self::ceilingQuotient(
            self::epochMicroseconds($from),
            $intervalMicroseconds,
        );
        if ($quotient > intdiv(\PHP_INT_MAX, $intervalMilliseconds)) {
            throw new \InvalidArgumentException(self::INVALID);
        }

        return $quotient * $intervalMilliseconds;
    }

    public static function exclusiveToMilliseconds(\DateTimeImmutable $to): int
    {
        $microseconds = self::epochMicroseconds($to);
        if ($microseconds > \PHP_INT_MAX - 999) {
            throw new \InvalidArgumentException(self::INVALID);
        }

        return self::ceilingQuotient($microseconds, 1_000);
    }

    public static function expectedCount(
        int $firstGridStartMilliseconds,
        int $exclusiveToMilliseconds,
        int $intervalMilliseconds,
    ): int {
        if ($firstGridStartMilliseconds < 0
            || $exclusiveToMilliseconds < 0
            || $intervalMilliseconds <= 0
        ) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        $exclusiveStartLimit = self::exclusiveStartLimitFromMilliseconds(
            $exclusiveToMilliseconds,
            $intervalMilliseconds,
        );
        if ($firstGridStartMilliseconds >= $exclusiveStartLimit) {
            return 0;
        }

        $lastOffset = ($exclusiveStartLimit - 1) - $firstGridStartMilliseconds;
        $quotient = intdiv($lastOffset, $intervalMilliseconds);
        if ($quotient === \PHP_INT_MAX) {
            throw new \InvalidArgumentException(self::INVALID);
        }

        return $quotient + 1;
    }

    public static function exclusiveStartLimitMilliseconds(
        \DateTimeImmutable $to,
        int $intervalMilliseconds,
    ): int {
        return self::exclusiveStartLimitFromMilliseconds(
            self::exclusiveToMilliseconds($to),
            $intervalMilliseconds,
        );
    }

    private static function exclusiveStartLimitFromMilliseconds(
        int $exclusiveToMilliseconds,
        int $intervalMilliseconds,
    ): int {
        if ($exclusiveToMilliseconds < 0 || $intervalMilliseconds <= 0) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        if ($exclusiveToMilliseconds < $intervalMilliseconds) {
            return 0;
        }

        return $exclusiveToMilliseconds - ($intervalMilliseconds - 1);
    }

    private static function ceilingQuotient(int $value, int $divisor): int
    {
        if ($value < 0 || $divisor <= 0) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        $quotient = intdiv($value, $divisor);
        if ($value % $divisor === 0) {
            return $quotient;
        }
        if ($quotient === \PHP_INT_MAX) {
            throw new \InvalidArgumentException(self::INVALID);
        }

        return $quotient + 1;
    }

    private static function fitsInteger(string $value): bool
    {
        $maximum = (string) \PHP_INT_MAX;

        return strlen($value) < strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0);
    }
}
