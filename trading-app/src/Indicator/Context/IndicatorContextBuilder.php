<?php

namespace App\Indicator\Context;

use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volume\Vwap;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        private readonly Sma $sma,
        #[Autowire(service: 'monolog.logger.indicators')] private readonly ?LoggerInterface $indicatorLogger = null,
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
        // Sanity check for invalid close series (all non-positive)
        if (!empty($this->closes)) {
            $maxClose = max($this->closes);
            $minClose = min($this->closes);
            if ($maxClose <= 0.0 && $minClose <= 0.0) {
                throw new \RuntimeException('Invalid closes series: all values are non-positive');
            }
        }

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
            // Pour calculer la pente (EMA actuelle - EMA précédente), il faut au moins period + 1 bougies
            // Cela garantit qu'on peut calculer à la fois l'EMA actuelle et l'EMA précédente
//            if ($p == 200 ) {
//                die(count($this->closes) . ' ' . $p);
//            }
            if (count($this->closes) >= $p + 1) {
                [$emaCurrent, $emaPrevious] = $this->computeEmaPair($this->closes, $p);
                if ($emaCurrent !== null) {
                    $emaMap[$p] = $emaCurrent;
                }
                if ($emaPrevious !== null) {
                    $emaPrevMap[$p] = $emaPrevious;
                }
            }
        }

        // Warn if EMA9 ~ 0 while close > 0 (suspect data)
        if ($this->indicatorLogger && isset($emaMap[9]) && is_float($emaMap[9]) && abs($emaMap[9]) < 1.0e-12 && is_float($close) && $close > 0.0) {
            $this->indicatorLogger->warning('EMA9 is approximately zero with positive close; data may be invalid', [
                'symbol' => $this->symbol,
                'timeframe' => $this->timeframe,
                'close' => $close,
                'ema9' => $emaMap[9],
            ]);
        }

        $vwapVal = null;
        if ($this->highs && $this->lows && $this->closes && $this->volumes) {
            $vwapVal = $this->vwap->calculate($this->highs, $this->lows, $this->closes, $this->volumes);
        }

        $volumeRatio = $this->computeVolumeRatio($this->volumes);

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

        $adxVal14 = null;
        $adxVal15 = null;
        if ($hlcSeries !== null) {
            $adxVal14 = $this->computeAdxValue(
                $hlcSeries['highs'],
                $hlcSeries['lows'],
                $hlcSeries['closes'],
                14
            );
            $adxVal15 = $this->computeAdxValue(
                $hlcSeries['highs'],
                $hlcSeries['lows'],
                $hlcSeries['closes'],
                15
            );
        }

        // SMA 21 & niveaux MA21 + k*ATR pour les règles price_lte_ma21_plus_k_atr / price_below_ma21_plus_2atr
        $ma21 = null;
        if (!empty($this->closes)) {
            try {
                $ma21 = $this->sma->calculate($this->closes, 21);
            } catch (\Throwable) {
                $ma21 = null;
            }
        }

        $ma21PlusKAtr = null;
        $ma21Plus13Atr = null;
        $ma21Plus15Atr = null;
        $ma21Plus2Atr = null;
        if ($ma21 !== null && $atr !== null) {
            $k = $this->atrK ?? 1.3;
            $ma21PlusKAtr = $ma21 + ($k * $atr);
            $ma21Plus13Atr = $ma21 + (1.3 * $atr);
            $ma21Plus15Atr = $ma21 + (1.5 * $atr);
            $ma21Plus2Atr = $ma21 + (2.0 * $atr);
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
            'volume_ratio' => $volumeRatio,
            'atr' => $atr,
            'ma21' => $ma21,
            'ma_21_plus_k_atr' => $ma21PlusKAtr,
            'ma_21_plus_1.3atr' => $ma21Plus13Atr,
            'ma_21_plus_1.5atr' => $ma21Plus15Atr,
            'ma_21_plus_2atr' => $ma21Plus2Atr,
            'adx' => $adxVal14 ? [14 => $adxVal14, 15 => $adxVal15] : null,
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
        // Utiliser Macd::calculateFull() qui gère déjà le fallback trader → PHP
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
                    // Some implementations return nulls for warm-up slots. We need the last non-null numeric.
                    $vals = array_values($series);
                    $current = null; $previous = null;
                    for ($i = count($vals) - 1; $i >= 0; $i--) {
                        if (is_numeric($vals[$i])) {
                            $current = (float)$vals[$i];
                            for ($j = $i - 1; $j >= 0; $j--) {
                                if (is_numeric($vals[$j])) { $previous = (float)$vals[$j]; break; }
                            }
                            break;
                        }
                    }
                    if ($current !== null) {
                        // Sanity: some drivers may yield 0.0 spuriously on very small-priced symbols.
                        if ($current === 0.0) {
                            // Fall back to pure-PHP EMA to avoid zero artefact
                            $current = $this->computeEmaPure($closes, $period);
                            if (count($closes) >= $period + 1) {
                                $prevCloses = $closes;
                                array_pop($prevCloses);
                                $previous = $this->computeEmaPure($prevCloses, $period);
                            }
                        }
                        return [$current, $previous];
                    }
                    // Fall through to pure-PHP below if no numeric value found
                }
            } catch (\Throwable) {
                // fallback below
            }
        }

        if (count($closes) < $period) {
            return [null, null];
        }

        // If the series is essentially zero/non-informative, return nulls
        $max = max($closes);
        $min = min($closes);
        if ($max <= 1.0e-18 && $min >= -1.0e-18) {
            return [null, null];
        }

        $current = $this->computeEmaPure($closes, $period);
        $previous = null;
        if (count($closes) >= $period + 1) {
            $prevCloses = $closes;
            array_pop($prevCloses);
            $previous = $this->computeEmaPure($prevCloses, $period);
        }

        return [$current, $previous];
    }

    /**
     * Pure-PHP EMA (bypass TRADER) with simple recursive smoothing seeded by first price.
     */
    private function computeEmaPure(array $closes, int $period): float
    {
        $n = count($closes);
        if ($n === 0) return 0.0;
        if ($period <= 1) return (float) end($closes);
        $k = 2 / ($period + 1);
        $ema = (float) $closes[0];
        for ($i = 1; $i < $n; $i++) {
            $price = (float) $closes[$i];
            $ema = $price * $k + $ema * (1 - $k);
        }
        return (float) $ema;
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
        // Utiliser AtrCalculator qui gère déjà le fallback trader → PHP
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

    /**
     * Calcule le ratio volume actuel / moyenne mobile adaptative des volumes.
     * Logique adaptative :
     * - Si < 3 klines → null (pas assez de données)
     * - Si 3 ≤ klines < 20 → SMA partiel (moyenne des volumes disponibles, sans la dernière)
     * - Si ≥ 20 → SMA20 normal (moyenne des 20 dernières, sans la dernière)
     *
     * @param float[] $volumes
     */
    private function computeVolumeRatio(array $volumes): ?float
    {
        $count = count($volumes);

        if ($count < 3) {
            return null; // pas de data utilisable
        }

        // Volume actuel (dernière kline)
        $currentVol = end($volumes);
        if ($currentVol === null || $currentVol <= 0.0) {
            return null;
        }

        // Prendre les volumes passés (toutes les klines sauf la dernière)
        $pastVolumes = array_slice($volumes, 0, -1);
        if (empty($pastVolumes)) {
            return null;
        }

        // Nombre de périodes disponible pour la moyenne (adaptatif : min entre 20 et le nombre de volumes passés)
        $window = min(20, count($pastVolumes));
        if ($window < 1) {
            return null;
        }

        // Prendre les N dernières volumes passés pour le calcul de la moyenne
        $windowVolumes = array_slice($pastVolumes, -$window);
        if (empty($windowVolumes)) {
            return null;
        }

        // Utiliser Sma service pour calculer la moyenne mobile
        $avgVol = $this->sma->calculate($windowVolumes, $window);
        if ($avgVol === null || $avgVol <= 0.0) {
            return null;
        }

        return $currentVol / $avgVol;
    }
}
