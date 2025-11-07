<?php

namespace App\Signal\HighConviction;

use App\Provider\Repository\KlineRepository;
use App\Service\Indicator\AtrCalculator;
use App\Service\Indicator\Volume\Vwap;
use App\Service\Strategy\MacroCalendarService;
use App\Util\SrRiskHelper;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;

/**
 * Version migrée (namespace singulier) du builder HighConvictionMetrics.
 */
final class HighConvictionMetricsBuilder
{
    private const LB_1H   = 260;
    private const LB_15M  = 260;
    private const LB_5M   = 260;
    private const LB_1M   = 400;

    private const ATR_LOOKBACK  = 14;
    private const ATR_METHOD    = 'wilder';
    private const ATR_TIMEFRAME = '5m';
    private const K_STOP        = 1.5;
    private const R_MULTIPLE    = 2.0;
    private const RISK_MAX_PCT  = 0.07;
    private const VWAP_FALLBACK = true;
    private const MACRO_LOOKAHEAD_MINUTES = 120;

    public function __construct(
        private readonly KlineRepository $klineRepository,
        private readonly HighConvictionMetrics $metrics,
        private readonly SrRiskHelper $srRiskHelper,
        private readonly AtrCalculator $atrCalculator,
        private readonly Vwap $vwap,
        private readonly MacroCalendarService $macroCalendar,
        private readonly LoggerInterface $highconviction
    ) {}

    /**
     * @return array{metrics: array, context: array}
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

        $atrSeries = $this->selectAtrSeries($ohlc1h, $ohlc15m, $ohlc5m, $ohlc1m);
        if (count($atrSeries) <= self::ATR_LOOKBACK) {
            $this->highconviction->error('Série ATR insuffisante', [
                'symbol'   => $symbol,
                'tf'       => self::ATR_TIMEFRAME,
                'count'    => count($atrSeries),
                'lookback' => self::ATR_LOOKBACK
            ]);
            return ['metrics' => [], 'context' => []];
        }
        $atrValue = $this->atrCalculator->compute($atrSeries, self::ATR_LOOKBACK, self::ATR_METHOD);

        if ($entry === null) {
            if (self::VWAP_FALLBACK) {
                // Calcul VWAP sur 15m (dernier point)
                $vwap15 = $this->vwap->calculate(
                    array_column($ohlc15m, 'high'),
                    array_column($ohlc15m, 'low'),
                    array_column($ohlc15m, 'close'),
                    array_column($ohlc15m, 'volume')
                );
                $entry  = $vwap15;
                $this->highconviction->info('[HC] Entry fallback via VWAP(15m)', [ 'symbol' => $symbol, 'entry' => $entry ]);
            } else {
                $entry = (float)($ohlc15m[array_key_last($ohlc15m)]['close'] ?? \NAN);
                $this->highconviction->info('[HC] Entry fallback via close(15m)', [ 'symbol' => $symbol, 'entry' => $entry ]);
            }
        }

        $sr = $this->srRiskHelper::findSupportResistance($ohlc15m);
        $sr['atr'] = $atrValue;
        $slRaw = $this->srRiskHelper::chooseSlFromSr(
            $sideUpper,
            $entry,
            $sr['supports'] ?? [],
            $sr['resistances'] ?? [],
            $sr['atr'] ?? null
        );

        if (!is_finite($slRaw)) {
            $k = self::K_STOP;
            $slRaw = ($sideUpper === 'LONG') ? ($entry - $k * $atrValue) : ($entry + $k * $atrValue);
            $this->highconviction->warning('[HC] SL fallback ATR', [
                'symbol' => $symbol, 'entry' => $entry, 'atr' => $atrValue, 'atr_tf' => self::ATR_TIMEFRAME, 'k' => $k, 'sl' => $slRaw
            ]);
        }
        $sl = $slRaw;

        $stopDist = abs($entry - $sl);
        $tp = ($sideUpper === 'LONG') ? ($entry + $rMultiple * $stopDist) : ($entry - $rMultiple * $stopDist);

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
            'atr'        => $atrValue,
            'atr_tf'     => self::ATR_TIMEFRAME,
        ]);

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

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $macroWindow = $this->macroCalendar->evaluateWindow($nowUtc, self::MACRO_LOOKAHEAD_MINUTES, $symbol);
        $metrics['macro_no_event'] = $macroWindow['no_event'];

        $this->highconviction->info('[HC] Metrics construits', [ 'symbol' => $symbol, 'metrics' => $metrics ]);

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
                'sr'        => array_merge($sr, ['atr_tf' => self::ATR_TIMEFRAME]),
                'macro'     => [
                    'checked_at' => $nowUtc->format(DateTimeImmutable::ATOM),
                    'lookahead_minutes' => self::MACRO_LOOKAHEAD_MINUTES,
                    'blocking_event' => $macroWindow['blocking_event'],
                ],
            ],
        ];
    }

    private function fetchOhlcAssoc(string $symbol, string $timeframe, int $lookback): array
    {
        $rows = $this->klineRepository->findRecentBySymbolAndTimeframe($symbol, $timeframe, $lookback);
        $out = [];
        foreach ($rows as $k) {
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

    private function selectAtrSeries(array $ohlc1h, array $ohlc15m, array $ohlc5m, array $ohlc1m): array
    {
        return match (self::ATR_TIMEFRAME) {
            '1h'  => $ohlc1h,
            '15m' => $ohlc15m,
            '5m'  => $ohlc5m,
            '1m'  => $ohlc1m,
            default => $ohlc15m,
        };
    }

    private function lastOrNan(array $series): float
    {
        // Plus utilisée (VWAP retourne déjà un float). Conservée si besoin futur.
        if (empty($series)) return \NAN;
        $last = end($series);
        return \is_array($last) ? (float)reset($last) : (float)$last;
    }
}
