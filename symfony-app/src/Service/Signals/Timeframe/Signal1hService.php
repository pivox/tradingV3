<?php
// src/Service/Signals/Timeframe/Signal1hService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Momentum\Macd;
use Psr\Log\LoggerInterface;

/**
 * Contexte MTF 1h (biais opérationnel).
 *
 * YML scalping (lecture minimale, sûre) :
 *  - LONG  : ema_20 > ema_50 && macd_hist > 0
 *  - SHORT : ema_20 < ema_50 && macd_hist < 0
 */
final class Signal1hService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Ema $ema,
        private Macd $macd,
        private TradingParameters $params,
        // Defaults
        private float $defaultEps           = 1.0e-6,
        private bool  $defaultUseLastClosed = true,
        private int   $defaultMinBars       = 220,
        private int   $defaultEmaFastPeriod = 20,
        private int   $defaultEmaSlowPeriod = 50,
        private int   $defaultEmaTrendPeriod = 200,
        private int   $defaultMacdFast      = 12,
        private int   $defaultMacdSlow      = 26,
        private int   $defaultMacdSignal    = 9,
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne -> récente)
     * @return array{
     *   ema_fast: float,
     *   ema_slow: float,
     *   macd: array{macd:float,signal:float,hist:float},
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
        $minBars       = $cfg['timeframes']['1h']['guards']['min_bars'] ?? $this->defaultMinBars;

        $emaFastPeriod  = $cfg['indicators']['ema']['fast']  ?? $this->defaultEmaFastPeriod; // 20
        $emaSlowPeriod  = $cfg['indicators']['ema']['slow']  ?? $this->defaultEmaSlowPeriod; // 50
        $emaTrendPeriod = $cfg['indicators']['ema']['trend'] ?? $this->defaultEmaTrendPeriod; // 200

        $macdFast   = $cfg['indicators']['macd']['fast']   ?? $this->defaultMacdFast;
        $macdSlow   = $cfg['indicators']['macd']['slow']   ?? $this->defaultMacdSlow;
        $macdSignal = $cfg['indicators']['macd']['signal'] ?? $this->defaultMacdSignal;

        if (count($candles) < $minBars) {
            $validation = [
                'ema_fast' => 0.0,
                'ema_slow' => 0.0,
                'macd'     => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0],
                'close'    => 0.0,
                'path'     => 'context_1h',
                'trigger'  => '',
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];
            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;
        }

        $closes = array_map(fn(Kline $k) => $k->getClose(), $candles);

        $emaFast  = $this->ema->calculate($closes, $emaFastPeriod);
        $emaSlow  = $this->ema->calculate($closes, $emaSlowPeriod);
        $emaTrend = $this->ema->calculate($closes, $emaTrendPeriod);

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

        $lastClose = (float) end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        $emaUp   = ($emaFast > $emaSlow + $eps);
        $emaDown = ($emaSlow > $emaFast + $eps);
        $macdUp  = ($histNow > 0 + $eps);
        $macdDown= ($histNow < 0 - $eps);
        $closeAboveTrend = ($lastClose > $emaTrend + $eps);
        $closeBelowTrend = ($lastClose < $emaTrend - $eps);

        $signal = 'NONE';
        $trigger = '';
        $path = 'context_1h';

        if ($emaUp && $macdUp && $closeAboveTrend) {
            $signal  = 'LONG';
            $trigger = 'ema_20_gt_50 & macd_hist_gt_0 & close_above_ema_200';
        } elseif ($emaDown && $macdDown && $closeBelowTrend) {
            $signal  = 'SHORT';
            $trigger = 'ema_20_lt_50 & macd_hist_lt_0 & close_below_ema_200';
        }

        $validation = [
            'ema_fast'  => $emaFast,
            'ema_slow'  => $emaSlow,
            'ema_trend' => $emaTrend,
            'macd'      => ['macd'=>$macdNow, 'signal'=>$sigNow, 'hist'=>$histNow],
            'close'     => $lastClose,
            'close_above_ema_trend' => $closeAboveTrend,
            'close_below_ema_trend' => $closeBelowTrend,
            'path'      => $path,
            'trigger'   => $trigger,
            'signal'    => $signal,
            'date' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
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
