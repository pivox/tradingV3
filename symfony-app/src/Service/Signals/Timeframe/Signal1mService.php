<?php
// src/Service/Signals/Timeframe/Signal1mService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Trend\Adx;
use App\Service\Indicator\Trend\Ichimoku;
use App\Service\Indicator\Momentum\Rsi;
use App\Service\Indicator\Momentum\Macd;
use App\Service\Indicator\Volatility\Bollinger;

/**
 * Service de signal pour le timeframe 1m (micro‑exécution / timing d'entrée).
 *
 * Objectif : déclencher très vite, en cohérence avec 4H→1H→15m déjà validés.
 *  - Régime via ADX (seuils plus sensibles que 5m),
 *  - Alignement EMA (ema_fast vs ema_slow),
 *  - Triggers rapides : MACD cross, RSI cross 50, franchissement Kijun, price vs Senkou A,
 *  - Optionnel : expansion de volatilité (pente de width Bollinger sur 1–2 barres).
 */
final class Signal1mService
{
    public function __construct(
        private Ema $ema,
        private Adx $adx,
        private Ichimoku $ichimoku,
        private Rsi $rsi,
        private Macd $macd,
        private Bollinger $bollinger,
        private TradingParameters $params,

        // Défauts (ultra réactifs)
        private float $defaultEps             = 1.0e-6,
        private bool  $defaultUseLastClosed   = true,
        private int   $defaultMinBars         = 220,
        private int   $defaultTrendMinAdx     = 19,
        private int   $defaultRangeMaxAdx     = 15,
        private int   $defaultBbLookback      = 1,
        private int   $defaultEmaFastPeriod   = 34,   // plus réactif par défaut en 1m
        private int   $defaultEmaSlowPeriod   = 144
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne → récente)
     * @return array{
     *   ema_fast: float,
     *   ema_slow: float,
     *   adx14: float,
     *   rsi14: float,
     *   macd: array{macd:float,signal:float,hist:float,diff:float,prev_diff:float},
     *   bollinger: array{upper:float,lower:float,middle:float,width:float},
     *   ichimoku: array{tenkan:float,kijun:float,senkou_a:float,senkou_b:float},
     *   regime: string,
     *   path: string,
     *   trigger: string,
     *   signal: string,
     *   status?: string
     * }
     */
    public function evaluate(array $candles): array
    {
        // 1) Config YAML
        $cfg = $this->params->getConfig();
        $tf  = $cfg['timeframes']['1m'] ?? [];

        $eps           = $cfg['runtime']['eps']              ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed']  ?? $this->defaultUseLastClosed;
        $minBars       = $tf['guards']['min_bars']           ?? $this->defaultMinBars;

        $trendMinAdx   = $tf['regime']['trend_min_adx']      ?? $this->defaultTrendMinAdx;
        $rangeMaxAdx   = $tf['regime']['range_max_adx']      ?? $this->defaultRangeMaxAdx;
        $bbLookback    = $tf['regime']['bb_lookback']        ?? $this->defaultBbLookback;

        $emaFastPeriod = $tf['indicators']['ema_fast']['period'] ?? ($tf['indicators']['ema34']['period'] ?? $this->defaultEmaFastPeriod);
        $emaSlowPeriod = $tf['indicators']['ema_slow']['period'] ?? ($tf['indicators']['ema144']['period'] ?? $this->defaultEmaSlowPeriod);

        // 2) Garde-fou
        if (count($candles) < $minBars) {
            return [
                'ema_fast'  => 0.0,
                'ema_slow'  => 0.0,
                'adx14'     => 0.0,
                'rsi14'     => 0.0,
                'macd'      => ['macd'=>0.0,'signal'=>0.0,'hist'=>0.0,'diff'=>0.0,'prev_diff'=>0.0],
                'bollinger' => ['upper'=>0.0,'lower'=>0.0,'middle'=>0.0,'width'=>0.0],
                'ichimoku'  => ['tenkan'=>0.0,'kijun'=>0.0,'senkou_a'=>0.0,'senkou_b'=>0.0],
                'regime'    => 'neutral',
                'path'      => 'neutral',
                'trigger'   => '',
                'signal'    => 'NONE',
                'status'    => 'insufficient_data',
            ];
        }

        // 3) Séries OHLC
        $closes = array_map(fn(Kline $k) => $k->getClose(), $candles);
        $highs  = array_map(fn(Kline $k) => $k->getHigh(),  $candles);
        $lows   = array_map(fn(Kline $k) => $k->getLow(),   $candles);

        // 4) Indicateurs
        $emaFast = $this->ema->calculate($closes, $emaFastPeriod);
        $emaSlow = $this->ema->calculate($closes, $emaSlowPeriod);
        $adx14   = $this->adx->calculate($highs, $lows, $closes, 14);

        $ich = $this->ichimoku->calculateFull($highs, $lows, $closes, 9, 26, 52, 26, true);
        if (!isset($ich['senkou_a']) && isset($ich['tenkan'], $ich['kijun'])) {
            $ich['senkou_a'] = ($ich['tenkan'] + $ich['kijun']) / 2.0; // secours
        }

        $rsiNow  = $this->rsi->calculate($closes, 14);
        $rsiPrev = $this->rsi->calculate(array_slice($closes, 0, -1), 14);

        // MACD séries avec diff précédent
        $macdNow = 0.0; $sigNow = 0.0; $histNow = 0.0; $diffNow = 0.0; $diffPrev = 0.0;
        if (method_exists($this->macd, 'calculateFull')) {
            $m = $this->macd->calculateFull($closes, 12, 26, 9);
            if (!empty($m['macd']) && !empty($m['signal'])) {
                $macdNow = (float) end($m['macd']);
                $sigNow  = (float) end($m['signal']);
                $histNow = isset($m['hist']) && !empty($m['hist']) ? (float) end($m['hist']) : ($macdNow - $sigNow);
                $nMacd = count($m['macd']); $nSig = count($m['signal']);
                if ($nMacd > 1 && $nSig > 1) {
                    $prevMacd = (float) $m['macd'][$nMacd - 2];
                    $prevSig  = (float) $m['signal'][$nSig - 2];
                    $diffPrev = $prevMacd - $prevSig;
                }
            }
            $diffNow = $macdNow - $sigNow;
        } else {
            $m = $this->macd->calculate($closes, 12, 26, 9);
            $macdNow = (float) ($m['macd'] ?? 0.0);
            $sigNow  = (float) ($m['signal'] ?? 0.0);
            $histNow = (float) ($m['hist'] ?? ($macdNow - $sigNow));
            $diffNow = $macdNow - $sigNow;
            $m2 = $this->macd->calculate(array_slice($closes, 0, -1), 12, 26, 9);
            $diffPrev = (float) (($m2['macd'] ?? 0.0) - ($m2['signal'] ?? 0.0));
        }

        // Bollinger
        $bb = ['upper'=>0.0,'lower'=>0.0,'middle'=>0.0,'width'=>0.0];
        $bbWidthSeries = [];
        if (method_exists($this->bollinger, 'calculateFull')) {
            $b = $this->bollinger->calculateFull($closes, 20, 2.0);
            if (!empty($b['upper']) && !empty($b['lower'])) {
                $n = min(count($b['upper']), count($b['lower']));
                for ($i = 0; $i < $n; $i++) { $bbWidthSeries[] = (float) ($b['upper'][$i] - $b['lower'][$i]); }
                $bb['upper']  = (float) end($b['upper']);
                $bb['lower']  = (float) end($b['lower']);
                $bb['middle'] = !empty($b['middle']) ? (float) end($b['middle']) : ($bb['upper'] + $bb['lower']) / 2.0;
                $bb['width']  = $bbWidthSeries ? (float) end($bbWidthSeries) : ($bb['upper'] - $bb['lower']);
            }
        } else {
            $b = $this->bollinger->calculate($closes, 20, 2.0);
            $bb['upper']  = (float) ($b['upper']  ?? 0.0);
            $bb['lower']  = (float) ($b['lower']  ?? 0.0);
            $bb['middle'] = (float) ($b['middle'] ?? (($bb['upper'] + $bb['lower']) / 2.0));
            $bb['width']  = (float) ($b['width']  ?? ($bb['upper'] - $bb['lower']));
        }

        // 5) Closes de référence
        $lastClose = end($closes);
        $prevClose = $lastClose;
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
            $prevClose = (float) (count($tmp) > 1 ? $tmp[count($tmp) - 2] : $lastClose);
        } elseif (count($closes) > 1) {
            $prevClose = (float) $closes[count($closes) - 2];
        }

