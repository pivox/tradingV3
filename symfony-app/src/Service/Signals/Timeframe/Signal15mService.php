<?php
// src/Service/Signals/Timeframe/Signal15mService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Momentum\Macd;
use App\Service\Indicator\Momentum\Rsi;
use App\Service\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

/**
 * Exécution 15m (plus propre que 5m, moins de bruit).
 *
 * YML scalping (lecture minimale, sûre) :
 *  - LONG  : ema_20 > ema_50 && macd_hist > 0 && close > vwap(daily) && rsi < 70
 *  - SHORT : ema_20 < ema_50 && macd_hist < 0 && close < vwap(daily) && rsi > 30
 *
 * NB: Donchian/StochRSI/Choppiness peuvent être ajoutés plus tard de façon optionnelle.
 */
final class Signal15mService
{
    public function __construct(
        private LoggerInterface $validationLogger,
        private LoggerInterface $signalsLogger,
        private Ema $ema,
        private Macd $macd,
        private Rsi $rsi,
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
        private int   $defaultRsiPeriod     = 14,
        private float $defaultRsiLongMax    = 70.0,
        private float $defaultRsiShortMin   = 30.0,
        private string $defaultTimezone     = 'UTC'   // ← remplace l’ancienne notion de "session"
    ) {}

    /** @param Kline[] $candles */
    public function evaluate(array $candles): array
    {
        $this->validationLogger->info(' --- Evaluating signal 15m --- ');
        $cfg = $this->params->getConfig();

        $eps           = $cfg['runtime']['eps']             ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed'] ?? $this->defaultUseLastClosed;
        $minBars       = $cfg['timeframes']['15m']['guards']['min_bars'] ?? $this->defaultMinBars;

        $emaFastPeriod = $cfg['indicators']['ema']['fast']  ?? $this->defaultEmaFastPeriod;
        $emaSlowPeriod = $cfg['indicators']['ema']['slow']  ?? $this->defaultEmaSlowPeriod;

        $macdFast   = $cfg['indicators']['macd']['fast']   ?? $this->defaultMacdFast;
        $macdSlow   = $cfg['indicators']['macd']['slow']   ?? $this->defaultMacdSlow;
        $macdSignal = $cfg['indicators']['macd']['signal'] ?? $this->defaultMacdSignal;

        $rsiPeriod  = $cfg['indicators']['rsi']['period']  ?? $this->defaultRsiPeriod;
        $rsiGuard   = $cfg['timeframes']['15m']['guards']['rsi'] ?? [];
        $rsiLongMax  = is_array($rsiGuard) && array_key_exists('long_max', $rsiGuard)
            ? (float)$rsiGuard['long_max']
            : $this->defaultRsiLongMax;
        $rsiShortMin = is_array($rsiGuard) && array_key_exists('short_min', $rsiGuard)
            ? (float)$rsiGuard['short_min']
            : $this->defaultRsiShortMin;

        // 🔁 Nouvelle lecture de conf VWAP (compatible avec ton YAML v1.2)
        $vwapDaily   = (bool)($cfg['indicators']['vwap']['daily'] ?? true);
        $vwapTz      = (string)($cfg['indicators']['vwap']['timezone'] ?? $this->defaultTimezone);

        if (count($candles) < $minBars) {
            $validation = [
                'ema_fast' => 0.0, 'ema_slow' => 0.0,
                'macd' => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0],
                'vwap' => 0.0, 'close' => 0.0,
                'path' => 'execution_15m', 'trigger' => '',
                'signal' => 'NONE', 'status' => 'insufficient_data',
            ];
            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            $this->validationLogger->info(' --- END Evaluating signal 15m --- ');
            return $validation;
        }

        // 🧩 Décomposition des bougies → tableaux scalaires
        $timestamps = array_map(fn(Kline $k) => (int)$k->getTimestamp()->getTimestamp(), $candles); // adapte si tu as getOpenTimeMs()
        $highs      = array_map(fn(Kline $k) => (float)$k->getHigh(),   $candles);
        $lows       = array_map(fn(Kline $k) => (float)$k->getLow(),    $candles);
        $closes     = array_map(fn(Kline $k) => (float)$k->getClose(),  $candles);
        $volumes    = array_map(fn(Kline $k) => (float)$k->getVolume(), $candles);

        // 📈 EMA / MACD inchangés
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

        // ✅ VWAP : compat "daily reset" OU "continu"
        $vwapVal = 0.0;

        if ($vwapDaily) {
            // VWAP avec reset quotidien (plus fidèle à un "session/daily VWAP")
            $vwapVal = (float) $this->vwap->calculateLastDailyWithTimestamps(
                $timestamps, $highs, $lows, $closes, $volumes, $vwapTz
            );
        } else {
            // VWAP continu cumulé
            $vwapVal = (float) $this->vwap->calculate($highs, $lows, $closes, $volumes);
        }

        // close utilisé
        $lastClose = (float) end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        $emaUp   = ($emaFast > $emaSlow + $eps);
        $emaDown = ($emaSlow > $emaFast + $eps);
        $macdUp  = ($histNow > 0 + $eps);
        $macdDown= ($histNow < 0 - $eps);

        $rsiCloses = $closes;
        if ($useLastClosed && count($rsiCloses) > 1) {
            array_pop($rsiCloses);
        }
        $rsiSeries = $this->rsi->calculateFull($rsiCloses, (int)$rsiPeriod);
        $rsiNow = !empty($rsiSeries['rsi']) ? (float) end($rsiSeries['rsi']) : 50.0;
        $rsiBelowCap = ($rsiNow <= $rsiLongMax - $eps);
        $rsiAboveFloor = ($rsiNow >= $rsiShortMin + $eps);

        $closeAboveVwap = ($lastClose > $vwapVal + $eps);
        $closeBelowVwap = ($lastClose < $vwapVal - $eps);

        $signal = 'NONE';
        $trigger = '';
        $path = 'execution_15m';

        if ($emaUp && $macdUp && $closeAboveVwap && $rsiBelowCap) {
            $signal  = 'LONG';
            $trigger = 'ema_20_gt_50 & macd_hist_gt_0 & close_above_vwap & rsi_lt_70';
        } elseif ($emaDown && $macdDown && $closeBelowVwap && $rsiAboveFloor) {
            $signal  = 'SHORT';
            $trigger = 'ema_20_lt_50 & macd_hist_lt_0 & close_below_vwap & rsi_gt_30';
        }

        $validation = [
            'ema_fast' => $emaFast,
            'ema_slow' => $emaSlow,
            'macd'     => ['macd'=>$macdNow, 'signal'=>$sigNow, 'hist'=>$histNow],
            'vwap'     => $vwapVal,
            'close'    => $lastClose,
            'rsi'      => $rsiNow,
            'path'     => $path,
            'trigger'  => $trigger,
            'signal'   => $signal,
            'date' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ];

        $this->signalsLogger->info('signals.tick', $validation);
        if ($signal === 'NONE') {
            $this->validationLogger->warning('validation.violation', $validation);
        } else {
            $this->validationLogger->info('validation.ok', $validation);
        }

        $this->validationLogger->info(' --- END Evaluating signal 15m --- ');
        return $validation;
    }
}
