<?php
// src/Service/Signals/Timeframe/Signal15mService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Momentum\Rsi;
use App\Service\Indicator\Momentum\Macd;
use App\Service\Indicator\Momentum\StochRsi;
use App\Service\Indicator\Volatility\Bollinger;
use App\Service\Indicator\Volatility\Donchian;
use App\Service\Indicator\Volatility\Choppiness;
use Psr\Log\LoggerInterface;

// VWAP n'est pas requis dans la branche 15m du YML, on l'omet ici.

final class Signal15mService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Ema $ema,
        private Rsi $rsi,
        private Macd $macd,
        private StochRsi $stochRsi,
        private Bollinger $bollinger,
        private Donchian $donchian,
        private Choppiness $choppiness,
        private TradingParameters $params,

        // Défauts cohérents avec le YML v1.2
        private float $defaultEps = 1.0e-6,
        private bool  $defaultUseLastClosed = true,
        private int   $defaultMinBars = 220,
        private int   $defaultEmaFastPeriod = 20,
        private int   $defaultEmaSlowPeriod = 50,
        private int   $defaultMacdFast = 12,
        private int   $defaultMacdSlow = 26,
        private int   $defaultMacdSignal = 9,
        private int   $defaultStochRsiPeriod = 14,
        private int   $defaultStochRsiK = 3,
        private int   $defaultStochRsiD = 3,
        private int   $defaultDonchianPeriod = 20,
        private int   $defaultChopPeriod = 14,
        private float $defaultChopMax = 61.0,
        private int   $defaultBbPeriod = 20,
        private float $defaultBbStd = 2.0,
        private float $defaultBbMinWidthPct = 0.3 // optionnel : garde-fou de volatilité
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne -> récente)
     * @return array{
     *   ema_fast: float,
     *   ema_slow: float,
     *   rsi14: float,
     *   macd: array{macd:float,signal:float,hist:float},
     *   stochrsi: array{k:float,d:float,cross:string},
     *   donchian: array{upper:float,lower:float,mid:float},
     *   choppiness: float,
     *   bollinger?: array{upper:float,lower:float,middle:float,width:float,width_pct?:float},
     *   path: string,
     *   trigger: string,
     *   signal: string,
     *   status?: string
     * }
     */
    public function evaluate(array $candles): array
    {
        // 1) Config YAML (sécurisée)
        $cfg = $this->params->getConfig();
        $tf  = $cfg['timeframes']['15m'] ?? []; // section spécifique si vous en avez une

        $eps           = $cfg['runtime']['eps']              ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed']  ?? $this->defaultUseLastClosed;
        $minBars       = $tf['guards']['min_bars']           ?? $this->defaultMinBars;

        $emaFastPeriod = $cfg['indicators']['ema']['fast']   ?? $this->defaultEmaFastPeriod;
        $emaSlowPeriod = $cfg['indicators']['ema']['slow']   ?? $this->defaultEmaSlowPeriod;

        $macdFast      = $cfg['indicators']['macd']['fast']  ?? $this->defaultMacdFast;
        $macdSlow      = $cfg['indicators']['macd']['slow']  ?? $this->defaultMacdSlow;
        $macdSignal    = $cfg['indicators']['macd']['signal']?? $this->defaultMacdSignal;

        $srsiPeriod    = $cfg['indicators']['stochrsi']['period'] ?? $this->defaultStochRsiPeriod;
        $srsiK         = $cfg['indicators']['stochrsi']['k']      ?? $this->defaultStochRsiK;
        $srsiD         = $cfg['indicators']['stochrsi']['d']      ?? $this->defaultStochRsiD;

        $donchianPeriod= $cfg['indicators']['donchian']['period'] ?? $this->defaultDonchianPeriod;

        $chopPeriod    = $cfg['indicators']['choppiness']['period'] ?? $this->defaultChopPeriod;
        $chopMax       = $cfg['indicators']['choppiness']['max']    ?? $this->defaultChopMax;

        $bbPeriod      = $cfg['indicators']['bollinger']['period'] ?? $this->defaultBbPeriod;
        $bbStd         = $cfg['indicators']['bollinger']['std']    ?? $this->defaultBbStd;
        $bbMinWidthPct = $cfg['indicators']['bollinger']['min_width_pct'] ?? $this->defaultBbMinWidthPct;

        // 2) Garde-fou
        if (count($candles) < $minBars) {
            $validation = [
                'ema_fast'  => 0.0,
                'ema_slow'  => 0.0,
                'rsi14'     => 0.0,
                'macd'      => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0],
                'stochrsi'  => ['k'=>0.0,'d'=>0.0,'cross'=>'none'],
                'donchian'  => ['upper'=>0.0,'lower'=>0.0,'mid'=>0.0],
                'choppiness'=> 100.0,
                'bollinger' => ['upper'=>0.0,'lower'=>0.0,'middle'=>0.0,'width'=>0.0,'width_pct'=>0.0],
                'path'      => 'neutral',
                'trigger'   => '',
                'signal'    => 'NONE',
                'status'    => 'insufficient_data',
            ];

            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;
        }

        // 3) Séries OHLC
        $closes = array_map(fn(Kline $k) => $k->getClose(), $candles);
        $highs  = array_map(fn(Kline $k) => $k->getHigh(),  $candles);
        $lows   = array_map(fn(Kline $k) => $k->getLow(),   $candles);

        // 4) Indicateurs
        $emaFast = $this->ema->calculate($closes, $emaFastPeriod);
        $emaSlow = $this->ema->calculate($closes, $emaSlowPeriod);

        $rsi14   = $this->rsi->calculate($closes, 14);

        // MACD (séries)
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

        // StochRSI
        $sr = $this->stochRsi->calculateFull($closes, $srsiPeriod, $srsiK, $srsiD);
        $kNow = isset($sr['k']) && !empty($sr['k']) ? (float) end($sr['k']) : 0.0;
        $dNow = isset($sr['d']) && !empty($sr['d']) ? (float) end($sr['d']) : 0.0;
        $kPrev = $kNow; $dPrev = $dNow;
        if (!empty($sr['k']) && count($sr['k']) > 1) { $kPrev = (float) $sr['k'][count($sr['k']) - 2]; }
        if (!empty($sr['d']) && count($sr['d']) > 1) { $dPrev = (float) $sr['d'][count($sr['d']) - 2]; }
        $srsiCrossUp   = ($kPrev <= $dPrev + $eps) && ($kNow > $dNow + $eps);
        $srsiCrossDown = ($kPrev >= $dPrev - $eps) && ($kNow < $dNow - $eps);
        $srsiCross = $srsiCrossUp ? 'k_cross_up_d' : ($srsiCrossDown ? 'k_cross_down_d' : 'none');

        // Donchian (20) + médiane
        $dc = $this->donchian->calculateFull($highs, $lows, $donchianPeriod);
        $dcUpper = (float) ($dc['upper'] ?? 0.0);
        $dcLower = (float) ($dc['lower'] ?? 0.0);
        $dcMid   = ($dcUpper + $dcLower) / 2.0;

        // Choppiness
        $chop = $this->choppiness->calculate($highs, $lows, $closes, $chopPeriod);

        // Bollinger (optionnel : contrôle d’environnement)
        $bb = ['upper'=>0.0,'lower'=>0.0,'middle'=>0.0,'width'=>0.0,'width_pct'=>0.0];
        if (method_exists($this->bollinger, 'calculateFull')) {
            $b = $this->bollinger->calculateFull($closes, $bbPeriod, $bbStd);
            if (!empty($b['upper']) && !empty($b['lower'])) {
                $bb['upper']  = (float) end($b['upper']);
                $bb['lower']  = (float) end($b['lower']);
                $bb['middle'] = !empty($b['middle']) ? (float) end($b['middle']) : ($bb['upper'] + $bb['lower']) / 2.0;
                $bb['width']  = $bb['upper'] - $bb['lower'];
                $priceRef     = (float) end($closes);
                $bb['width_pct'] = ($priceRef > 0.0) ? ($bb['width'] / $priceRef) * 100.0 : 0.0;
            }
        }

        // 5) Close de référence (dernier close clôturé si demandé)
        $lastClose = (float) end($closes);
        $prevClose = $lastClose;
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
            $prevClose = (float) (count($tmp) > 1 ? $tmp[count($tmp) - 2] : $lastClose);
        } elseif (count($closes) > 1) {
            $prevClose = (float) $closes[count($closes) - 2];
        }

        // 6) Règles d'exécution 15m (YML v1.2)
        // Long: ema_fast>ema_slow && macd_hist>0 && StochRSI K cross up D && choppiness < 61 && close > Donchian mid
        // Short: ema_fast<ema_slow && macd_hist<0 && StochRSI K cross down D && choppiness < 61 && close < Donchian mid
        // Optionnel : vérifier un minimum d'ouverture (Bollinger width_pct) si vous souhaitez filtrer marchés ultra-calmes.
        $emaTrendUp   = ($emaFast > $emaSlow + $eps);
        $emaTrendDown = ($emaSlow > $emaFast + $eps);

        $macdHistUp   = ($histNow > 0 + $eps);
        $macdHistDown = ($histNow < 0 - $eps);

        $closeAboveDcMid = ($lastClose > $dcMid + $eps);
        $closeBelowDcMid = ($lastClose < $dcMid - $eps);

        $chopOk = ($chop < $chopMax - $eps);

        $bbOk = true;
        if ($bb['width_pct'] > 0.0 && $bbMinWidthPct > 0.0) {
            $bbOk = ($bb['width_pct'] >= $bbMinWidthPct - $eps);
        }

        $signal = 'NONE';
        $trigger = '';
        $path = 'execution_15m';

        $long  = $emaTrendUp   && $macdHistUp   && $srsiCrossUp   && $chopOk && $closeAboveDcMid && $bbOk;
        $short = $emaTrendDown && $macdHistDown && $srsiCrossDown && $chopOk && $closeBelowDcMid && $bbOk;

        if ($long) {
            $signal  = 'LONG';
            $trigger = 'ema_fast_gt_slow & macd_hist_gt_0 & stochrsi_k_cross_up_d & choppiness_below_max & close_above_donchian_mid';
        } elseif ($short) {
            $signal  = 'SHORT';
            $trigger = 'ema_fast_lt_slow & macd_hist_lt_0 & stochrsi_k_cross_down_d & choppiness_below_max & close_below_donchian_mid';
        }

        // 7) Retour
        $validation = [
            'ema_fast'  => $emaFast,
            'ema_slow'  => $emaSlow,
            'rsi14'     => $rsi14,
            'macd'      => [
                'macd'   => $macdNow,
                'signal' => $sigNow,
                'hist'   => $histNow,
            ],
            'stochrsi' => [
                'k'     => $kNow,
                'd'     => $dNow,
                'cross' => $srsiCross,
            ],
            'donchian' => [
                'upper' => $dcUpper,
                'lower' => $dcLower,
                'mid'   => $dcMid,
            ],
            'choppiness' => $chop,
            'bollinger'  => $bb,
            'path'       => $path,
            'trigger'    => $trigger,
            'signal'     => $signal,
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
