<?php

namespace App\Indicator\Context;

use App\Indicator\Momentum\Rsi;
use App\Indicator\Momentum\Macd;
use App\Indicator\Trend\Ema;
use App\Indicator\Trend\Adx;
use App\Indicator\Volume\Vwap;
use App\Indicator\AtrCalculator;

/**
 * Construit un tableau de contexte unifié pour les conditions.
 * Objectif : éviter duplication calcul RSI/MACD/EMA/VWAP/ATR et garantir clés cohérentes.
 * Fluent builder minimal : alimenter OHLCV puis build().
 */
class IndicatorContextBuilder
{
    private ?string $symbol = null;
    private ?string $timeframe = null;
    /** @var float[] */ private array $closes = [];
    /** @var float[] */ private array $highs = [];
    /** @var float[] */ private array $lows = [];
    /** @var float[] */ private array $volumes = [];
    /** @var array<int,array{high:float,low:float,close:float}> */ private array $ohlc = [];
    
    // Paramètres configurables pour les conditions
    private ?float $entryPrice = null;
    private ?float $stopLoss = null;
    private ?float $atrK = null;
    private ?float $minAtrPct = null;
    private ?float $maxAtrPct = null;
    private ?float $rsiLt70Threshold = null;
    private ?float $rsiCrossUpLevel = null;
    private ?float $rsiCrossDownLevel = null;
    private bool $traderAvailable;

    public function __construct(
        private readonly Rsi $rsi,
        private readonly Macd $macd,
        private readonly Ema $ema,
        private readonly Adx $adx,
        private readonly Vwap $vwap,
        private readonly AtrCalculator $atrCalc,
    ) {
        $this->traderAvailable = \extension_loaded('trader');
    }

    public function symbol(string $s): self { $this->symbol = $s; return $this; }
    public function timeframe(string $tf): self { $this->timeframe = $tf; return $this; }
    /** @param float[] $closes */
    public function closes(array $closes): self { $this->closes = array_map('floatval',$closes); return $this; }
    /** @param float[] $highs */
    public function highs(array $highs): self { $this->highs = array_map('floatval',$highs); return $this; }
    /** @param float[] $lows */
    public function lows(array $lows): self { $this->lows = array_map('floatval',$lows); return $this; }
    /** @param float[] $volumes */
    public function volumes(array $volumes): self { $this->volumes = array_map('floatval',$volumes); return $this; }
    /** @param array<int,array{high:float,low:float,close:float}> $ohlc */
    public function ohlc(array $ohlc): self { $this->ohlc = $ohlc; return $this; }
    
    // Méthodes pour configurer les paramètres des conditions
    public function entryPrice(?float $price): self { $this->entryPrice = $price; return $this; }
    public function stopLoss(?float $stop): self { $this->stopLoss = $stop; return $this; }
    public function atrK(?float $k): self { $this->atrK = $k; return $this; }
    public function minAtrPct(?float $pct): self { $this->minAtrPct = $pct; return $this; }
    public function maxAtrPct(?float $pct): self { $this->maxAtrPct = $pct; return $this; }
    public function rsiLt70Threshold(?float $threshold): self { $this->rsiLt70Threshold = $threshold; return $this; }
    public function rsiCrossUpLevel(?float $level): self { $this->rsiCrossUpLevel = $level; return $this; }
    public function rsiCrossDownLevel(?float $level): self { $this->rsiCrossDownLevel = $level; return $this; }
    
    /**
     * Configure les paramètres par défaut pour les conditions.
     * Utile pour initialiser rapidement avec des valeurs standard.
     */
    public function withDefaults(): self
    {
        return $this
            ->atrK(1.5)
            ->minAtrPct(0.001)  // 0.1%
            ->maxAtrPct(0.03)   // 3%
            ->rsiLt70Threshold(70.0)
            ->rsiCrossUpLevel(30.0)
            ->rsiCrossDownLevel(70.0);
    }

