<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Market;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;

final class PaperKlineProvider
{
    /** @var array<string, PaperKlineWindow> */
    private array $windows = [];

    public function __construct(private readonly int $capacity = 500)
    {
        if ($capacity < 1 || $capacity > 500) {
            throw new \InvalidArgumentException('paper_kline_window_capacity_invalid');
        }
    }

    public function put(KlineDto $kline): void
    {
        $symbol = $this->symbol($kline->symbol);
        $this->assertTimeframe($kline->timeframe);
        $this->windows[$this->key($symbol, $kline->timeframe)] ??= new PaperKlineWindow($this->capacity);
        $this->windows[$this->key($symbol, $kline->timeframe)]->put($kline);
    }

    /** @return list<KlineDto> */
    public function getKlines(string $symbol, Timeframe $timeframe, ?int $limit = null): array
    {
        $this->assertTimeframe($timeframe);
        $key = $this->key($this->symbol($symbol), $timeframe);
        if (!isset($this->windows[$key])) {
            return [];
        }

        return $this->windows[$key]->all($limit);
    }

    public function getLastKline(string $symbol, Timeframe $timeframe): ?KlineDto
    {
        $this->assertTimeframe($timeframe);
        $key = $this->key($this->symbol($symbol), $timeframe);
        if (!isset($this->windows[$key])) {
            return null;
        }

        return $this->windows[$key]->last();
    }

    public function clear(): void
    {
        $this->windows = [];
    }

    private function key(string $symbol, Timeframe $timeframe): string
    {
        return $symbol . '/' . $timeframe->value;
    }

    private function symbol(string $symbol): string
    {
        if (!in_array($symbol, ['BTCUSDT', 'ETHUSDT'], true)) {
            throw new \InvalidArgumentException('paper_market_symbol_not_allowed');
        }

        return $symbol;
    }

    private function assertTimeframe(Timeframe $timeframe): void
    {
        if (!in_array($timeframe, [Timeframe::TF_1M, Timeframe::TF_5M, Timeframe::TF_15M, Timeframe::TF_1H], true)) {
            throw new \InvalidArgumentException('paper_market_timeframe_not_allowed');
        }
    }
}
