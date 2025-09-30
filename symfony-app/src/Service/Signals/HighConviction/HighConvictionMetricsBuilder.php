<?php

namespace App\Service\Signals\HighConviction;

use App\Repository\KlineRepository;
use App\Service\Indicator\AtrCalculator;
use App\Service\Indicator\Volume\Vwap;
use App\Util\SrRiskHelper;
use Psr\Log\LoggerInterface;

/**
 * Construit les métriques High Conviction en injectant automatiquement les OHLC
 * depuis la base (KlineRepository), puis en déléguant le calcul à HighConvictionMetrics.
 *
 * Étapes :
 *  1) Fetch OHLC (1h, 15m, 5m, 1m) avec lookbacks suffisants.
 *  2) Détermine entry (~mark/limit arrondi via VWAP15m en fallback).
 *  3) Calcule SL depuis S/R + ATR (helper SrRiskHelper).
 *  4) Calcule TP à R-multiple (défaut 2R).
 *  5) Estime un levier “optimal” cohérent avec ta logique (riskMaxPct).
 *  6) Construit $metrics via HighConvictionMetrics->build(...).
 */
final class HighConvictionMetricsBuilder
{
    // Lookbacks par TF (peuvent être ajustés)
    private const LB_1H   = 260;
    private const LB_15M  = 260;
    private const LB_5M   = 260;
    private const LB_1M   = 400;

    // Paramètres par défaut
    private const ATR_LOOKBACK     = 14;
    private const ATR_METHOD       = 'wilder';
    private const K_STOP           = 1.5;   // SL = entry +/- K * ATR
    private const R_MULTIPLE       = 2.0;   // TP = entry +/- R * (entry - SL)
    private const RISK_MAX_PCT     = 0.07;  // 7% sur la marge (comme ton openLimitAutoLevWithSr)
    private const VWAP_FALLBACK    = true;  // si entry non fourni, on utilise VWAP(15m) comme proxy

    public function __construct(
        private readonly KlineRepository $klineRepository,
        private readonly HighConvictionMetrics $metrics,
        private readonly SrRiskHelper $srRiskHelper,
        private readonly AtrCalculator $atrCalculator,
        private readonly Vwap $vwap,
        private readonly LoggerInterface $highconviction // canal monolog "highconviction"
    ) {}

