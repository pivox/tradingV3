<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Market;

use App\Contract\Provider\Dto\KlineDto;

final class PaperKlineWindow
{
    /** @var array<int, KlineDto> */
    private array $klines = [];

    public function __construct(private readonly int $capacity = 500)
    {
        if ($capacity < 1 || $capacity > 500) {
            throw new \InvalidArgumentException('paper_kline_window_capacity_invalid');
        }
    }

    public function put(KlineDto $kline): void
    {
        $timestamp = (int) $kline->openTime->format('U');
        $existing = $this->klines[$timestamp] ?? null;
        if ($existing instanceof KlineDto) {
            if (!$this->equals($existing, $kline)) {
                throw new \LogicException('paper_market_candle_conflict');
            }

            return;
        }

        $this->klines[$timestamp] = $kline;
        ksort($this->klines, SORT_NUMERIC);
        while (count($this->klines) > $this->capacity) {
            array_shift($this->klines);
        }
    }

    /** @return list<KlineDto> */
    public function all(?int $limit = null): array
    {
        $klines = array_values($this->klines);
        if ($limit === null) {
            return $klines;
        }
        if ($limit < 1 || $limit > $this->capacity) {
            throw new \InvalidArgumentException('paper_kline_window_limit_invalid');
        }

        return array_slice($klines, -$limit);
    }

    public function last(): ?KlineDto
    {
        $last = end($this->klines);

        return $last instanceof KlineDto ? $last : null;
    }

    private function equals(KlineDto $left, KlineDto $right): bool
    {
        return $left->symbol === $right->symbol
            && $left->timeframe === $right->timeframe
            && $left->openTime == $right->openTime
            && (string) $left->open === (string) $right->open
            && (string) $left->high === (string) $right->high
            && (string) $left->low === (string) $right->low
            && (string) $left->close === (string) $right->close
            && (string) $left->volume === (string) $right->volume
            && $left->source === $right->source;
    }
}