    /** Retourne le contexte prêt pour ConditionRegistry->evaluate(). */
    public function build(): array
    {
        $close = !empty($this->closes) ? (float) end($this->closes) : null;

        $rsiSeries = $this->computeRsiSeries($this->closes);
        $rsiVals = $rsiSeries['rsi'] ?? [];
        $rsi = $rsiVals ? (float) end($rsiVals) : null;
        $prevRsi = (count($rsiVals) > 1) ? (float) $rsiVals[count($rsiVals) - 2] : null;

        $macdFull = $this->computeMacdSeries($this->closes);
        $macdSeries = $macdFull['macd'] ?? [];
        $signalSeries = $macdFull['signal'] ?? [];
        $histSeries = $macdFull['hist'] ?? [];
        $macdVal = $macdSeries ? (float) end($macdSeries) : null;
        $signalVal = $signalSeries ? (float) end($signalSeries) : null;
        $histVal = $histSeries ? (float) end($histSeries) : null;
        $prevMacd = (count($macdSeries) > 1) ? (float) $macdSeries[count($macdSeries) - 2] : null;
        $prevSignal = (count($signalSeries) > 1) ? (float) $signalSeries[count($signalSeries) - 2] : null;
        $prevHist = (count($histSeries) > 1) ? (float) $histSeries[count($histSeries) - 2] : null;

        $emaPeriods = [9, 20, 21, 50, 200];
        $emaMap = [];
        $emaPrevMap = [];
        foreach ($emaPeriods as $p) {
            if (count($this->closes) >= $p) {
                [$emaCurrent, $emaPrevious] = $this->computeEmaPair($this->closes, $p);
                if ($emaCurrent !== null) {
                    $emaMap[$p] = $emaCurrent;
                }
                if ($emaPrevious !== null) {
                    $emaPrevMap[$p] = $emaPrevious;
                }
            }
        }

        $vwapVal = null;
        if ($this->highs && $this->lows && $this->closes && $this->volumes) {
            $vwapVal = $this->vwap->calculate($this->highs, $this->lows, $this->closes, $this->volumes);
        }

        $hlcSeries = $this->collectHlcSeries();
        $atr = null;
        if ($hlcSeries !== null) {
            $atr = $this->computeAtrValue(
                $hlcSeries['highs'],
                $hlcSeries['lows'],
                $hlcSeries['closes'],
                $this->ohlc
            );
        }

        $adxVal = null;
        if ($hlcSeries !== null) {
            $adxVal = $this->computeAdxValue(
                $hlcSeries['highs'],
                $hlcSeries['lows'],
                $hlcSeries['closes'],
                14
            );
        }

        $ema200Slope = null;
        if (isset($emaMap[200], $emaPrevMap[200])) {
            $ema200Slope = $emaMap[200] - $emaPrevMap[200];
        }

        $macdHistLast3 = null;
        $macdHistSeries = null;
        if ($histSeries) {
            $n = count($histSeries);
            $macdHistLast3 = [];
            for ($i = max(0, $n - 3); $i < $n; $i++) {
                $macdHistLast3[] = (float) $histSeries[$i];
            }
            $macdHistSeries = array_values(array_map('floatval', array_reverse($histSeries)));
        }

        return array_filter([
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'close' => $close,
            'ema' => $emaMap ?: null,
            'ema_prev' => $emaPrevMap ?: null,
            'ema_200_slope' => $ema200Slope,
            'rsi' => $rsi,
            'macd' => ($macdVal !== null && $signalVal !== null) ? [
                'macd' => $macdVal,
                'signal' => $signalVal,
                'hist' => $histVal,
            ] : null,
            'macd_hist_last3' => $macdHistLast3,
            'macd_hist_series' => $macdHistSeries,
            'vwap' => $vwapVal,
            'atr' => $atr,
            'adx' => $adxVal ? [14 => $adxVal] : null,
            'previous' => array_filter([
                'rsi' => $prevRsi,
                'macd' => ($prevMacd !== null && $prevSignal !== null) ? [
                    'macd' => $prevMacd,
                    'signal' => $prevSignal,
                    'hist' => $prevHist,
                ] : null,
            ]) ?: null,

            // Paramètres configurables pour les conditions
            'entry_price' => $this->entryPrice,
            'stop_loss' => $this->stopLoss,
            'atr_k' => $this->atrK,
            'k' => $this->atrK, // alias pour compatibilité
            'min_atr_pct' => $this->minAtrPct,
            'max_atr_pct' => $this->maxAtrPct,
            'rsi_lt_70_threshold' => $this->rsiLt70Threshold,
            'rsi_cross_up_level' => $this->rsiCrossUpLevel,
            'rsi_cross_down_level' => $this->rsiCrossDownLevel,
        ], fn($v) => $v !== null);
    }

    /**
     * @param float[] $closes
     * @return array{rsi: float[]}
     */
    private function computeRsiSeries(array $closes, int $period = 14): array
    {
        if ($this->traderAvailable && count($closes) >= $period) {
            try {
                $result = \trader_rsi($closes, $period);
                if ($result !== false) {
                    return ['rsi' => array_map('floatval', array_values($result))];
                }
            } catch (\Throwable) {
                // fallback to pure PHP implementation below
            }
        }

        return $this->rsi->calculateFull($closes, $period);
    }

