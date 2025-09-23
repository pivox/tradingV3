<?php
declare(strict_types=1);

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Momentum\Macd;
use App\Service\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

final class Signal5mService
{
    public function __construct(
        private LoggerInterface $validationLogger,
        private LoggerInterface $signalsLogger,
        private Ema $ema,
        private Macd $macd,
        private Vwap $vwap,
        private TradingParameters $params,
        private float $defaultEps = 1.0e-6,
        private bool $defaultUseLastClosed = true,
        private int $defaultMinBars = 220,
        private int $defaultEmaFastPeriod = 20,
        private int $defaultEmaSlowPeriod = 50,
        private int $defaultMacdFast = 12,
        private int $defaultMacdSlow = 26,
        private int $defaultMacdSignal = 9,
        private string $defaultVwapTz = 'UTC',
        private bool $defaultVwapDaily = true
    ) {}

    /**
     * @param Kline[] $candles (ancienne -> récente)
     */
    public function evaluate(array $candles): array
    {
        $cfg = $this->params->getConfig();

        $eps           = (float)($cfg['runtime']['eps'] ?? $this->defaultEps);
        $useLastClosed = (bool) ($cfg['runtime']['use_last_closed'] ?? $this->defaultUseLastClosed);
        $minBars       = (int)  ($cfg['timeframes']['5m']['guards']['min_bars'] ?? $this->defaultMinBars);

        $emaFastPeriod = (int)($cfg['indicators']['ema']['fast'] ?? $this->defaultEmaFastPeriod);
        $emaSlowPeriod = (int)($cfg['indicators']['ema']['slow'] ?? $this->defaultEmaSlowPeriod);
        $macdFast      = (int)($cfg['indicators']['macd']['fast'] ?? $this->defaultMacdFast);
        $macdSlow      = (int)($cfg['indicators']['macd']['slow'] ?? $this->defaultMacdSlow);
        $macdSignal    = (int)($cfg['indicators']['macd']['signal'] ?? $this->defaultMacdSignal);

        $vwapDaily     = (bool) ($cfg['indicators']['vwap']['daily']    ?? $this->defaultVwapDaily);
        $vwapTimezone  = (string)($cfg['indicators']['vwap']['timezone'] ?? $this->defaultVwapTz);

        if (count($candles) < $minBars) {
            $validation = [
                'ema_fast' => 0.0, 'ema_slow' => 0.0,
                'macd' => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0],
                'vwap' => 0.0, 'close' => 0.0,
                'path' => 'execution_5m', 'trigger' => '',
                'signal' => 'NONE', 'status' => 'insufficient_data',
            ];
            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;
        }

        // --- Séries OHLCV + timestamps (via getTimestamp()) ---
        $highs   = array_map(fn(Kline $k) => (float)$k->getHigh(),   $candles);
        $lows    = array_map(fn(Kline $k) => (float)$k->getLow(),    $candles);
        $closes  = array_map(fn(Kline $k) => (float)$k->getClose(),  $candles);
        $volumes = array_map(fn(Kline $k) => (float)$k->getVolume(), $candles);
        $times   = array_map(fn(Kline $k) => $k->getTimestamp()->getTimestamp(), $candles);

        // EMA
        $emaFast = (float)$this->ema->calculate($closes, $emaFastPeriod);
        $emaSlow = (float)$this->ema->calculate($closes, $emaSlowPeriod);

        // MACD
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
            $macdNow = (float)($m['macd'] ?? 0.0);
            $sigNow  = (float)($m['signal'] ?? 0.0);
            $histNow = (float)($m['hist'] ?? ($macdNow - $sigNow));
        }

        // VWAP : daily (si activé) sinon cumulatif
        $vwapVal = $vwapDaily
            ? (float)$this->vwap->calculateLastDailyWithTimestamps($times, $highs, $lows, $closes, $volumes, $vwapTimezone)
            : (float)$this->vwap->calculate($highs, $lows, $closes, $volumes);

        // Close de référence (dernier close clôturé si demandé)
        $lastClose = (float) end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        // Règles
        $emaTrendUp     = ($emaFast > $emaSlow + $eps);
        $emaTrendDown   = ($emaSlow > $emaFast + $eps);
        $macdHistUp     = ($histNow > 0.0 + $eps);
        $macdHistDown   = ($histNow < 0.0 - $eps);
        $closeAboveVwap = ($lastClose > $vwapVal + $eps);
        $closeBelowVwap = ($lastClose < $vwapVal - $eps);

        $signal = 'NONE'; $trigger = ''; $path = 'execution_5m';
        if ($emaTrendUp && $macdHistUp && $closeAboveVwap) {
            $signal  = 'LONG';
            $trigger = 'ema_fast_gt_slow & macd_hist_gt_0 & close_above_vwap_daily';
        } elseif ($emaTrendDown && $macdHistDown && $closeBelowVwap) {
            $signal  = 'SHORT';
            $trigger = 'ema_fast_lt_slow & macd_hist_lt_0 & close_below_vwap_daily';
        }

        $validation = [
            'ema_fast' => $emaFast, 'ema_slow' => $emaSlow,
            'macd' => ['macd'=>$macdNow,'signal'=>$sigNow,'hist'=>$histNow],
            'vwap' => $vwapVal, 'close' => $lastClose,
            'path' => $path, 'trigger' => $trigger, 'signal' => $signal,
        ];

        $this->signalsLogger->info('signals.tick', $validation);
        if ($signal === 'NONE') $this->validationLogger->warning('validation.violation', $validation);
        else                   $this->validationLogger->info('validation.ok', $validation);

        return $validation;
    }
}
