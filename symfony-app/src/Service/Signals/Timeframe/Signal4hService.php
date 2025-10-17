<?php
// src/Service/Signals/Timeframe/Signal4hService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Momentum\Macd;
use Psr\Log\LoggerInterface;

/**
 * Contexte MTF 4h (biais de fond).
 *
 * YML scalping (lecture minimale, sûre) :
 *  - LONG  : ema_50 > ema_200 && macd_hist > 0 && close > ema_200
 *  - SHORT : ema_50 < ema_200 && macd_hist < 0 && close < ema_200
 *
 * On reste volontairement sobre (EMA/MACD). Les autres filtres (ADX/RSI/Ichimoku…)
 * peuvent être ajoutés plus tard sans casser l’API.
 */
final class Signal4hService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Ema $ema,
        private Macd $macd,
        private TradingParameters $params,
        // Defaults au cas où des clés YAML manquent
        private float $defaultEps             = 1.0e-6,
        private bool  $defaultUseLastClosed   = true,
        private int   $defaultMinBars         = 260,  // >= 200 + marge
        private int   $defaultEmaMidPeriod    = 50,
        private int   $defaultEmaTrendPeriod  = 200,
        private int   $defaultMacdFast        = 12,
        private int   $defaultMacdSlow        = 26,
        private int   $defaultMacdSignal      = 9,
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne -> récente)
     * @return array{
     *   ema_mid: float,
     *   ema_trend: float,
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
        $minBars       = $cfg['timeframes']['4h']['guards']['min_bars'] ?? $this->defaultMinBars;

        $emaMidPeriod   = $cfg['indicators']['ema']['slow']   ?? $this->defaultEmaMidPeriod;   // 50
        $emaTrendPeriod = $cfg['indicators']['ema']['trend']  ?? $this->defaultEmaTrendPeriod; // 200

        $macdFast   = $cfg['indicators']['macd']['fast']   ?? $this->defaultMacdFast;
        $macdSlow   = $cfg['indicators']['macd']['slow']   ?? $this->defaultMacdSlow;
        $macdSignal = $cfg['indicators']['macd']['signal'] ?? $this->defaultMacdSignal;

        if (count($candles) < $minBars) {
            $validation = [
                'ema_mid'  => 0.0,
                'ema_trend'=> 0.0,
                'macd'     => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0],
                'close'    => 0.0,
                'path'     => 'context_4h',
                'trigger'  => '',
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];
            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;
        }

        $closes = array_map(fn(Kline $k) => $k->getClose(), $candles);

        $emaMid   = $this->ema->calculate($closes, $emaMidPeriod);
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

        $emaUp   = ($emaMid > $emaTrend + $eps);
        $emaDown = ($emaTrend > $emaMid + $eps);
        $macdUp  = ($histNow > 0 + $eps);
        $macdDown= ($histNow < 0 - $eps);
        $closeAboveTrend = ($lastClose > $emaTrend + $eps);
        $closeBelowTrend = ($lastClose < $emaTrend - $eps);

        $signal = 'NONE';
        $trigger = '';
        $path = 'context_4h';

        if ($emaUp && $macdUp && $closeAboveTrend) {
            $signal  = 'LONG';
            $trigger = 'ema_50_gt_200 & macd_hist_gt_0 & close_above_ema_200';
        } elseif ($emaDown && $macdDown && $closeBelowTrend) {
            $signal  = 'SHORT';
            $trigger = 'ema_50_lt_200 & macd_hist_lt_0 & close_below_ema_200';
        }

        $validation = [
            'ema_mid'   => $emaMid,
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
