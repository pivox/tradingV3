<?php
// src/Service/Signals/Timeframe/Signal15mService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Momentum\Macd;
use App\Service\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

/**
 * Exécution 15m (plus propre que 5m, moins de bruit).
 *
 * YML scalping (lecture minimale, sûre) :
 *  - LONG  : ema_20 > ema_50 && macd_hist > 0 && close > vwap(daily)
 *  - SHORT : ema_20 < ema_50 && macd_hist < 0 && close < vwap(daily)
 *
 * NB: Donchian/StochRSI/Choppiness peuvent être ajoutés plus tard de façon optionnelle.
 */
final class Signal15mService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Ema $ema,
        private Macd $macd,
        private Vwap $vwap,
        private TradingParameters $params,
        // Defaults
        private float $defaultEps           = 1.0e-6,
        private bool  $defaultUseLastClosed = true,
        private int   $defaultMinBars       = 220,
        private int   $defaultEmaFastPeriod = 20,
        private int   $defaultEmaSlowPeriod = 50,
        private int   $defaultMacdFast      = 12,
        private int   $defaultMacdSlow      = 26,
        private int   $defaultMacdSignal    = 9,
        private string $defaultVwapSession  = 'daily'
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne -> récente)
     * @return array{
     *   ema_fast: float,
     *   ema_slow: float,
     *   macd: array{macd:float,signal:float,hist:float},
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
        $minBars       = $cfg['timeframes']['15m']['guards']['min_bars'] ?? $this->defaultMinBars;

        $emaFastPeriod = $cfg['indicators']['ema']['fast']  ?? $this->defaultEmaFastPeriod; // 20
        $emaSlowPeriod = $cfg['indicators']['ema']['slow']  ?? $this->defaultEmaSlowPeriod; // 50

        $macdFast   = $cfg['indicators']['macd']['fast']   ?? $this->defaultMacdFast;
        $macdSlow   = $cfg['indicators']['macd']['slow']   ?? $this->defaultMacdSlow;
        $macdSignal = $cfg['indicators']['macd']['signal'] ?? $this->defaultMacdSignal;

        $vwapSession = $cfg['indicators']['vwap']['session'] ?? $this->defaultVwapSession;

        if (count($candles) < $minBars) {
            $validation = [
                'ema_fast' => 0.0,
                'ema_slow' => 0.0,
                'macd'     => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0],
                'vwap'     => 0.0,
                'close'    => 0.0,
                'path'     => 'execution_15m',
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

        $macdNow = 0.0; $sigNow = 0.0; $histNow = 0.0;
        if (method_exists($this->macd, 'calculateFull')) {
            $m = $this->macd->calculateFull($closes, $macdFast, $macdSlow, $macdSignal);
            if (!empty($m['macd']) && !empty($m['signal'])) {
                $macdNow = (float) end($m['macd']);
                $sigNow  = (float) end($m['signal']);
                $histNow = isset($m['hist']) && !empty($m['hist']) ? (float) end($m['hist']) : ($macdNow - $sigNow);
            }
        } else {
            $m = $this->macd->calculate($closes, $macdFast, $macdSlow, $macdSignal);
            $macdNow = (float) ($m['macd'] ?? 0.0);
            $sigNow  = (float) ($m['signal'] ?? 0.0);
            $histNow = (float) ($m['hist'] ?? ($macdNow - $sigNow));
        }

        $vwapVal = 0.0;
        if (method_exists($this->vwap, 'calculateSession')) {
            $vwapVal = (float) $this->vwap->calculateSession($candles, $vwapSession);
        } elseif (method_exists($this->vwap, 'calculateFull')) {
            $v = $this->vwap->calculateFull($candles, $vwapSession);
            $vwapVal = is_array($v) && !empty($v) ? (float) end($v) : 0.0;
        } elseif (method_exists($this->vwap, 'calculate')) {
            $vwapVal = (float) $this->vwap->calculate($closes, $volumes, $vwapSession);
        }

        $lastClose = (float) end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        $emaUp   = ($emaFast > $emaSlow + $eps);
        $emaDown = ($emaSlow > $emaFast + $eps);
        $macdUp  = ($histNow > 0 + $eps);
        $macdDown= ($histNow < 0 - $eps);

        $closeAboveVwap = ($lastClose > $vwapVal + $eps);
        $closeBelowVwap = ($lastClose < $vwapVal - $eps);

        $signal = 'NONE';
        $trigger = '';
        $path = 'execution_15m';

        if ($emaUp && $macdUp && $closeAboveVwap) {
            $signal  = 'LONG';
            $trigger = 'ema_fast_gt_slow & macd_hist_gt_0 & close_above_vwap';
        } elseif ($emaDown && $macdDown && $closeBelowVwap) {
            $signal  = 'SHORT';
            $trigger = 'ema_fast_lt_slow & macd_hist_lt_0 & close_below_vwap';
        }

        $validation = [
            'ema_fast' => $emaFast,
            'ema_slow' => $emaSlow,
            'macd'     => ['macd'=>$macdNow, 'signal'=>$sigNow, 'hist'=>$histNow],
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