        // 6) Régime
        $regime = 'neutral';
        if ($adx14 >= $trendMinAdx)      { $regime = 'trend'; }
        elseif ($adx14 <= $rangeMaxAdx)  { $regime = 'range'; }

        // 7) Triggers
        $emaTrendUp   = ($emaFast > $emaSlow + $eps);
        $emaTrendDown = ($emaSlow > $emaFast + $eps);

        $macdCrossUp   = ($diffPrev <= 0 + $eps) && ($diffNow > 0 + $eps);
        $macdCrossDown = ($diffPrev >= 0 - $eps) && ($diffNow < 0 - $eps);

        $rsiCrossUp   = ($rsiPrev <= 50 + $eps) && ($rsiNow > 50 + $eps);
        $rsiCrossDown = ($rsiPrev >= 50 - $eps) && ($rsiNow < 50 - $eps);

        $kijun   = $ich['kijun']    ?? 0.0;
        $senkouA = $ich['senkou_a'] ?? 0.0;
        $priceAboveLine = ($lastClose > $kijun + $eps) || ($lastClose > $senkouA + $eps);
        $priceBelowLine = ($kijun > $lastClose + $eps) || ($senkouA > $lastClose + $eps);

        $kijunBreakUp   = ($prevClose <= $kijun + $eps) && ($lastClose > $kijun + $eps);
        $kijunBreakDown = ($prevClose >= $kijun - $eps) && ($lastClose < $kijun - $eps);

