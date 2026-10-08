<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

/**
 * Proves that a standby connection continues every stream of the active one, then cuts the
 * standby backlog where the active connection stopped.
 *
 * - trades: a standby row whose natural identity the active connection already emitted
 *   (same assignment digest) anchors the coin; rows up to the last such anchor are dropped.
 * - l2Book: every message is a full snapshot; the first standby book must not be newer than
 *   the last active one, and only newer standby books are kept.
 * - candles: every message is the full current candle; (start, trade count) orders the
 *   states of a stream, and only states newer than the active one are kept.
 *
 * @phpstan-type StandbyItem array{
 *     decoded: array{kind: string, data?: mixed},
 *     frame: string,
 *     at?: float,
 *     fingerprints?: list<array{identity_hash: string, assignment_digest: string}>
 * }
 */
final class HyperliquidPaperLiveRotationOverlap
{
    private const COINS = ['BTC', 'ETH'];
    private const CANDLE_INTERVALS = ['1m', '5m', '15m', '1h'];

    /**
     * @param list<StandbyItem> $items standby frames in arrival order
     * @param array<string, string> $emitted identity hash => assignment digest of emitted trades
     * @param array<string, int> $lastBookTimes coin => time of the last active book
     * @param array<string, array<string, mixed>> $currentCandles stream => active candle row
     * @param array<string, int> $frontiers stream => start of the last finalized candle
     * @param bool $tradesOnly the active connection is gone: books and candles are full states
     *                         that need no overlap, only the trades must be proven
     *
     * @return array{
     *     missing: list<string>,
     *     trade_cuts: array<string, int>,
     *     anchors: array<string, string>,
     *     trades: array<string, array{rows: int, first_time: int, last_time: int}>,
     *     first_books: array<string, int>,
     *     first_candles: array<string, array{int, int}>
     * }
     */
    public static function evaluate(
        array $items,
        array $emitted,
        array $lastBookTimes,
        array $currentCandles,
        array $frontiers,
        bool $tradesOnly = false,
    ): array {
        $tradeCuts = [];
        $anchors = [];
        $trades = [];
        $firstBooks = [];
        $firstCandles = [];
        $rowIndex = 0;
        foreach ($items as $item) {
            $data = $item['decoded']['data'] ?? null;
            if (!\is_array($data)) {
                continue;
            }
            switch ($item['decoded']['kind']) {
                case 'trades':
                    foreach (array_values($data) as $position => $row) {
                        $fingerprint = $item['fingerprints'][$position] ?? null;
                        if (!\is_array($row) || $fingerprint === null) {
                            throw new \LogicException('hyperliquid_paper_rotation_item_invalid');
                        }
                        $coin = self::string($row['coin'] ?? null);
                        $time = self::integer($row['time'] ?? null);
                        $trades[$coin] = [
                            'rows' => ($trades[$coin]['rows'] ?? 0) + 1,
                            'first_time' => min($trades[$coin]['first_time'] ?? $time, $time),
                            'last_time' => max($trades[$coin]['last_time'] ?? $time, $time),
                        ];
                        $known = $emitted[$fingerprint['identity_hash']] ?? null;
                        if ($known !== null) {
                            if (!hash_equals($known, $fingerprint['assignment_digest'])) {
                                throw new \RuntimeException(
                                    'hyperliquid_paper_natural_identity_conflict',
                                );
                            }
                            $tradeCuts[$coin] = $rowIndex;
                            $anchors[$coin] = $time . '/' . self::string($row['tid'] ?? null);
                        }
                        ++$rowIndex;
                    }
                    break;
                case 'book':
                    $coin = self::string($data['coin'] ?? null);
                    $firstBooks[$coin] ??= self::integer($data['time'] ?? null);
                    break;
                case 'candle':
                    $stream = self::string($data['s'] ?? null) . '/' . self::string($data['i'] ?? null);
                    $firstCandles[$stream] ??= [
                        self::integer($data['t'] ?? null),
                        self::integer($data['n'] ?? null),
                    ];
                    break;
            }
        }

        $missing = [];
        foreach (self::COINS as $coin) {
            if (!isset($tradeCuts[$coin])) {
                $missing[] = 'trades/' . $coin;
            }
            if ($tradesOnly) {
                continue;
            }
            if (!isset($firstBooks[$coin], $lastBookTimes[$coin])
                || $firstBooks[$coin] > $lastBookTimes[$coin]
            ) {
                $missing[] = 'book/' . $coin;
            }
            foreach (self::CANDLE_INTERVALS as $interval) {
                $stream = $coin . '/' . $interval;
                if (!isset($firstCandles[$stream])
                    || !self::candleCovered(
                        $firstCandles[$stream],
                        $currentCandles[$stream] ?? null,
                        $frontiers[$stream] ?? null,
                    )
                ) {
                    $missing[] = 'candle/' . $stream;
                }
            }
        }

        return [
            'missing' => $missing,
            'trade_cuts' => $tradeCuts,
            'anchors' => $anchors,
            'trades' => $trades,
            'first_books' => $firstBooks,
            'first_candles' => $firstCandles,
        ];
    }

