<?php
// src/Service/Signals/Timeframe/Signal1mService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

/**
 * Micro-structure 1m (affinage d'entrée/gestion).
 *
 * Idée simple et robuste : alignement EMA/VWAP en faveur du contexte.
 *  - LONG  : ema_20 > ema_50 && close > vwap
 *  - SHORT : ema_20 < ema_50 && close < vwap
 *
 * (On laisse de côté les figures/wicks complexes pour rester stable.)
 */
final class Signal1mService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Ema $ema,
        private Vwap $vwap,
        private TradingParameters $params,
        // Defaults
        private float $defaultEps           = 1.0e-6,
        private bool  $defaultUseLastClosed = true,
        private int   $defaultMinBars       = 120,
        private int   $defaultEmaFastPeriod = 20,
        private int   $defaultEmaSlowPeriod = 50,
        private string $defaultVwapSession  = 'daily'
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne -> récente)
     * @return array{
     *   ema_fast: float,
     *   ema_slow: float,
     *   vwap: float,
     *   close: float,
     *   path: string,
     *   trigger: string,
     *   signal: string,
     *   status?: string
     * }
     */
    public function evaluate(array $candles): array
    {
        $cfg = $this->params->getConfig();

        $eps           = $cfg['runtime']['eps']             ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed'] ?? $this->defaultUseLastClosed;
        $minBars       = $cfg['timeframes']['1m']['guards']['min_bars'] ?? $this->defaultMinBars;

        $emaFastPeriod = $cfg['indicators']['ema']['fast']  ?? $this->defaultEmaFastPeriod; // 20
        $emaSlowPeriod = $cfg['indicators']['ema']['slow']  ?? $this->defaultEmaSlowPeriod; // 50

        $vwapSession   = $cfg['indicators']['vwap']['session'] ?? $this->defaultVwapSession;

        if (count($candles) < $minBars) {
            $validation = [
                'ema_fast' => 0.0,
                'ema_slow' => 0.0,
                'vwap'     => 0.0,
                'close'    => 0.0,
                'path'     => 'micro_1m',
                'trigger'  => '',
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];
            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;
        }

        $closes  = array_map(fn(Kline $k) => $k->getClose(),  $candles);
        $volumes = array_map(fn(Kline $k) => $k->getVolume(), $candles);

        $emaFast = $this->ema->calculate($closes, $emaFastPeriod);
        $emaSlow = $this->ema->calculate($closes, $emaSlowPeriod);

        // APRÈS (à coller)
        $timestamps = array_map(fn(Kline $k) => $k->getTimestamp(), $candles);
        $highs      = array_map(fn(Kline $k) => $k->getHigh(),      $candles);
        $lows       = array_map(fn(Kline $k) => $k->getLow(),       $candles);
// $closes et $volumes existent déjà plus haut

        $vwapVal = 0.0;
        if (strtolower((string)$vwapSession) === 'daily') {
            // VWAP journalier (reset chaque jour)
            if (method_exists($this->vwap, 'calculateLastDailyWithTimestamps')) {
                $vwapVal = (float) $this->vwap->calculateLastDailyWithTimestamps(
                    $timestamps, $highs, $lows, $closes, $volumes, 'UTC'
                );
            } else {
                // fallback: calcul plein puis dernier point
                if (method_exists($this->vwap, 'calculateFull')) {
                    $series = $this->vwap->calculateFull($highs, $lows, $closes, $volumes);
                    $vwapVal = !empty($series) ? (float) end($series) : 0.0;
                } elseif (method_exists($this->vwap, 'calculate')) {
                    $vwapVal = (float) $this->vwap->calculate($highs, $lows, $closes, $volumes);
                }
            }
        } else {
            // Session non journalière : on prend le VWAP "classique" (cumulé)
            if (method_exists($this->vwap, 'calculateFull')) {
                $series = $this->vwap->calculateFull($highs, $lows, $closes, $volumes);
                $vwapVal = !empty($series) ? (float) end($series) : 0.0;
            } elseif (method_exists($this->vwap, 'calculate')) {
                $vwapVal = (float) $this->vwap->calculate($highs, $lows, $closes, $volumes);
            }
        }

        $lastClose = (float) end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        $emaUp   = ($emaFast > $emaSlow + $eps);
        $emaDown = ($emaSlow > $emaFast + $eps);

        $closeAboveVwap = ($lastClose > $vwapVal + $eps);
        $closeBelowVwap = ($lastClose < $vwapVal - $eps);

        $signal = 'NONE';
        $trigger = '';
        $path = 'micro_1m';

        if ($emaUp && $closeAboveVwap) {
            $signal  = 'LONG';
            $trigger = 'ema_fast_gt_slow & close_above_vwap';
        } elseif ($emaDown && $closeBelowVwap) {
            $signal  = 'SHORT';
            $trigger = 'ema_fast_lt_slow & close_below_vwap';
        }

        $validation = [
            'ema_fast' => $emaFast,
            'ema_slow' => $emaSlow,
            'vwap'     => $vwapVal,
            'close'    => $lastClose,
            'path'     => $path,
            'trigger'  => $trigger,
            'signal'   => $signal,
        ];

        $this->signalsLogger->info('signals.tick', $validation);
        if ($signal === 'NONE') {
            $this->validationLogger->warning('validation.violation', $validation);
        } else {
            $this->validationLogger->info('validation.ok', $validation);
        }
        return $validation;
    }
}
