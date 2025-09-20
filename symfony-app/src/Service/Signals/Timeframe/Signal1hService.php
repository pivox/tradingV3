<?php
declare(strict_types=1);

namespace App\Service\Signals\Timeframe;

use App\Entity\Kline;
use App\Service\Config\TradingParameters;
use App\Service\Indicator\Trend\Adx;
use App\Service\Indicator\Momentum\Rsi;
use App\Service\Indicator\Volume\Obv;
use Psr\Log\LoggerInterface;

/**
 * Contexte SCALPING v1.2 — Timeframe 1H
 *
 * Règles YAML attendues :
 *  LONG  : ADX(14) >= 18  AND  RSI(14) > 50  AND  OBV slope >= 0
 *  SHORT : ADX(14) >= 18  AND  RSI(14) < 50  AND  OBV slope <= 0
 *
 * Injection des loggers (services.yaml) :
 *  App\Service\Signals\Timeframe\Signal1hService:
 *      arguments:
 *          $validationLogger: '@monolog.logger.validation'
 *          $signalsLogger:    '@monolog.logger.signals'
 */
final class Signal1hService
{
    private const TIMEFRAME = '1h';

    public function __construct(
        private LoggerInterface   $validationLogger, // canal 'validation'
        private LoggerInterface   $signalsLogger,    // canal 'signals'
        private Adx               $adx,
        private Rsi               $rsi,
        private Obv               $obv,
        private TradingParameters $params,

        // Défauts si non précisés en YAML
        private float $defaultEps              = 1.0e-6,
        private bool  $defaultUseLastClosed    = true,
        private int   $defaultMinBars          = 220,
        private int   $defaultAdxPeriod        = 14,
        private int   $defaultAdxMin           = 18,
        private int   $defaultRsiPeriod        = 14,
        private float $defaultRsiBull          = 50.0,
        private float $defaultRsiBear          = 50.0,
        private int   $defaultObvSlopeLookback = 5
    ) {}

