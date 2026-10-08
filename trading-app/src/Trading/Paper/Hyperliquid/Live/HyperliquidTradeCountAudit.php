<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

/**
 * Cross-checks the recorded trades against the trade count `n` of the closed 1m candles.
 *
 * Measured on Hyperliquid mainnet: `n` equals the number of rows the trades feed delivered
 * for the minute, except that the last candle update of a minute sometimes predates its last
 * trades (then `n` is lower, never higher). So fewer recorded rows than `n` proves a hole,
 * while more rows than `n` is Hyperliquid's own lag and means nothing.
 *
 * A minute is compared only when it is fully covered: it starts after the coin's first
 * recorded trade (the initial snapshot cuts the first minute), and a trade of a later minute
 * has been recorded (the trades feed is ordered, so nothing of the minute is still due even
 * if its candle frame arrived before its last trades frame).
 */
final class HyperliquidTradeCountAudit
{
    public const INTERVAL_MILLISECONDS = 60_000;

    /** @var array<string, array<int, int>> coin => minute start => recorded rows */
    private array $rows = [];

    /** @var array<string, array<int, int>> coin => minute start => closed candle trade count */
    private array $candles = [];

    /** @var array<string, int> coin => time of the first recorded trade */
    private array $coverageStart = [];

    /** @var array<string, int> coin => time of the latest recorded trade */
    private array $latest = [];

    /** @var array<string, int> coin => start of the last settled minute */
    private array $settled = [];

    public function trade(string $coin, int $time): void
    {
        $minute = $time - $time % self::INTERVAL_MILLISECONDS;
        $this->coverageStart[$coin] = min($this->coverageStart[$coin] ?? $time, $time);
        $this->latest[$coin] = max($this->latest[$coin] ?? $time, $time);
        if ($minute <= ($this->settled[$coin] ?? -1)) {
            return;
        }
        $this->rows[$coin][$minute] = ($this->rows[$coin][$minute] ?? 0) + 1;
    }

    /** The closing state of a 1m candle, as recorded. */
    public function closedCandle(string $coin, int $startTime, int $tradeCount): void
    {
        if ($startTime <= ($this->settled[$coin] ?? -1)) {
            return;
        }
        $this->candles[$coin][$startTime] = $tradeCount;
    }

    /**
     * Compares every minute that is complete by now and forgets it.
     *
     * @return list<array{coin: string, minute_start: int, rows: int, candle_trade_count: int}>
     *     the minutes with fewer recorded trades than their candle counts
     */
    public function settle(): array
    {
        $holes = [];
        foreach ($this->candles as $coin => $counts) {
            ksort($counts);
            foreach ($counts as $start => $count) {
                $covered = isset($this->coverageStart[$coin], $this->latest[$coin])
                    && $start > $this->coverageStart[$coin]
                    && $this->latest[$coin] >= $start + self::INTERVAL_MILLISECONDS;
                if (!$covered) {
                    continue;
                }
                $rows = $this->rows[$coin][$start] ?? 0;
                if ($rows < $count) {
                    $holes[] = [
                        'coin' => $coin,
                        'minute_start' => $start,
                        'rows' => $rows,
                        'candle_trade_count' => $count,
                    ];
                }
                unset($this->candles[$coin][$start], $this->rows[$coin][$start]);
                $this->settled[$coin] = max($this->settled[$coin] ?? -1, $start);
            }
            // Row counters of minutes older than the settled one can never be compared anymore.
            foreach (array_keys($this->rows[$coin] ?? []) as $minute) {
                if ($minute <= ($this->settled[$coin] ?? -1)) {
                    unset($this->rows[$coin][$minute]);
                }
            }
        }

        return $holes;
    }
}
