<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class HyperliquidHistoricalEventCoverage
{
    /** @var array<string, int> */
    private const INTERVAL_MILLISECONDS = [
        '1m' => 60_000,
        '5m' => 300_000,
        '15m' => 900_000,
        '1h' => 3_600_000,
    ];

    private function __construct(
        public string $interval,
        public int $intervalMilliseconds,
        public int $startMilliseconds,
        public int $closeMilliseconds,
        public bool $modelledBook,
    ) {
    }

    public static function parse(
        #[\SensitiveParameter] PaperMarketEvent $event,
    ): self {
        $interval = match ($event->channel) {
            PaperMarketDataChannel::CANDLE_1M => '1m',
            PaperMarketDataChannel::CANDLE_5M => '5m',
            PaperMarketDataChannel::CANDLE_15M => '15m',
            PaperMarketDataChannel::CANDLE_1H => '1h',
            PaperMarketDataChannel::TOP_OF_BOOK => null,
            default => throw new \InvalidArgumentException(),
        };
        $close = self::eventTimestampMilliseconds($event);
        if ($interval !== null) {
            $payload = $event->payload;
            if (($payload['interval'] ?? null) !== $interval
                || ($payload['origin'] ?? null) !== 'rest_candle_snapshot'
                || ($payload['confirmed'] ?? null) !== true
                || !\is_string($payload['start_time'] ?? null)
                || !\is_string($payload['close_time'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $start = self::unsignedInteger($payload['start_time']);
            $payloadClose = self::unsignedInteger($payload['close_time']);
            $duration = self::INTERVAL_MILLISECONDS[$interval];
            self::assertBoundary($start, $payloadClose, $duration);
            if ($payloadClose !== $close) {
                throw new \InvalidArgumentException();
            }

            return new self($interval, $duration, $start, $close, false);
        }

        $payload = $event->payload;
        if (($payload['model_name'] ?? null) !== 'hl_candle_atr_top_v1'
            || ($payload['model_version'] ?? null) !== '1.0.0'
            || ($payload['origin'] ?? null) !== 'historical_candle_model'
            || ($payload['synthetic'] ?? null) !== true
            || !\is_string($payload['source_candle_start'] ?? null)
        ) {
            throw new \InvalidArgumentException();
        }
        $start = self::unsignedInteger($payload['source_candle_start']);
        foreach (self::INTERVAL_MILLISECONDS as $candidate => $duration) {
            if ($start <= \PHP_INT_MAX - ($duration - 1)
                && $start + $duration - 1 === $close
            ) {
                self::assertBoundary($start, $close, $duration);

                return new self($candidate, $duration, $start, $close, true);
            }
        }

        throw new \InvalidArgumentException();
    }

    private static function assertBoundary(int $start, int $close, int $duration): void
    {
        if ($start % $duration !== 0
            || $start > \PHP_INT_MAX - ($duration - 1)
            || $start + $duration - 1 !== $close
        ) {
            throw new \InvalidArgumentException();
        }
    }

    private static function eventTimestampMilliseconds(
        #[\SensitiveParameter] PaperMarketEvent $event,
    ): int {
        $microseconds = $event->exchangeTimestamp->format('u');
        $seconds = self::unsignedInteger($event->exchangeTimestamp->format('U'));
        if (preg_match('/\A[0-9]{6}\z/D', $microseconds) !== 1
            || ((int) $microseconds) % 1_000 !== 0
            || $seconds > intdiv(\PHP_INT_MAX - 999, 1_000)
        ) {
            throw new \InvalidArgumentException();
        }

        return ($seconds * 1_000) + intdiv((int) $microseconds, 1_000);
    }

    private static function unsignedInteger(#[\SensitiveParameter] string $value): int
    {
        $maximum = (string) \PHP_INT_MAX;
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new \InvalidArgumentException();
        }

        return (int) $value;
    }
}