        $bbWidthUp = false;
        if ($bbWidthSeries && count($bbWidthSeries) > $bbLookback) {
            $nowW  = (float) end($bbWidthSeries);
            $prevW = $bbWidthSeries[count($bbWidthSeries) - 1 - $bbLookback];
            $bbWidthUp = ($nowW > $prevW + $eps);
        }

        // 8) Décision
        $path = 'neutral';
        $trigger = '';
        $signal = 'NONE';

        if ($regime === 'trend') {
            $path = 'trend';
            $long  = $emaTrendUp   && ($macdCrossUp   || $rsiCrossUp   || $kijunBreakUp   || $priceAboveLine);
            $short = $emaTrendDown && ($macdCrossDown || $rsiCrossDown || $kijunBreakDown || $priceBelowLine);

            if ($long) {
                $signal = 'LONG';
                $trigger = $macdCrossUp ? 'macd_cross_up' : ($rsiCrossUp ? 'rsi_cross_up' : ($kijunBreakUp ? 'kijun_break_up' : 'ichi_price_above'));
            } elseif ($short) {
                $signal = 'SHORT';
                $trigger = $macdCrossDown ? 'macd_cross_down' : ($rsiCrossDown ? 'rsi_cross_down' : ($kijunBreakDown ? 'kijun_break_down' : 'ichi_price_below'));
            }
        } elseif ($regime === 'range') {
            $path = 'range';
            $long  = ($rsiNow > 50 + $eps) && $bbWidthUp;
            $short = ($rsiNow < 50 - $eps) && $bbWidthUp;

            if ($long)  { $signal = 'LONG';  $trigger = 'range_momentum_up'; }
            if ($short) { $signal = 'SHORT'; $trigger = 'range_momentum_down'; }
        } else {
            if ($emaTrendUp && ($macdCrossUp || $rsiCrossUp || $kijunBreakUp)) {
                $path='trend'; $signal='LONG';  $trigger = $macdCrossUp ? 'macd_cross_up' : ($rsiCrossUp ? 'rsi_cross_up' : 'kijun_break_up');
            } elseif ($emaTrendDown && ($macdCrossDown || $rsiCrossDown || $kijunBreakDown)) {
                $path='trend'; $signal='SHORT'; $trigger = $macdCrossDown ? 'macd_cross_down' : ($rsiCrossDown ? 'rsi_cross_down' : 'kijun_break_down');
            }
        }

        return [
            'ema_fast'  => $emaFast,
            'ema_slow'  => $emaSlow,
            'adx14'     => $adx14,
            'rsi14'     => $rsiNow,
            'macd'      => [
                'macd' => $macdNow,
                'signal' => $sigNow,
                'hist' => $histNow,
                'diff' => $diffNow,
                'prev_diff' => $diffPrev,
            ],
            'bollinger' => $bb,
            'ichimoku'  => [
                'tenkan'   => $ich['tenkan']   ?? 0.0,
                'kijun'    => $ich['kijun']    ?? 0.0,
                'senkou_a' => $ich['senkou_a'] ?? 0.0,
                'senkou_b' => $ich['senkou_b'] ?? 0.0,
            ],
            'regime'  => $regime,
            'path'    => $path,
            'trigger' => $trigger,
            'signal'  => $signal,
        ];
    }
}
