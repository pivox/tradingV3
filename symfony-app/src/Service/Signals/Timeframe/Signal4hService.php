<?php
// src/Service/Signals/Timeframe/Signal4hService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Trend\Adx;
use App\Service\Indicator\Trend\Ichimoku;

/**
 * Service de signal pour le timeframe 4H.
 *
 * Rôle :
 * - Calculer EMA(50/200), ADX(14) (Wilder, init "first DX"), Ichimoku (9/26/52),
 * - Appliquer des règles simples pour retourner un signal : LONG / SHORT / NONE.
 *
 * Hypothèses :
 * - Les bougies ($candles) sont triées de la plus ancienne à la plus récente,
 * - On ignore la dernière bougie potentiellement non clôturée pour l’Ichimoku,
 * - Comparaisons avec tolérance EPS pour éviter le bruit numérique.
 *
 * Règles (simplifiées) :
 * - LONG  si (EMA50 > EMA200) ET (ADX > seuil) ET (Close > Kijun OU Close > SpanA)
 * - SHORT si (EMA200 > EMA50) ET (ADX > seuil) ET (Kijun > Close OU SpanA > Close)
 */
final class Signal4hService
{
    public function __construct(
        private Ema $ema,
        private Adx $adx,
        private Ichimoku $ichimoku,
        private float $eps = 1.0e-6,      // tolérance numérique
        private int   $adxThreshold = 20,  // seuil de force de tendance
        private bool  $useLastClosed = true // utiliser le dernier close clôturé
    ) {}

    /**
     * @param Kline[] $candles  Tableau d'entités Kline (ordre chronologique)
     * @return array{
     *   ema50: float,
     *   ema200: float,
     *   adx14: float,
     *   ichimoku: array{tenkan:float,kijun:float,senkou_a:float,senkou_b:float},
     *   signal: string,
     *   status?: string
     * }
     */
    public function evaluate(array $candles): array
    {
        // Garde-fou : on veut suffisamment d'historique pour EMA200/Ichimoku(52)
        if (count($candles) < 220) {
            return [
                'ema50'    => 0.0,
                'ema200'   => 0.0,
                'adx14'    => 0.0,
                'ichimoku' => ['tenkan' => 0.0, 'kijun' => 0.0, 'senkou_a' => 0.0, 'senkou_b' => 0.0],
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];
        }

        // Extraction des séries numériques depuis les entités Doctrine
        $closes = array_map(fn(Kline $k) => $k->getClose(), $candles);
        $highs  = array_map(fn(Kline $k) => $k->getHigh(),  $candles);
        $lows   = array_map(fn(Kline $k) => $k->getLow(),   $candles);

        // Indicateurs de tendance
        $ema50  = $this->ema->calculate($closes, 50);
        $ema200 = $this->ema->calculate($closes, 200);

        // ADX(14) : Wilder smoothing, init "first DX" (aligné plateformes type BitMart)
        $adx14  = $this->adx->calculate($highs, $lows, $closes, 14);

        // Ichimoku complet : Tenkan/Kijun/SpanA/SpanB (on ignore la bougie non close en interne)
        $ich    = $this->ichimoku->calculateFull($highs, $lows, $closes, 9, 26, 52, 26, true);

        // Close de référence : dernier close clôturé (si demandé)
        $lastClose = end($closes);
        if ($this->useLastClosed && count($closes) > 1) {
            $tmp = $closes;
            array_pop($tmp);              // retire la dernière bougie (potentiellement non clôturée)
            $lastClose = end($tmp);
        }

        // Conditions LONG
        $long =
            ($ema50  > $ema200 + $this->eps) &&
            ($adx14  > $this->adxThreshold) &&
            (
                $lastClose > $ich['kijun']    + $this->eps ||
                $lastClose > $ich['senkou_a'] + $this->eps
            );

        // Conditions SHORT
        $short =
            ($ema200 > $ema50  + $this->eps) &&
            ($adx14  > $this->adxThreshold) &&
            (
                $ich['kijun']    > $lastClose + $this->eps ||
                $ich['senkou_a'] > $lastClose + $this->eps
            );

        $signal = $long ? 'LONG' : ($short ? 'SHORT' : 'NONE');

        return [
            'ema50'    => $ema50,
            'ema200'   => $ema200,
            'adx14'    => $adx14,
            'ichimoku' => [
                'tenkan'   => $ich['tenkan'],
                'kijun'    => $ich['kijun'],
                'senkou_a' => $ich['senkou_a'],
                'senkou_b' => $ich['senkou_b'],
            ],
            'signal'   => $signal,
        ];
    }
}
