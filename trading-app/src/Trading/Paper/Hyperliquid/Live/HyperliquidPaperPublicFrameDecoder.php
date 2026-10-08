<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use Brick\Math\BigDecimal;

final readonly class HyperliquidPaperPublicFrameDecoder
{
    private const MAX_DECIMAL_BYTES = 128;
    /**
     * A sanity bound only: Hyperliquid sends all fills of a block in one trades message (a
     * sweep produced more than 1000 on 2026-10-02), and the smallest valid row takes more
     * than 64 bytes, so the frame byte limit is always reached first.
     */
    public const MAX_TRADE_ROWS = HyperliquidPaperLivePolicy::MAX_FRAME_BYTES >> 6;
    private const MAX_TID = 1_125_899_906_842_623;

    public function __construct(
        private HyperliquidPaperPublicSubscriptionSet $subscriptions,
    ) {
    }

    /**
     * A rejected frame always fails as hyperliquid_paper_public_message_invalid; the previous
     * exception names the failed check with the frame's channel, size, rows and digest, so a
     * rejection can be diagnosed without the frame itself.
     *
     * @return array{kind: string, data?: mixed}
     */
    public function decode(#[\SensitiveParameter] string $frame): array
    {
        $channel = null;
        $rows = null;
        try {
            if ($frame === ''
                || \strlen($frame) > HyperliquidPaperLivePolicy::MAX_FRAME_BYTES
            ) {
                self::invalid('frame_size');
            }
            $message = json_decode($frame, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($message) || array_is_list($message)) {
                self::invalid('not_an_object');
            }

            $channel = $message['channel'] ?? null;
            if ($channel === 'pong') {
                self::assertExactKeys($message, ['channel'], 'pong_keys');

                return ['kind' => 'pong'];
            }
            self::assertExactKeys($message, ['channel', 'data'], 'message_keys');
            if (!\is_array($message['data'] ?? null)) {
                self::invalid('data_not_array');
            }
            /** @var array<array-key, mixed> $data */
            $data = $message['data'];
            $rows = \is_string($channel) && $channel === 'trades' ? \count($data) : null;

            return match ($channel) {
                'subscriptionResponse' => $this->subscription($data),
                'trades' => $this->trades($data),
                'l2Book' => $this->book($data),
                'bbo' => $this->bbo($data),
                'candle' => $this->candle($data),
                default => self::invalid('channel_unknown'),
            };
        } catch (\Throwable $exception) {
            $reason = $exception instanceof \UnexpectedValueException
                && str_starts_with($exception->getMessage(), 'check:')
                ? substr($exception->getMessage(), 6)
                : 'unexpected ' . $exception::class . ' ' . $exception->getMessage();

            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_message_invalid',
                0,
                new \InvalidArgumentException(sprintf(
                    '%s channel=%s bytes=%d rows=%s sha256=%s',
                    $reason,
                    \is_string($channel) ? substr(preg_replace('/[^A-Za-z0-9_]/', '?', $channel) ?? '', 0, 32) : '-',
                    \strlen($frame),
                    $rows === null ? '-' : (string) $rows,
                    substr(hash('sha256', $frame), 0, 16),
                )),
            );
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{kind: 'subscription', data: array<string, mixed>}
     */
    private function subscription(array $data): array
    {
        $this->subscriptions->acknowledge($data);

        /** @var array<string, mixed> $data */
        return ['kind' => 'subscription', 'data' => $data];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{kind: 'trades', data: list<array<string, mixed>>}
     */
    private function trades(array $data): array
    {
        if (!array_is_list($data) || $data === []) {
            self::invalid('trades_not_a_list');
        }
        if (\count($data) > self::MAX_TRADE_ROWS) {
            self::invalid('trades_rows_exceeded');
        }
        $rows = [];
        foreach ($data as $row) {
            if (!\is_array($row) || array_is_list($row)) {
                self::invalid('trade_not_an_object');
            }
            self::assertExactKeys(
                $row,
                ['coin', 'side', 'px', 'sz', 'hash', 'time', 'tid', 'users'],
                'trade_keys',
            );
            if (!\is_string($row['coin'] ?? null)
                || !\in_array($row['coin'], ['BTC', 'ETH'], true)
                || !\is_string($row['side'] ?? null)
                || !\in_array($row['side'], ['A', 'B'], true)
                || !\is_string($row['hash'] ?? null)
                || preg_match('/\A0x[0-9a-fA-F]{1,128}\z/D', $row['hash']) !== 1
                || !\is_int($row['time'] ?? null)
                || $row['time'] < 0
                || !\is_int($row['tid'] ?? null)
                || $row['tid'] < 0
                || $row['tid'] > self::MAX_TID
                || !\is_array($row['users'] ?? null)
                || !array_is_list($row['users'])
                || \count($row['users']) !== 2
            ) {
                self::invalid('trade_fields');
            }
            foreach ($row['users'] as $user) {
                if (!\is_string($user)
                    || preg_match('/\A0x[0-9a-fA-F]{1,128}\z/D', $user) !== 1
                ) {
                    self::invalid('trade_users');
                }
            }
            if (!self::positiveDecimal($row['px'] ?? null)
                || !self::positiveDecimal($row['sz'] ?? null)
            ) {
                self::invalid('trade_decimals');
            }
            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return ['kind' => 'trades', 'data' => $rows];
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{kind: 'book', data: array<string, mixed>}
     */
    private function book(array $data): array
    {
        self::assertExactKeys($data, ['coin', 'levels', 'time'], 'book_keys');
        if (!\is_string($data['coin'] ?? null)
            || !\in_array($data['coin'], ['BTC', 'ETH'], true)
            || !\is_int($data['time'] ?? null)
            || $data['time'] < 0
            || !\is_array($data['levels'] ?? null)
            || !array_is_list($data['levels'])
            || \count($data['levels']) !== 2
        ) {
            self::invalid('book_fields');
        }

        $best = [];
        foreach ($data['levels'] as $sideIndex => $levels) {
            if (!\is_array($levels)
                || !array_is_list($levels)
                || $levels === []
                || \count($levels) > HyperliquidPaperLivePolicy::MAX_BOOK_LEVELS_PER_SIDE
            ) {
                self::invalid('book_side');
            }
            $prices = [];
            foreach ($levels as $level) {
                $prices[] = self::level($level);
            }
            $selected = array_shift($prices);
            if (!$selected instanceof BigDecimal) {
                self::invalid('book_side');
            }
            foreach ($prices as $price) {
                $selected = $sideIndex === 0
                    ? ($price->isGreaterThan($selected) ? $price : $selected)
                    : ($price->isLessThan($selected) ? $price : $selected);
            }
            $best[] = $selected;
        }
        if ($best[0]->isGreaterThanOrEqualTo($best[1])) {
            self::invalid('book_crossed');
        }

        /** @var array<string, mixed> $data */
        return ['kind' => 'book', 'data' => $data];
    }

    /**
     * The best bid and offer, pushed by Hyperliquid on every block where either changes. Both
     * sides are required: an empty side cannot be recorded as a top of book.
     *
     * @param array<array-key, mixed> $data
     * @return array{kind: 'book', data: array<string, mixed>}
     */
    private function bbo(array $data): array
    {
        self::assertExactKeys($data, ['bbo', 'coin', 'time'], 'bbo_keys');
        if (!\is_string($data['coin'] ?? null)
            || !\in_array($data['coin'], ['BTC', 'ETH'], true)
            || !\is_int($data['time'] ?? null)
            || $data['time'] < 0
            || !\is_array($data['bbo'] ?? null)
            || !array_is_list($data['bbo'])
            || \count($data['bbo']) !== 2
        ) {
            self::invalid('bbo_fields');
        }
        if ($data['bbo'][0] === null || $data['bbo'][1] === null) {
            self::invalid('bbo_side_empty');
        }
        if (self::level($data['bbo'][0])->isGreaterThanOrEqualTo(self::level($data['bbo'][1]))) {
            self::invalid('book_crossed');
        }

        /** @var array<string, mixed> $data */
        return ['kind' => 'book', 'data' => $data];
    }

    private static function level(mixed $level): BigDecimal
    {
        if (!\is_array($level) || array_is_list($level)) {
            self::invalid('book_level');
        }
        self::assertExactKeys($level, ['px', 'sz', 'n'], 'book_level_keys');
        if (!self::positiveDecimal($level['px'] ?? null)
            || !self::positiveDecimal($level['sz'] ?? null)
            || !\is_int($level['n'] ?? null)
            || $level['n'] < 1
        ) {
            self::invalid('book_level');
        }

        return BigDecimal::of($level['px']);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array{kind: 'candle', data: array<string, mixed>}
     */
    private function candle(array $data): array
    {
        $coin = $data['s'] ?? null;
        $interval = $data['i'] ?? null;
        if (!\is_string($coin)
            || !\in_array($coin, ['BTC', 'ETH'], true)
            || !\is_string($interval)
            || !\in_array($interval, ['1m', '5m', '15m', '1h'], true)
        ) {
            self::invalid('candle_stream');
        }
        HyperliquidCandle::fromApiRow($data, $coin, $interval);

        /** @var array<string, mixed> $data */
        return ['kind' => 'candle', 'data' => $data];
    }

    private static function positiveDecimal(mixed $value): bool
    {
        return \is_string($value)
            && \strlen($value) <= self::MAX_DECIMAL_BYTES
            && preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) === 1
            && BigDecimal::of($value)->isGreaterThan(0);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $keys
     */
    private static function assertExactKeys(array $value, array $keys, string $check): void
    {
        $actual = array_keys($value);
        sort($actual, \SORT_STRING);
        sort($keys, \SORT_STRING);
        if ($actual !== $keys) {
            self::invalid($check);
        }
    }

    private static function invalid(string $check): never
    {
        throw new \UnexpectedValueException('check:' . $check);
    }
}
