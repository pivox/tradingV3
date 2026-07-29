<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use Brick\Math\BigDecimal;

final readonly class HyperliquidPaperPublicFrameDecoder
{
    private const MAX_DECIMAL_BYTES = 128;
    private const MAX_TRADE_ROWS = 1_000;
    private const MAX_TID = 1_125_899_906_842_623;

    public function __construct(
        private HyperliquidPaperPublicSubscriptionSet $subscriptions,
    ) {
    }

    /** @return array{kind: string, data?: mixed} */
    public function decode(#[\SensitiveParameter] string $frame): array
    {
        try {
            if ($frame === ''
                || \strlen($frame) > HyperliquidPaperLivePolicy::MAX_FRAME_BYTES
            ) {
                self::invalid();
            }
            $message = json_decode($frame, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($message) || array_is_list($message)) {
                self::invalid();
            }

            $channel = $message['channel'] ?? null;
            if ($channel === 'pong') {
                self::assertExactKeys($message, ['channel']);

                return ['kind' => 'pong'];
            }
            self::assertExactKeys($message, ['channel', 'data']);
            if (!\is_array($message['data'] ?? null)) {
                self::invalid();
            }
            /** @var array<array-key, mixed> $data */
            $data = $message['data'];

            return match ($channel) {
                'subscriptionResponse' => $this->subscription($data),
                'trades' => $this->trades($data),
                'l2Book' => $this->book($data),
                'candle' => $this->candle($data),
                default => self::invalid(),
            };
        } catch (\Throwable $exception) {
            if ($exception instanceof HyperliquidPaperLiveIntegrityException
                && $exception->getMessage() === 'hyperliquid_paper_public_message_invalid'
            ) {
                throw $exception;
            }

            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_message_invalid',
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
        if (!array_is_list($data)
            || $data === []
            || \count($data) > self::MAX_TRADE_ROWS
        ) {
            self::invalid();
        }
        $rows = [];
        foreach ($data as $row) {
            if (!\is_array($row) || array_is_list($row)) {
                self::invalid();
            }
            self::assertExactKeys(
                $row,
                ['coin', 'side', 'px', 'sz', 'hash', 'time', 'tid', 'users'],
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
                self::invalid();
            }
            foreach ($row['users'] as $user) {
                if (!\is_string($user)
                    || preg_match('/\A0x[0-9a-fA-F]{1,128}\z/D', $user) !== 1
                ) {
                    self::invalid();
                }
            }
            if (!self::positiveDecimal($row['px'] ?? null)
                || !self::positiveDecimal($row['sz'] ?? null)
            ) {
                self::invalid();
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
        self::assertExactKeys($data, ['coin', 'levels', 'time']);
        if (!\is_string($data['coin'] ?? null)
            || !\in_array($data['coin'], ['BTC', 'ETH'], true)
            || !\is_int($data['time'] ?? null)
            || $data['time'] < 0
            || !\is_array($data['levels'] ?? null)
            || !array_is_list($data['levels'])
            || \count($data['levels']) !== 2
        ) {
            self::invalid();
        }

        $best = [];
        foreach ($data['levels'] as $sideIndex => $levels) {
            if (!\is_array($levels)
                || !array_is_list($levels)
                || $levels === []
                || \count($levels) > HyperliquidPaperLivePolicy::MAX_BOOK_LEVELS_PER_SIDE
            ) {
                self::invalid();
            }
            $prices = [];
            foreach ($levels as $level) {
                if (!\is_array($level) || array_is_list($level)) {
                    self::invalid();
                }
                self::assertExactKeys($level, ['px', 'sz', 'n']);
                if (!self::positiveDecimal($level['px'] ?? null)
                    || !self::positiveDecimal($level['sz'] ?? null)
                    || !\is_int($level['n'] ?? null)
                    || $level['n'] < 1
                ) {
                    self::invalid();
                }
                $prices[] = BigDecimal::of($level['px']);
            }
            $selected = array_shift($prices);
            if (!$selected instanceof BigDecimal) {
                self::invalid();
            }
            foreach ($prices as $price) {
                $selected = $sideIndex === 0
                    ? ($price->isGreaterThan($selected) ? $price : $selected)
                    : ($price->isLessThan($selected) ? $price : $selected);
            }
            $best[] = $selected;
        }
        if ($best[0]->isGreaterThanOrEqualTo($best[1])) {
            self::invalid();
        }

        /** @var array<string, mixed> $data */
        return ['kind' => 'book', 'data' => $data];
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
            self::invalid();
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
    private static function assertExactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, \SORT_STRING);
        sort($keys, \SORT_STRING);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new HyperliquidPaperLiveIntegrityException(
            'hyperliquid_paper_public_message_invalid',
        );
    }
}
