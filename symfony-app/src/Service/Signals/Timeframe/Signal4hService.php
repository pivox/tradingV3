<?php
// src/Service/Signals/Timeframe/Signal4hService.php

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Ema;
use App\Service\Indicator\Trend\Ichimoku;
use Psr\Log\LoggerInterface;

final class Signal4hService
{
    public function __construct(
        private LoggerInterface $validationLogger, // canal 'validation'
        private LoggerInterface $signalsLogger,    // canal 'signals'
        private Ema $ema,
        private Ichimoku $ichimoku,
        private TradingParameters $params,

        // Défauts conformes v1.2
        private float $defaultEps = 1.0e-6,
        private bool  $defaultUseLastClosed = true,
        private int   $defaultMinBars = 220,
        private int   $defaultEmaTrendPeriod = 200,
        private int   $defaultTenkan = 9,
        private int   $defaultKijun = 26,
        private int   $defaultSenkouB = 52,
        private int   $defaultDisplacement = 26
    ) {}

    /**
     * @param Kline[] $candles  anciennes -> récentes
     * @return array{
     *   ema200: float,
     *   ichimoku: array{tenkan:float,kijun:float,senkou_a:float,senkou_b:float},
     *   context_long_ok: bool,
     *   context_short_ok: bool,
     *   signal: string,   // LONG|SHORT|NONE
     *   status?: string
     * }
     */
    public function evaluate(array $candles): array
    {
        // 1) Config YAML (défensive)
        $cfg = $this->params->getConfig();

        $eps           = $cfg['runtime']['eps']              ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed']  ?? $this->defaultUseLastClosed;
        $minBars       = $cfg['timeframes']['4h']['guards']['min_bars'] ?? $this->defaultMinBars;

        $emaTrendPeriod = $cfg['indicators']['ema']['trend'] ?? $this->defaultEmaTrendPeriod; // 200
        $tenkan         = $cfg['indicators']['ichimoku']['tenkan']   ?? $this->defaultTenkan;  // 9
        $kijun          = $cfg['indicators']['ichimoku']['kijun']    ?? $this->defaultKijun;   // 26
        $senkouB        = $cfg['indicators']['ichimoku']['senkou_b'] ?? $this->defaultSenkouB; // 52
        $disp           = $cfg['indicators']['ichimoku']['displacement'] ?? $this->defaultDisplacement; // 26

        // 2) Garde-fou data
        if (count($candles) < $minBars) {
            $validation =  [
                'ema200'   => 0.0,
                'ichimoku' => ['tenkan'=>0.0,'kijun'=>0.0,'senkou_a'=>0.0,'senkou_b'=>0.0],
                'context_long_ok'  => false,
                'context_short_ok' => false,
                'signal'   => 'NONE',
                'status'   => 'insufficient_data',
            ];
            $this->signalsLogger->info('signals.tick', $validation);
            $this->validationLogger->warning('validation.violation', $validation);
            return $validation;

        }

        // 3) Séries
        $closes = array_map(fn(Kline $k) => $k->getClose(), $candles);
        $highs  = array_map(fn(Kline $k) => $k->getHigh(),  $candles);
        $lows   = array_map(fn(Kline $k) => $k->getLow(),   $candles);

        // 4) Valeur de close de référence (bougie clôturée si demandé)
        $lastClose = (float) end($closes);
        if ($useLastClosed && count($closes) > 1) {
            $tmp = $closes; array_pop($tmp);
            $lastClose = (float) end($tmp);
        }

        // 5) Indicateurs requis (v1.2)
        $ema200 = $this->ema->calculate($closes, $emaTrendPeriod);

        // Ichimoku complet
        $ich = $this->ichimoku->calculateFull($highs, $lows, $closes, $tenkan, $kijun, $senkouB, $disp, true);
        // secours senkou_a si absent
        if (!isset($ich['senkou_a']) && isset($ich['tenkan'], $ich['kijun'])) {
            $ich['senkou_a'] = ($ich['tenkan'] + $ich['kijun']) / 2.0;
        }

        $spanA = (float) ($ich['senkou_a'] ?? 0.0);
        $spanB = (float) ($ich['senkou_b'] ?? 0.0);
        $cloudTop    = max($spanA, $spanB);
        $cloudBottom = min($spanA, $spanB);

        // 6) Règles YML
        // LONG  : price_above_ema:200 && ichimoku_bull (close > nuage ET spanA > spanB)
        // SHORT : price_below_ema:200 && ichimoku_bear (close < nuage ET spanA < spanB)
        $priceAboveEma200 = ($lastClose > $ema200 + $eps);
        $priceBelowEma200 = ($ema200 > $lastClose + $eps);

        $ichiBull = ($spanA > $spanB + $eps) && ($lastClose > $cloudTop + $eps);
        $ichiBear = ($spanB > $spanA + $eps) && ($lastClose < $cloudBottom - $eps);

        $longOk  = $priceAboveEma200 && $ichiBull;
        $shortOk = $priceBelowEma200 && $ichiBear;

        $signal = $longOk && !$shortOk ? 'LONG' : ($shortOk && !$longOk ? 'SHORT' : 'NONE');

        $validation = [
            'ema200' => $ema200,
            'ichimoku' => [
                'tenkan'   => (float) ($ich['tenkan']   ?? 0.0),
                'kijun'    => (float) ($ich['kijun']    ?? 0.0),
                'senkou_a' => $spanA,
                'senkou_b' => $spanB,
            ],
            'context_long_ok'  => $longOk,
            'context_short_ok' => $shortOk,
            'signal' => $signal,
        ];
        $this->signalsLogger->info('signals.tick', $validation);
        if ($signal == 'NONE') {
                $this->validationLogger->warning('validation.violation', $validation);
        } else {
            $this->validationLogger->info('validation.ok', $validation);
        }

        return $validation;
    }

    /**
     * Validation binaire du contexte 4H (vrai si LONG ou SHORT valide).
     */
    public function validateContext(array $candles): bool
    {
        $r = $this->evaluate($candles);
        return (bool) (($r['context_long_ok'] ?? false) || ($r['context_short_ok'] ?? false));
    }
}
