<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Research;

use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorNumericSeries;
use Brick\Math\BigDecimal;

final class ResearchRollingWindows
{
    public const STEPS = ['1m' => 60000, '5m' => 300000, '15m' => 900000, '1h' => 3600000, '4h' => 14400000];
    /** @var array<string, list<array<string, mixed>>> */
    private array $windows = ['1m' => [], '5m' => [], '15m' => [], '1h' => [], '4h' => []];
    /** @var array<string, array<string, mixed>> */
    private array $pending = [];
    private int $nextMs;

    public function __construct(private readonly string $symbol, int $startMs, private readonly int $endMs)
    {
        if (!in_array($symbol, ResearchCandle::SYMBOLS, true) || $startMs < 0 || $startMs % 60000 !== 0 || $endMs <= $startMs || $endMs % 60000 !== 0) {
            throw new \InvalidArgumentException('research_window_binding_invalid');
        }
        $this->nextMs = $startMs;
    }

    public function append(ResearchCandle $candle): void
    {
        if ($candle->symbol !== $this->symbol) { throw new \InvalidArgumentException('research_candle_symbol_mismatch'); }
        if ($candle->openMs !== $this->nextMs) { throw new \InvalidArgumentException('research_candle_chronology_invalid'); }
        if ($candle->openMs + 60000 > $this->endMs) { throw new \InvalidArgumentException('research_candle_future_availability'); }
        $this->push('1m', $candle->bar());
        $this->nextMs += 60000;
    }

    /** @param array<string, mixed> $bar */
    private function push(string $timeframe, array $bar): void
    {
        $this->windows[$timeframe][] = $bar;
        if (count($this->windows[$timeframe]) > 250) { array_shift($this->windows[$timeframe]); }
        $next = match ($timeframe) { '1m' => '5m', '5m' => '15m', '15m' => '1h', '1h' => '4h', default => null };
        if ($next === null) { return; }
        $step = self::STEPS[$next];
        if (!isset($this->pending[$next])) {
            if ($bar['open_ms'] % $step !== 0) { return; } // An incomplete leading UTC bucket is never emitted.
            $this->pending[$next] = $bar;
        } else {
            $current = $this->pending[$next];
            $current['high'] = (string) BigDecimal::of($current['high'])->max(BigDecimal::of($bar['high']));
            $current['low'] = (string) BigDecimal::of($current['low'])->min(BigDecimal::of($bar['low']));
            $current['volume'] = (string) BigDecimal::of($current['volume'])->plus($bar['volume'])->stripTrailingZeros();
            $current['close'] = $bar['close'];
            $current['available_ms'] = $bar['available_ms'];
            $current['source_record_id'] = hash('sha256', $current['source_record_id'] . ':' . $bar['source_record_id']);
            $this->pending[$next] = $current;
        }
        if ($bar['available_ms'] % $step === 0) {
            $closed = $this->pending[$next]; unset($this->pending[$next]);
            $this->push($next, $closed);
        }
    }

    /** @return list<array<string, mixed>> */
    public function bars(string $timeframe): array
    {
        return $this->windows[$timeframe] ?? throw new \InvalidArgumentException('research_timeframe_invalid');
    }

    public function ready(): bool
    {
        foreach ($this->windows as $bars) { if (count($bars) !== 250) { return false; } }
        return true;
    }

    public function numericSeries(string $timeframe): CanonicalIndicatorNumericSeries
    {
        $opens = $highs = $lows = $closes = $volumes = $timestamps = [];
        foreach ($this->bars($timeframe) as $bar) {
            $opens[] = (float) $bar['open']; $highs[] = (float) $bar['high']; $lows[] = (float) $bar['low'];
            $closes[] = (float) $bar['close']; $volumes[] = (float) $bar['volume']; $timestamps[] = intdiv($bar['open_ms'], 1000);
        }
        return new CanonicalIndicatorNumericSeries($opens, $highs, $lows, $closes, $volumes, $timestamps);
    }
}
