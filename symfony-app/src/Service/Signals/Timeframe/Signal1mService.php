<?php
// src/Service/Signals/Timeframe/Signal1mService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Volatility\Donchian;
use App\Service\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

// adaptez le namespace selon votre projet

/**
 * Service de signal pour le timeframe 1m (micro-exécution / timing d'entrée).
 *
 * Conformité YML v1.2 (branche 1m) :
 *  - LONG  : trigger = breakout_above_donchian_high  OR  retest_vwap_bullish_wick
 *  - SHORT : trigger = breakdown_below_donchian_low  OR  retest_vwap_bearish_wick
 *
 * Hypothèses :
 *  - Le contexte MTF (4H/1H) et l’exécution 15m/5m ont déjà validé la direction.
 *  - Ici on ne fait que le TRIGGER 1m, sans filtres additionnels.
 */
final class Signal1mService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Donchian $donchian,
        private Vwap $vwap,
        private TradingParameters $params,

        // Défauts cohérents avec la logique 1m
        private float $defaultEps              = 1.0e-6,
        private bool  $defaultUseLastClosed    = true,
        private int   $defaultMinBars          = 220,
        private int   $defaultDonchianPeriod   = 20,
        private string $defaultVwapSession     = 'daily',
        // Détection retest VWAP
        private float $defaultVwapTolBps       = 8.0,   // tolérance ±8 bps autour du VWAP
        private float $defaultMinWickRatio     = 0.50   // mèche ≥ 50% de la range (rejet franc)
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne → récente)
     * @return array{
     *   donchian: array{upper:float,lower:float,mid:float,upper_prev:float,lower_prev:float},
     *   vwap: float,
     *   close: float,
     *   wick: array{upper:float,lower:float,body:float,range:float,lower_ratio:float,upper_ratio:float},
     *   trigger: string,
     *   signal: string,
     *   path: string,
     *   status?: string
     * }
     */
    public function evaluate(array $candles): array
    {
        // 1) Config YAML (défensive)
        $cfg = $this->params->getConfig();

        $eps           = $cfg['runtime']['eps']              ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed']  ?? $this->defaultUseLastClosed;

        $minBars       = $cfg['timeframes']['1m']['guards']['min_bars'] ?? $this->defaultMinBars;

        $donchianPeriod = $cfg['indicators']['donchian']['period'] ?? $this->defaultDonchianPeriod; // 20
        $vwapSession    = $cfg['indicators']['vwap']['session']     ?? $this->defaultVwapSession;    // daily

        $vwapTolBps     = $cfg['timeframes']['1m']['triggers']['vwap_tol_bps']    ?? $this->defaultVwapTolBps;
        $minWickRatio   = $cfg['timeframes']['1m']['triggers']['min_wick_ratio']  ?? $this->defaultMinWickRatio;

        // 2) Garde-fou data
        if (count($candles) < $minBars) {
            $validation =  [
                'donchian' => ['upper'=>0.0,'lower'=>0.0,'mid'=>0.0,'upper_prev'=>0.0,'lower_prev'=>0.0],
                'vwap'     => 0.0,
                'close'    => 0.0,
                'wick'     => ['upper'=>0.0,'lower'=>0.0,'body'=>0.0,'range'=>0.0,'lower_ratio'=>0.0,'upper_ratio'=>0.0],
                'path'     => 'execution_1m',
                'trigger'  => '',
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];

            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;
        }

        // 3) Séries OHLCV
        $opens   = array_map(fn(Kline $k) => $k->getOpen(),   $candles);
        $closes  = array_map(fn(Kline $k) => $k->getClose(),  $candles);
        $highs   = array_map(fn(Kline $k) => $k->getHigh(),   $candles);
        $lows    = array_map(fn(Kline $k) => $k->getLow(),    $candles);
        $volumes = array_map(fn(Kline $k) => $k->getVolume(), $candles);

        // 4) Référence : dernier close clôturé si demandé
        $idxLast = count($candles) - 1;
        $idxUse  = $idxLast;
        if ($useLastClosed && $idxLast > 0) {
            $idxUse = $idxLast - 1;
        }

        $open  = (float) $opens[$idxUse];
        $close = (float) $closes[$idxUse];
        $high  = (float) $highs[$idxUse];
        $low   = (float) $lows[$idxUse];

        // 5) Donchian (on a besoin de la borne "précédente" pour valider un breakout propre)
        //    -> upper_prev = upper(N-1) = plus haut des N barres précédant la barre utilisée
        //    -> lower_prev = lower(N-1) = plus bas des N barres précédant la barre utilisée
        $dc = $this->donchian->calculateSeries($highs, $lows, $donchianPeriod); // attendez : implémentez calculateSeries qui renvoie arrays 'upper','lower'
        $dcUpper = 0.0; $dcLower = 0.0; $dcMid = 0.0; $dcUpperPrev = 0.0; $dcLowerPrev = 0.0;

        if (is_array($dc) && !empty($dc['upper']) && !empty($dc['lower'])) {
            $dcUpper = (float) $dc['upper'][$idxUse] ?? 0.0;
            $dcLower = (float) $dc['lower'][$idxUse] ?? 0.0;
            $dcMid   = ($dcUpper + $dcLower) / 2.0;

            // bornes "précédentes" : on exclut la barre utilisée
            $idxPrev = $idxUse - 1;
            if ($idxPrev >= 0) {
                $dcUpperPrev = (float) $dc['upper'][$idxPrev] ?? $dcUpper;
                $dcLowerPrev = (float) $dc['lower'][$idxPrev] ?? $dcLower;
            } else {
                $dcUpperPrev = $dcUpper;
                $dcLowerPrev = $dcLower;
            }
        }

        // 6) VWAP (session = daily)
        $vwapVal = 0.0;
        if (method_exists($this->vwap, 'calculateSession')) {
            $vwapVal = (float) $this->vwap->calculateSession(array_slice($candles, 0, $idxUse + 1), $vwapSession);
        } elseif (method_exists($this->vwap, 'calculateFull')) {
            $v = $this->vwap->calculateFull($candles, $vwapSession);
            $vwapVal = is_array($v) && isset($v[$idxUse]) ? (float) $v[$idxUse] : (float) (is_array($v) && !empty($v) ? end($v) : 0.0);
        } elseif (method_exists($this->vwap, 'calculate')) {
            $vwapVal = (float) $this->vwap->calculate(array_slice($closes, 0, $idxUse + 1), array_slice($volumes, 0, $idxUse + 1), $vwapSession);
        }

        // 7) Métriques de mèche (wick)
        $body  = max(abs($close - $open), $eps);
        $range = max($high - $low, $eps);
        $upperWick = max($high - max($open, $close), 0.0);
        $lowerWick = max(min($open, $close) - $low, 0.0);
        $lowerRatio = $lowerWick / $range;
        $upperRatio = $upperWick / $range;

        // 8) Déclencheurs 1m (YML)
        // Breakout Donchian (on compare au "prev" pour éviter un faux signal intra-range)
        $breakoutAboveDonchianHigh = ($close > $dcUpperPrev + $eps);
        $breakdownBelowDonchianLow = ($close < $dcLowerPrev - $eps);

        // Retest VWAP avec mèche de rejet
        $tol = $vwapVal * ($vwapTolBps / 10000.0);
        $nearOrCrossBelowVwap = ($low <= $vwapVal + $tol);   // touche ou traverse légèrement par le bas
        $nearOrCrossAboveVwap = ($high >= $vwapVal - $tol);  // touche ou traverse légèrement par le haut

        $bullishRetestVwap = $nearOrCrossBelowVwap && ($close > $vwapVal + $eps) && ($lowerRatio >= $minWickRatio - $eps);
        $bearishRetestVwap = $nearOrCrossAboveVwap && ($close < $vwapVal - $eps) && ($upperRatio >= $minWickRatio - $eps);

        // 9) Décision
        $signal = 'NONE';
        $trigger = '';
        $path = 'execution_1m';

        if ($breakoutAboveDonchianHigh || $bullishRetestVwap) {
            $signal  = 'LONG';
            $trigger = $breakoutAboveDonchianHigh ? 'breakout_above_donchian_high' : 'retest_vwap_bullish_wick';
        } elseif ($breakdownBelowDonchianLow || $bearishRetestVwap) {
            $signal  = 'SHORT';
            $trigger = $breakdownBelowDonchianLow ? 'breakdown_below_donchian_low' : 'retest_vwap_bearish_wick';
        }

        // 10) Retour
        $validation = [
            'donchian' => [
                'upper'      => $dcUpper,
                'lower'      => $dcLower,
                'mid'        => $dcMid,
                'upper_prev' => $dcUpperPrev,
                'lower_prev' => $dcLowerPrev,
            ],
            'vwap' => $vwapVal,
            'close' => $close,
            'wick' => [
                'upper'       => $upperWick,
                'lower'       => $lowerWick,
                'body'        => $body,
                'range'       => $range,
                'lower_ratio' => $lowerRatio,
                'upper_ratio' => $upperRatio,
            ],
            'trigger' => $trigger,
            'signal'  => $signal,
            'path'    => $path,
        ];

        $this->signalsLogger->info('signals.tick', $validation);
        if ($signal == 'NONE') {
            $this->validationLogger->warning('validation.violation', $validation);
        } else {
            $this->validationLogger->info('validation.ok', $validation);
        }

        return $validation;
    }
}