    /**
     * @param list<StandbyItem> $items
     * @param array<string, int> $tradeCuts coin => index of the last already-emitted standby row
     * @param array<string, int> $lastBookTimes
     * @param array<string, array<string, mixed>> $currentCandles
     * @param array<string, int> $frontiers
     *
     * @return array{
     *     items: list<array{decoded: array{kind: string, data?: mixed}, frame: string}>,
     *     kept: int,
     *     dropped: int
     * }
     */
    public static function continuation(
        array $items,
        array $tradeCuts,
        array $lastBookTimes,
        array $currentCandles,
        array $frontiers,
    ): array {
        $continuation = [];
        $kept = 0;
        $dropped = 0;
        $rowIndex = 0;
        foreach ($items as $item) {
            $data = $item['decoded']['data'] ?? null;
            if (!\is_array($data)) {
                continue;
            }
            switch ($item['decoded']['kind']) {
                case 'trades':
                    $rows = [];
                    foreach (array_values($data) as $row) {
                        if (!\is_array($row)) {
                            throw new \LogicException('hyperliquid_paper_rotation_item_invalid');
                        }
                        $cut = $tradeCuts[self::string($row['coin'] ?? null)] ?? \PHP_INT_MAX;
                        if ($rowIndex > $cut) {
                            $rows[] = $row;
                        } else {
                            ++$dropped;
                        }
                        ++$rowIndex;
                    }
                    if ($rows !== []) {
                        $continuation[] = [
                            'decoded' => ['kind' => 'trades', 'data' => $rows],
                            'frame' => $item['frame'],
                        ];
                        $kept += \count($rows);
                    }
                    break;
                case 'book':
                    $coin = self::string($data['coin'] ?? null);
                    if (self::integer($data['time'] ?? null) > ($lastBookTimes[$coin] ?? -1)) {
                        $continuation[] = ['decoded' => $item['decoded'], 'frame' => $item['frame']];
                        ++$kept;
                    } else {
                        ++$dropped;
                    }
                    break;
                case 'candle':
                    $stream = self::string($data['s'] ?? null) . '/' . self::string($data['i'] ?? null);
                    if (self::candleNewer(
                        [self::integer($data['t'] ?? null), self::integer($data['n'] ?? null)],
                        $currentCandles[$stream] ?? null,
                        $frontiers[$stream] ?? null,
                    )) {
                        $continuation[] = ['decoded' => $item['decoded'], 'frame' => $item['frame']];
                        ++$kept;
                    } else {
                        ++$dropped;
                    }
                    break;
                default:
                    ++$dropped;
            }
        }

        return ['items' => $continuation, 'kept' => $kept, 'dropped' => $dropped];
    }

    /**
     * @param array{int, int} $state standby (start, trade count)
     * @param array<string, mixed>|null $current
     */
    private static function candleCovered(array $state, ?array $current, ?int $frontier): bool
    {
        if ($frontier !== null && $state[0] <= $frontier) {
            return true;
        }
        if ($current === null) {
            return false;
        }
        $currentState = [self::integer($current['t'] ?? null), self::integer($current['n'] ?? null)];

        return $state <= $currentState;
    }

    /**
     * @param array{int, int} $state
     * @param array<string, mixed>|null $current
     */
    private static function candleNewer(array $state, ?array $current, ?int $frontier): bool
    {
        if ($frontier !== null && $state[0] <= $frontier) {
            return false;
        }
        if ($current === null) {
            return true;
        }

        return $state > [self::integer($current['t'] ?? null), self::integer($current['n'] ?? null)];
    }

    private static function string(mixed $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }
        if (!\is_string($value)) {
            throw new \LogicException('hyperliquid_paper_rotation_item_invalid');
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \LogicException('hyperliquid_paper_rotation_item_invalid');
        }

        return $value;
    }
}
