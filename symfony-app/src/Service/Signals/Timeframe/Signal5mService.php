<?php
// src/Service/Signals/Timeframe/Signal5mService.php
declare(strict_types=1);

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Momentum\Macd;
use App\Service\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

/**
 * Service de signal pour le timeframe 5m (exécution fine, très réactif).
 *
 * Conformité YML v1.2 (branche 5m) :
 *  - LONG  : ema_fast > ema_slow  && macd_hist > 0 && close > vwap(daily)
 *  - SHORT : ema_fast < ema_slow  && macd_hist < 0 && close < vwap(daily)
 *
 * Hypothèses :
 *  - Le contexte MTF (4H/1H/15m) est validé en amont (ex: SignalScalpingService).
 *  - Ici, on ne fait que le TRIGGER 5m, sans ADX/RSI/Ichimoku/Bollinger/Donchian.
 */
final class Signal5mService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Ema $ema,
        private Macd $macd,
        private Vwap $vwap,
        private TradingParameters $params,

        // Défauts cohérents avec le YML v1.2
        private float  $defaultEps           = 1.0e-6,
        private bool   $defaultUseLastClosed = true,
        private int    $defaultMinBars       = 220,
        private int    $defaultEmaFastPeriod = 20,
        private int    $defaultEmaSlowPeriod = 50,
        private int    $defaultMacdFast      = 12,
        private int    $defaultMacdSlow      = 26,
        private int    $defaultMacdSignal    = 9,
        private string $defaultVwapSession   = 'daily'
    ) {}

    /**
     * @param Kline[] $candles Bougies dans l'ordre chronologique (ancienne -> récente)
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
        // 1) Config YAML (défensive)
        $cfg = $this->params->getConfig();

        $eps           = $cfg['runtime']['eps']             ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed'] ?? $this->defaultUseLastClosed;

        // On peut prévoir une section spécifique timeframes->5m->guards si vous l'ajoutez plus tard
        $minBars       = $cfg['timeframes']['5m']['guards']['min_bars'] ?? $this->defaultMinBars;

        // Indicateurs (YML v1.2)
        $emaFastPeriod = $cfg['indicators']['ema']['fast']    ?? $this->defaultEmaFastPeriod; // 20
        $emaSlowPeriod = $cfg['indicators']['ema']['slow']    ?? $this->defaultEmaSlowPeriod; // 50

        $macdFast      = $cfg['indicators']['macd']['fast']   ?? $this->defaultMacdFast;      // 12
        $macdSlow      = $cfg['indicators']['macd']['slow']   ?? $this->defaultMacdSlow;      // 26
        $macdSignal    = $cfg['indicators']['macd']['signal'] ?? $this->defaultMacdSignal;    // 9

        $vwapSession   = $cfg['indicators']['vwap']['session'] ?? $this->defaultVwapSession;   // daily

        // 2) Garde-fou data
        if (count($candles) < $minBars) {
            $validation = [
                'ema_fast' => 0.0,
                'ema_slow' => 0.0,
                'macd'     => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0],
                'vwap'     => 0.0,
                'close'    => 0.0,
                'path'     => 'execution_5m',
                'trigger'  => '',
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];
            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;
        }

        // 3) Séries OHLCV
        $closes  = array_map(fn(Kline $k) => $k->getClose(),  $candles);
        $volumes = array_map(fn(Kline $k) => $k->getVolume(), $candles);
        $highs   = array_map(fn(Kline $k) => $k->getHigh(),   $candles);
        $lows    = array_map(fn(Kline $k) => $k->getLow(),    $candles);

        // 4) Indicateurs
        $emaFast = $this->ema->calculate($closes, $emaFastPeriod);
        $emaSlow = $this->ema->calculate($closes, $emaSlowPeriod);

        // MACD (séries si dispo)
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

        // VWAP (session = daily)
        // Plusieurs signatures possibles selon votre implémentation.
        $vwapVal = 0.0;
        if (method_exists($this->vwap, 'calculateSession')) {
            // Signature suggérée : calculateSession(Kline[] $candles, string $session = 'daily'): float
            $vwapVal = (float) $this->vwap->calculateSession($candles, $vwapSession);
        } elseif (method_exists($this->vwap, 'calculateFull')) {
            // Signature attendue : calculateFull(array $highs, array $lows, array $closes, array $volumes): array
            $v = $this->vwap->calculateFull($highs, $lows, $closes, $volumes);
            $vwapVal = is_array($v) && !empty($v) ? (float) end($v) : 0.0;
        } elseif (method_exists($this->vwap, 'calculate')) {
            // Ex: calculate(array $closes, array $volumes, string $session): float
            $vwapVal = (float) $this->vwap->calculate($closes, $volumes, $vwapSession);
        }

        // 5) Close de référence (dernier close clôturé si demandé)
        $lastClose = (float) end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        // 6) Logique d'exécution 5m (YML v1.2)
        $emaTrendUp   = ($emaFast > $emaSlow + $eps);
        $emaTrendDown = ($emaSlow > $emaFast + $eps);

        $macdHistUp   = ($histNow > 0 + $eps);
        $macdHistDown = ($histNow < 0 - $eps);

        $closeAboveVwap = ($lastClose > $vwapVal + $eps);
        $closeBelowVwap = ($lastClose < $vwapVal - $eps);

        $signal  = 'NONE';
        $trigger = '';
        $path    = 'execution_5m';

        $long  = $emaTrendUp   && $macdHistUp   && $closeAboveVwap;
        $short = $emaTrendDown && $macdHistDown && $closeBelowVwap;

        if ($long) {
            $signal  = 'LONG';
            $trigger = 'ema_fast_gt_slow & macd_hist_gt_0 & close_above_vwap';
        } elseif ($short) {
            $signal  = 'SHORT';
            $trigger = 'ema_fast_lt_slow & macd_hist_lt_0 & close_below_vwap';
        }

        // 7) Retour
        $validation = [
            'ema_fast' => $emaFast,
            'ema_slow' => $emaSlow,
            'macd'     => [
                'macd'   => $macdNow,
                'signal' => $sigNow,
                'hist'   => $histNow,
            ],
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