    /**
     * @param string $symbol      Symbole du contrat (ex: "BCHUSDT")
     * @param array  $signals     ctx = contract_pipeline::signals (clés '4h','1h','15m','5m','1m')
     * @param string $sideUpper   'LONG' | 'SHORT' (sens final)
     * @param float|null $entry   Prix d'entrée (mark/limit). Si null → fallback VWAP15m.
     * @param float|null $riskMaxPct  Risque max sur marge (ex: 0.07). Si null → défaut.
     * @param float|null $rMultiple   R-multiple (TP). Si null → défaut.
     *
     * @return array{metrics: array, context: array}
     *         - metrics : tableau consommable par HighConvictionValidation::validate(...)
     *         - context : données intermédiaires utiles au debug (OHLC, entry/sl/tp/levier)
     */
    public function buildForSymbol(
        string $symbol,
        array $signals,
        string $sideUpper,
        ?float $entry = null,
        ?float $riskMaxPct = null,
        ?float $rMultiple = null
    ): array {
        $sideUpper = strtoupper($sideUpper);
        $riskMaxPct = $riskMaxPct ?? self::RISK_MAX_PCT;
        $rMultiple  = $rMultiple  ?? self::R_MULTIPLE;

        // 1) OHLC : charge depuis la base
        $ohlc1h  = $this->fetchOhlcAssoc($symbol, '1h',  self::LB_1H);
        $ohlc15m = $this->fetchOhlcAssoc($symbol, '15m', self::LB_15M);
        $ohlc5m  = $this->fetchOhlcAssoc($symbol, '5m',  self::LB_5M);
        $ohlc1m  = $this->fetchOhlcAssoc($symbol, '1m',  self::LB_1M);

        if (empty($ohlc1h) || empty($ohlc15m) || empty($ohlc5m) || empty($ohlc1m)) {
            $this->highconviction->error('OHLC insuffisants pour construire les métriques HC', [
                'symbol' => $symbol,
                'sizes'  => ['1h'=>count($ohlc1h),'15m'=>count($ohlc15m),'5m'=>count($ohlc5m),'1m'=>count($ohlc1m)]
            ]);
            return ['metrics' => [], 'context' => []];
        }

        // 2) Entry (si absent) : fallback sur VWAP 15m
        if ($entry === null) {
            if (self::VWAP_FALLBACK) {
                $vwap15 = $this->lastOrNan($this->vwap->calculate($ohlc15m));
                $entry  = $vwap15;
                $this->highconviction->info('[HC] Entry fallback via VWAP(15m)', [
                    'symbol' => $symbol,
                    'entry'  => $entry
                ]);
            } else {
                // à défaut, dernier close 15m
                $entry = (float)($ohlc15m[array_key_last($ohlc15m)]['close'] ?? \NAN);
                $this->highconviction->info('[HC] Entry fallback via close(15m)', [
                    'symbol' => $symbol,
                    'entry'  => $entry
                ]);
            }
        }

        // 3) SL depuis S/R + ATR (comme dans ta logique SR)
        //    On récupère S/R via SrRiskHelper (sur 15m), ATR pour la sécurité.
        $sr = $this->srRiskHelper::findSupportResistance($ohlc15m);
        $slRaw = $this->srRiskHelper::chooseSlFromSr(
            $sideUpper,
            $entry,
            $sr['supports'] ?? [],
            $sr['resistances'] ?? [],
            $sr['atr'] ?? null // ton helper peut déjà intégrer l’ATR interne
        );

        // Sécurité si helper ne renvoie rien : SL = entry +/- K*ATR(15m)
        if (!is_finite($slRaw)) {
            $atr15 = $this->atrCalculator->compute($ohlc15m, self::ATR_LOOKBACK, self::ATR_METHOD);
            $k = self::K_STOP;
            $slRaw = ($sideUpper === 'LONG') ? ($entry - $k * $atr15) : ($entry + $k * $atr15);
            $this->highconviction->warning('[HC] SL fallback ATR', [
                'symbol' => $symbol, 'entry' => $entry, 'atr15' => $atr15, 'k' => $k, 'sl' => $slRaw
            ]);
        }
        $sl = $slRaw;

        // 4) TP à R-multiple
        $stopDist = abs($entry - $sl);
        $tp = ($sideUpper === 'LONG')
            ? ($entry + $rMultiple * $stopDist)
            : ($entry - $rMultiple * $stopDist);

        // 5) Levier optimal (identique à ta logique : lev_opt = RiskMax% / stop%)
        $stopPct = ($stopDist / max(1e-12, $entry));
        $levOpt  = ($stopPct > 0.0) ? ($riskMaxPct / $stopPct) : 1.0;

        $this->highconviction->info('[HC] Pré-métriques', [
            'symbol'     => $symbol,
            'side'       => $sideUpper,
            'entry'      => $entry,
            'sl'         => $sl,
            'tp'         => $tp,
            'stop_pct'   => $stopPct,
            'lev_opt'    => $levOpt,
            'risk_max%'  => $riskMaxPct,
            'r_multiple' => $rMultiple,
        ]);

        // 6) Construction finale des métriques via HighConvictionMetrics
        $metrics = $this->metrics->build(
            signals:  $signals,
            ohlc1h:   $ohlc1h,
            ohlc15m:  $ohlc15m,
            ohlc5m:   $ohlc5m,
            ohlc1m:   $ohlc1m,
            sideUpper:$sideUpper,
            entry:    $entry,
            sl:       $sl,
            tp:       $tp,
            leverage: $levOpt
        );

        $this->highconviction->info('[HC] Metrics construits', [
            'symbol'   => $symbol,
            'metrics'  => $metrics
        ]);

        return [
            'metrics' => $metrics,
            'context' => [
                'ohlc_1h'   => $ohlc1h,
                'ohlc_15m'  => $ohlc15m,
                'ohlc_5m'   => $ohlc5m,
                'ohlc_1m'   => $ohlc1m,
                'entry'     => $entry,
                'sl'        => $sl,
                'tp'        => $tp,
                'lev_opt'   => $levOpt,
                'risk_max%' => $riskMaxPct,
                'r_multiple'=> $rMultiple,
                'sr'        => $sr,
            ],
        ];
    }

    /**
     * Récupère des OHLC (associatifs) pour un symbole et un TF.
     * Le repository expose déjà `findRecentBySymbolAndTimeframe($symbol, $timeframe, $lookback)`.
     *
     * @return array<int,array{open:float,high:float,low:float,close:float,volume:float,timestamp:int}>
     */
    private function fetchOhlcAssoc(string $symbol, string $timeframe, int $lookback): array
    {
        $rows = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, $timeframe, $lookback);
        // On normalise en tableau associatif simple (open, high, low, close, volume, timestamp)
        $out = [];
        foreach ($rows as $k) {
            // $k peut être une entité Kline OU un array selon ton repo ; on gère les deux
            $open      = \is_object($k) ? $k->getOpen()  : (float)($k['open']   ?? \NAN);
            $high      = \is_object($k) ? $k->getHigh()  : (float)($k['high']   ?? \NAN);
            $low       = \is_object($k) ? $k->getLow()   : (float)($k['low']    ?? \NAN);
            $close     = \is_object($k) ? $k->getClose() : (float)($k['close']  ?? \NAN);
            $volume    = \is_object($k) ? $k->getVolume(): (float)($k['volume'] ?? \NAN);
            $timestamp = \is_object($k)
                ? (int)$k->getTimestamp()->getTimestamp()
                : (int)($k['timestamp'] ?? 0);

            $out[] = compact('open','high','low','close','volume','timestamp');
        }
        return $out;
    }

    private function lastOrNan(array $series): float
    {
        if (empty($series)) return \NAN;
        $last = end($series);
        return \is_array($last) ? (float)reset($last) : (float)$last;
    }
}
