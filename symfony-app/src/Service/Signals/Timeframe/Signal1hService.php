<?php
// src/Service/Signals/Timeframe/Signal1hService.php

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
 * Service de signal pour le timeframe 1H (régime-aware).
 *
 * Régime :
 *  - Tendance (ADX >= trend_min_adx) : on privilégie un setup trend-following
 *    (EMA50 > EMA200) ET (MACD > Signal OU Close > Kijun/SenkouA)
 *  - Range (ADX <= range_max_adx) : on privilégie un setup momentum/mean-reversion
 *    (RSI > 50) ET (expansion de volatilité via Bollinger width en hausse)
 *
 * Remarques :
 *  - On applique une petite tolérance EPS sur les comparaisons
 *  - On peut ignorer la dernière bougie non clôturée pour les comparaisons de prix
 *  - Garde-fou de données : minBars (par défaut 220)
 *
 * Sortie :
 *  - ema50, ema200, adx14
 *  - rsi14
 *  - macd { macd, signal, hist }
 *  - bollinger { upper, lower, middle, width }
 *  - ichimoku { tenkan, kijun, senkou_a, senkou_b }
 *  - regime: 'trend'|'range'|'neutral'
 *  - path  : 'trend'|'range'|'neutral' (chemin logique utilisé)
 *  - signal: 'LONG'|'SHORT'|'NONE'
 *  - status?: 'insufficient_data' si historique insuffisant
 */
final class Signal1hService
{
    public function __construct(
        private Ema        $ema,
        private Adx        $adx,
        private Ichimoku   $ichimoku,
        private Rsi        $rsi,
        private Macd       $macd,
        private Bollinger  $bollinger,
        private TradingParameters $params,

        // Défauts robustes si non définis dans le YAML
        private float $defaultEps           = 1.0e-6,
        private bool  $defaultUseLastClosed = true,
        private int   $defaultMinBars       = 220,
        private int   $defaultTrendMinAdx   = 25,  // ADX >= 25 => tendance
        private int   $defaultRangeMaxAdx   = 20,  // ADX <= 20 => range
        private int   $defaultBbLookback    = 3    // pente de width sur N barres
    ) {}

