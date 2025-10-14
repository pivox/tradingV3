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

    public function __construct(
        private readonly Rsi $rsi,
        private readonly Macd $macd,
        private readonly Ema $ema,
        private readonly Adx $adx,
        private readonly Vwap $vwap,
        private readonly AtrCalculator $atrCalc,
    ) {}

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

        // RSI full pour récupérer valeur précédente
        $rsiSeries = $this->rsi->calculateFull($this->closes);
        $rsiVals = $rsiSeries['rsi'] ?? [];
        $rsi = $rsiVals ? (float) end($rsiVals) : null;
        $prevRsi = (count($rsiVals) > 1) ? (float) $rsiVals[count($rsiVals)-2] : null;

        // MACD full pour valeur précédente
        $macdFull = $this->macd->calculateFull($this->closes);
        $macdSeries = $macdFull['macd'] ?? [];
        $signalSeries = $macdFull['signal'] ?? [];
        $histSeries = $macdFull['hist'] ?? [];
        $macdVal = $macdSeries ? (float) end($macdSeries) : null;
        $signalVal = $signalSeries ? (float) end($signalSeries) : null;
        $histVal = $histSeries ? (float) end($histSeries) : null;
        $prevMacd = (count($macdSeries) > 1) ? (float) $macdSeries[count($macdSeries)-2] : null;
        $prevSignal = (count($signalSeries) > 1) ? (float) $signalSeries[count($signalSeries)-2] : null;
        $prevHist = (count($histSeries) > 1) ? (float) $histSeries[count($histSeries)-2] : null;

        // EMA multi périodes
        $emaPeriods = [20,50,200];
        $emaMap = [];
        foreach ($emaPeriods as $p) {
            if (count($this->closes) >= $p) {
                $emaMap[$p] = $this->ema->calculate($this->closes, $p);
            }
        }

        // VWAP dernière valeur
        $vwapVal = null;
        if ($this->highs && $this->lows && $this->closes && $this->volumes) {
            $vwapVal = $this->vwap->calculate($this->highs, $this->lows, $this->closes, $this->volumes);
        }

        // ATR
        $atr = null;
        if ($this->ohlc) {
            try {
                $atr = $this->atrCalc->compute($this->ohlc);
            } catch (\Throwable) {
                $atr = null; // insuffisant ou paramètres invalides
            }
        } else {
            // fallback: reconstruire ohlc si on a highs/lows/closes alignés
            $n = min(count($this->highs), count($this->lows), count($this->closes));
            if ($n > 0) {
                $tmp = [];
                for ($i=0;$i<$n;$i++) $tmp[] = ['high'=>$this->highs[$i],'low'=>$this->lows[$i],'close'=>$this->closes[$i]];
                try { $atr = $this->atrCalc->compute($tmp); } catch (\Throwable) { $atr = null; }
            }
        }

        // ADX
        $adxVal = null;
        if ($this->highs && $this->lows && $this->closes && count($this->closes) >= 14) {
            try {
                $adxVal = $this->adx->calculate($this->highs, $this->lows, $this->closes, 14);
            } catch (\Throwable) {
                $adxVal = null;
            }
        }

        return array_filter([
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'close' => $close,
            'ema' => $emaMap ?: null,
            'rsi' => $rsi,
            'macd' => ($macdVal !== null && $signalVal !== null) ? [
                'macd' => $macdVal,
                'signal' => $signalVal,
                'hist' => $histVal,
            ] : null,
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
}