    /**
     * @param Kline[] $candles  Bougies du plus ancien au plus récent.
     * @return array{
     *   adx14: float,
     *   rsi14: float,
     *   obv: float,
     *   obv_slope: float,
     *   thresholds: array{adx_min:int,rsi_bull:float,rsi_bear:float,obv_lookback:int},
     *   context_long_ok: bool,
     *   context_short_ok: bool,
     *   signal: string,               // LONG|SHORT|NONE
     *   k_from?: string|null,         // ISO 8601
     *   k_to?: string|null,           // ISO 8601
     *   k_from_ts?: int|null,         // epoch
     *   k_to_ts?: int|null,           // epoch
     *   k_used_count: int,
     *   k_total_count: int,
     *   symbol?: string|null,
     *   step?: int|null,
     *   timeframe: string,
     *   status?: string
     * }
     */
    public function evaluate(array $candles): array
    {
        // 1) Configuration
        $cfg = $this->params->getConfig();

        $eps           = $cfg['runtime']['eps']             ?? $this->defaultEps;
        $useLastClosed = $cfg['runtime']['use_last_closed'] ?? $this->defaultUseLastClosed;
        $minBars       = $cfg['timeframes'][self::TIMEFRAME]['guards']['min_bars'] ?? $this->defaultMinBars;

        $adxPeriod     = $cfg['indicators']['adx']['period'] ?? $this->defaultAdxPeriod;
        $adxMin        = $cfg['indicators']['adx']['min']    ?? $this->defaultAdxMin;

        $rsiPeriod     = $cfg['indicators']['rsi']['period'] ?? $this->defaultRsiPeriod;
        $rsiBull       = $cfg['indicators']['rsi']['bull']   ?? $this->defaultRsiBull;
        $rsiBear       = $cfg['indicators']['rsi']['bear']   ?? $this->defaultRsiBear;

        $obvLookback   = $cfg['indicators']['obv']['slope_lookback'] ?? $this->defaultObvSlopeLookback;

        $totalCount = \count($candles);

        // 2) Garde-fou de quantité
        if ($totalCount < $minBars) {
            $first = $candles[0] ?? null;
            $last  = $candles[$totalCount - 1] ?? null;

            $payload = [
                'adx14' => 0.0,
                'rsi14' => 0.0,
                'obv'   => 0.0,
                'obv_slope' => 0.0,
                'thresholds' => [
                    'adx_min' => $adxMin,
                    'rsi_bull'=> $rsiBull,
                    'rsi_bear'=> $rsiBear,
                    'obv_lookback' => $obvLookback,
                ],
                'context_long_ok'  => false,
                'context_short_ok' => false,
                'signal' => 'NONE',
                'k_from'        => $first?->getTimestamp()?->format(DATE_ATOM),
                'k_to'          => $last?->getTimestamp()?->format(DATE_ATOM),
                'k_from_ts'     => $first?->getTimestamp()?->getTimestamp(),
                'k_to_ts'       => $last?->getTimestamp()?->getTimestamp(),
                'k_used_count'  => 0,
                'k_total_count' => $totalCount,
                'symbol'        => $last?->getContract()?->getSymbol(),
                'step'          => $last?->getStep(),
                'timeframe'     => self::TIMEFRAME,
                'status'        => 'insufficient_data',
            ];

            $this->signalsLogger->info('signals.tick', $payload);
            $this->validationLogger->warning('validation.violation', $payload);

            return $payload;
        }

        // 3) Préparation des séries + fenêtre "used"
        $usedCandles = $candles;
        if ($useLastClosed && \count($usedCandles) > 0) {
            array_pop($usedCandles); // retire la bougie en cours
        }
        $usedCount = \count($usedCandles);

        $closes  = array_map(fn(Kline $k) => $k->getClose(),  $usedCandles);
        $highs   = array_map(fn(Kline $k) => $k->getHigh(),   $usedCandles);
        $lows    = array_map(fn(Kline $k) => $k->getLow(),    $usedCandles);
        $volumes = array_map(fn(Kline $k) => $k->getVolume(), $usedCandles);

        $firstUsed = $usedCandles[0] ?? null;
        $lastUsed  = $usedCandles[$usedCount - 1] ?? null;

        // 4) Indicateurs requis
        $adx14 = (float) $this->adx->calculate($highs, $lows, $closes, $adxPeriod);
        $rsi14 = (float) $this->rsi->calculate($closes, $rsiPeriod);

        // OBV série + pente simple
        $obvSeries = $this->obv->calculateFull($closes, $volumes);
        if (empty($obvSeries)) {
            $obvSeries = [0.0];
        }
        $obvNow     = (float) end($obvSeries);
        $obvPrevIdx = max(\count($obvSeries) - 1 - $obvLookback, 0);
        $obvPrev    = (float) $obvSeries[$obvPrevIdx];
        $obvSlope   = $obvNow - $obvPrev;

        // 5) Règles de contexte
        $adxOk   = ($adx14 >= $adxMin - $eps);
        $longOk  = $adxOk && ($rsi14 > $rsiBull + $eps) && ($obvSlope >= -$eps);
        $shortOk = $adxOk && ($rsi14 < $rsiBear - $eps) && ($obvSlope <= +$eps);

        $signal = 'NONE';
        if ($longOk && !$shortOk) {
            $signal = 'LONG';
        } elseif ($shortOk && !$longOk) {
            $signal = 'SHORT';
        }

        // 6) Charge utile + logs
        $payload = [
            'adx14' => $adx14,
            'rsi14' => $rsi14,
            'obv'   => $obvNow,
            'obv_slope' => $obvSlope,
            'thresholds' => [
                'adx_min' => $adxMin,
                'rsi_bull'=> $rsiBull,
                'rsi_bear'=> $rsiBear,
                'obv_lookback' => $obvLookback,
            ],
            'context_long_ok'  => $longOk,
            'context_short_ok' => $shortOk,
            'signal'           => $signal,

            // bornes de la fenêtre utilisée
            'k_from'        => $firstUsed?->getTimestamp()?->format(DATE_ATOM),
            'k_to'          => $lastUsed?->getTimestamp()?->format(DATE_ATOM),
            'k_from_ts'     => $firstUsed?->getTimestamp()?->getTimestamp(),
            'k_to_ts'       => $lastUsed?->getTimestamp()?->getTimestamp(),
            'k_used_count'  => $usedCount,
            'k_total_count' => $totalCount,

            // méta pratiques
            'symbol'    => $lastUsed?->getContract()?->getSymbol(),
            'step'      => $lastUsed?->getStep(),
            'timeframe' => self::TIMEFRAME,
        ];

        $this->signalsLogger->info('signals.tick', $payload);
        if ($signal === 'NONE') {
            $this->validationLogger->warning('validation.violation', $payload);
        } else {
            $this->validationLogger->info('validation.ok', $payload);
        }

        return $payload;
    }

    /**
     * Validation binaire : vrai si au moins un contexte (long ou short) est valide.
     *
     * @param Kline[] $candles
     */
    public function validateContext(array $candles): bool
    {
        $r = $this->evaluate($candles);
        return (bool) (($r['context_long_ok'] ?? false) || ($r['context_short_ok'] ?? false));
    }
}