    /**
     * @param Kline[] $candles  Bougies dans l'ordre chronologique (ancienne -> récente)
     */
    public function evaluate(array $candles): array
    {
        // 1) Lecture de la config YAML (sécurisée)
        $cfg = $this->params->getConfig();
        $tf  = $cfg['timeframes']['1h'] ?? [];

        $eps           = $cfg['runtime']['eps']            ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed']?? $this->defaultUseLastClosed;
        $minBars       = $tf['guards']['min_bars']         ?? $this->defaultMinBars;

        $trendMinAdx   = $tf['regime']['trend_min_adx']    ?? $this->defaultTrendMinAdx;
        $rangeMaxAdx   = $tf['regime']['range_max_adx']    ?? $this->defaultRangeMaxAdx;
        $bbLookback    = $tf['regime']['bb_lookback']      ?? $this->defaultBbLookback;

        // 2) Garde-fou de données
        if (count($candles) < $minBars) {
            return [
                'ema50'    => 0.0,
                'ema200'   => 0.0,
                'adx14'    => 0.0,
                'rsi14'    => 0.0,
                'macd'     => ['macd' => 0.0, 'signal' => 0.0, 'hist' => 0.0],
                'bollinger'=> ['upper'=>0.0,'lower'=>0.0,'middle'=>0.0,'width'=>0.0],
                'ichimoku' => ['tenkan'=>0.0,'kijun'=>0.0,'senkou_a'=>0.0,'senkou_b'=>0.0],
                'regime'   => 'neutral',
                'path'     => 'neutral',
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];
        }

        // 3) Extraction des séries OHLC
        $closes = array_map(fn(Kline $k) => $k->getClose(), $candles);
        $highs  = array_map(fn(Kline $k) => $k->getHigh(),  $candles);
        $lows   = array_map(fn(Kline $k) => $k->getLow(),   $candles);

        // 4) Indicateurs principaux
        $ema50   = $this->ema->calculate($closes, 50);
        $ema200  = $this->ema->calculate($closes, 200);
        $adx14   = $this->adx->calculate($highs, $lows, $closes, 14); // Wilder + init first DX

        // Ichimoku (full) – ignore la dernière bougie non close en interne
        $ich = $this->ichimoku->calculateFull($highs, $lows, $closes, 9, 26, 52, 26, true);
        if (!isset($ich['senkou_a']) && isset($ich['tenkan'], $ich['kijun'])) {
            $ich['senkou_a'] = ($ich['tenkan'] + $ich['kijun']) / 2.0; // secours
        }

        // Momentum (RSI)
        $rsi14 = $this->rsi->calculate($closes, 14);

        // MACD (12,26,9) – on accepte calculateFull() ou calculate()
        $macdOut = ['macd' => 0.0, 'signal' => 0.0, 'hist' => 0.0];
        if (method_exists($this->macd, 'calculateFull')) {
            $m = $this->macd->calculateFull($closes, 12, 26, 9);
            // On suppose que calculateFull renvoie des séries ; on prend la dernière
            if (!empty($m['macd']) && !empty($m['signal'])) {
                $macdOut['macd']   = (float) end($m['macd']);
                $macdOut['signal'] = (float) end($m['signal']);
                $macdOut['hist']   = isset($m['hist']) && !empty($m['hist']) ? (float) end($m['hist']) : ($macdOut['macd'] - $macdOut['signal']);
            }
        } elseif (method_exists($this->macd, 'calculate')) {
            $m = $this->macd->calculate($closes, 12, 26, 9);
            $macdOut['macd']   = (float) ($m['macd']   ?? 0.0);
            $macdOut['signal'] = (float) ($m['signal'] ?? 0.0);
            $macdOut['hist']   = (float) ($m['hist']   ?? ($macdOut['macd'] - $macdOut['signal']));
        }

        // Bollinger (20,2) – width = upper - lower ; essayer séries, sinon dernier point
        $bb = ['upper'=>0.0,'lower'=>0.0,'middle'=>0.0,'width'=>0.0];
        $bbWidthSeries = [];
        if (method_exists($this->bollinger, 'calculateFull')) {
            $b = $this->bollinger->calculateFull($closes, 20, 2.0);
            // On suppose que calculateFull renvoie des séries 'upper','lower','middle'
            if (!empty($b['upper']) && !empty($b['lower'])) {
                $nU = count($b['upper']); $nL = count($b['lower']);
                $n  = min($nU, $nL);
                for ($i = 0; $i < $n; $i++) {
                    $bbWidthSeries[] = (float) ($b['upper'][$i] - $b['lower'][$i]);
                }
                // Dernier point
                $bb['upper']  = (float) end($b['upper']);
                $bb['lower']  = (float) end($b['lower']);
                $bb['middle'] = !empty($b['middle']) ? (float) end($b['middle']) : ($bb['upper'] + $bb['lower']) / 2.0;
                $bb['width']  = $bbWidthSeries ? (float) end($bbWidthSeries) : ($bb['upper'] - $bb['lower']);
            }
        } elseif (method_exists($this->bollinger, 'calculate')) {
            $b = $this->bollinger->calculate($closes, 20, 2.0);
            $bb['upper']  = (float) ($b['upper']  ?? 0.0);
            $bb['lower']  = (float) ($b['lower']  ?? 0.0);
            $bb['middle'] = (float) ($b['middle'] ?? (($bb['upper'] + $bb['lower']) / 2.0));
            $bb['width']  = (float) ($b['width']  ?? ($bb['upper'] - $bb['lower']));
            // pas de séries => pas de slope test robuste
        }

        // 5) Close de référence (dernier close clôturé si demandé)
        $lastClose = end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        // 6) Définition du régime
        $regime = 'neutral';
        if ($adx14 >= $trendMinAdx)      { $regime = 'trend'; }
        elseif ($adx14 <= $rangeMaxAdx)  { $regime = 'range'; }

        // 7) Conditions par chemin
        // Trend path (LONG/SHORT)
        $emaTrendUp   = ($ema50 > $ema200 + $eps);
        $emaTrendDown = ($ema200 > $ema50 + $eps);

        $macdUp   = ($macdOut['macd'] > $macdOut['signal'] + $eps);
        $macdDown = ($macdOut['signal'] > $macdOut['macd'] + $eps);

        $ichiBull = ($lastClose > ($ich['kijun'] ?? 0) + $eps) || ($lastClose > ($ich['senkou_a'] ?? 0) + $eps);
        $ichiBear = (($ich['kijun'] ?? PHP_FLOAT_MIN) > $lastClose + $eps) || (($ich['senkou_a'] ?? PHP_FLOAT_MIN) > $lastClose + $eps);

        $trendLong  = $emaTrendUp   && ($macdUp   || $ichiBull);
        $trendShort = $emaTrendDown && ($macdDown || $ichiBear);

        // Range path (LONG/SHORT)
        $rsiAbove50 = ($rsi14 > 50 + $eps);
        $rsiBelow50 = ((50 - $eps) > $rsi14);

        // Pente de la width sur lookback barres (approx : width_now - width_prev > 0)
        $bbWidthUp = false;
        if ($bbWidthSeries && count($bbWidthSeries) > $bbLookback) {
            $now   = (float) end($bbWidthSeries);
            $prevN = $bbWidthSeries[count($bbWidthSeries) - 1 - $bbLookback];
            $bbWidthUp = ($now > $prevN + $eps);
        }
        // Si on n'a pas de séries, on ne bloque pas : on n'utilise pas bbWidthUp comme hard rule.

        $rangeLong  = $rsiAbove50 && $bbWidthUp;
        $rangeShort = $rsiBelow50 && $bbWidthUp;

        // 8) Sélection du chemin selon le régime
        $path   = 'neutral';
        $signal = 'NONE';

        if ($regime === 'trend') {
            $path   = 'trend';
            $signal = $trendLong ? 'LONG' : ($trendShort ? 'SHORT' : 'NONE');
        } elseif ($regime === 'range') {
            $path   = 'range';
            $signal = $rangeLong ? 'LONG' : ($rangeShort ? 'SHORT' : 'NONE');
        } else {
            // neutral : on accepte une validation par l'un OU l'autre chemin
            if ($trendLong || $rangeLong) {
                $path = $trendLong ? 'trend' : 'range';
                $signal = 'LONG';
            } elseif ($trendShort || $rangeShort) {
                $path = $trendShort ? 'trend' : 'range';
                $signal = 'SHORT';
            } else {
                $signal = 'NONE';
            }
        }

        // 9) Retour structuré
        return [
            'ema50'    => $ema50,
            'ema200'   => $ema200,
            'adx14'    => $adx14,
            'rsi14'    => $rsi14,
            'macd'     => $macdOut,
            'bollinger'=> $bb,
            'ichimoku' => [
                'tenkan'   => $ich['tenkan']   ?? 0.0,
                'kijun'    => $ich['kijun']    ?? 0.0,
                'senkou_a' => $ich['senkou_a'] ?? 0.0,
                'senkou_b' => $ich['senkou_b'] ?? 0.0,
            ],
            'regime'   => $regime,
            'path'     => $path,
            'signal'   => $signal,
        ];
    }
}