    /**
     * @param float[] $closes
     * @return array{macd: float[], signal: float[], hist: float[]}
     */
    private function computeMacdSeries(array $closes, int $fast = 12, int $slow = 26, int $signal = 9): array
    {
        if ($this->traderAvailable && count($closes) >= $slow) {
            try {
                $result = \trader_macd($closes, $fast, $slow, $signal);
                if (is_array($result)) {
                    $macd = isset($result[0]) && is_array($result[0]) ? array_map('floatval', array_values($result[0])) : [];
                    $signalSeries = isset($result[1]) && is_array($result[1]) ? array_map('floatval', array_values($result[1])) : [];
                    $hist = isset($result[2]) && is_array($result[2]) ? array_map('floatval', array_values($result[2])) : [];

                    return [
                        'macd' => $macd,
                        'signal' => $signalSeries,
                        'hist' => $hist,
                    ];
                }
            } catch (\Throwable) {
                // fallback to pure PHP implementation below
            }
        }

        return $this->macd->calculateFull($closes, $fast, $slow, $signal);
    }

    /**
     * @param float[] $closes
     * @return array{0:?float,1:?float}
     */
    private function computeEmaPair(array $closes, int $period): array
    {
        if ($this->traderAvailable && count($closes) >= $period) {
            try {
                $series = \trader_ema($closes, $period);
                if ($series !== false) {
                    $series = array_map('floatval', array_values($series));
                    $current = $series ? (float) end($series) : null;
                    $previous = (count($series) > 1) ? (float) $series[count($series) - 2] : null;
                    return [$current, $previous];
                }
            } catch (\Throwable) {
                // fallback below
            }
        }

        if (count($closes) < $period) {
            return [null, null];
        }

        $current = (float) $this->ema->calculate($closes, $period);
        $previous = null;
        if (count($closes) >= $period + 1) {
            $prevCloses = $closes;
            array_pop($prevCloses);
            $previous = (float) $this->ema->calculate($prevCloses, $period);
        }

        return [$current, $previous];
    }

    /**
     * @return array{highs: float[], lows: float[], closes: float[]}|null
     */
    private function collectHlcSeries(): ?array
    {
        if ($this->ohlc) {
            $highs = [];
            $lows = [];
            $closes = [];
            foreach ($this->ohlc as $row) {
                $highs[] = (float) $row['high'];
                $lows[] = (float) $row['low'];
                $closes[] = (float) $row['close'];
            }

            return ['highs' => $highs, 'lows' => $lows, 'closes' => $closes];
        }

        $n = min(count($this->highs), count($this->lows), count($this->closes));
        if ($n === 0) {
            return null;
        }

        $highs = array_map('floatval', array_slice($this->highs, 0, $n));
        $lows = array_map('floatval', array_slice($this->lows, 0, $n));
        $closes = array_map('floatval', array_slice($this->closes, 0, $n));

        return ['highs' => $highs, 'lows' => $lows, 'closes' => $closes];
    }

    /**
     * @param float[] $highs
     * @param float[] $lows
     * @param float[] $closes
     * @param array<int,array{high:float,low:float,close:float}>|null $ohlc
     */
    private function computeAtrValue(
        array $highs,
        array $lows,
        array $closes,
        ?array $ohlc,
        int $period = 14
    ): ?float {
        if (
            $this->traderAvailable
            && count($highs) >= $period
            && count($lows) >= $period
            && count($closes) >= $period
        ) {
            try {
                $result = \trader_atr($highs, $lows, $closes, $period);
                if ($result !== false) {
                    $series = array_values($result);
                    if ($series !== []) {
                        return (float) end($series);
                    }
                }
            } catch (\Throwable) {
                // fallback to ATR calculator below
            }
        }

        if ($ohlc !== null) {
            try {
                return $this->atrCalc->compute($ohlc, $period);
            } catch (\Throwable) {
                return null;
            }
        }

        $n = min(count($highs), count($lows), count($closes));
        if ($n > 0) {
            $tmp = [];
            for ($i = 0; $i < $n; $i++) {
                $tmp[] = [
                    'high' => (float) $highs[$i],
                    'low' => (float) $lows[$i],
                    'close' => (float) $closes[$i],
                ];
            }

            try {
                return $this->atrCalc->compute($tmp, $period);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param float[] $highs
     * @param float[] $lows
     * @param float[] $closes
     */
    private function computeAdxValue(array $highs, array $lows, array $closes, int $period = 14): ?float
    {
        if (
            $this->traderAvailable
            && count($highs) >= $period
            && count($lows) >= $period
            && count($closes) >= $period
        ) {
            try {
                $result = \trader_adx($highs, $lows, $closes, $period);
                if ($result !== false) {
                    $series = array_values($result);
                    if ($series !== []) {
                        return (float) end($series);
                    }
                }
            } catch (\Throwable) {
                // fallback to PHP implementation below
            }
        }

        if (
            count($highs) < $period
            || count($lows) < $period
            || count($closes) < $period
        ) {
            return null;
        }

        try {
            return $this->adx->calculate($highs, $lows, $closes, $period);
        } catch (\Throwable) {
            return null;
        }
    }
}
