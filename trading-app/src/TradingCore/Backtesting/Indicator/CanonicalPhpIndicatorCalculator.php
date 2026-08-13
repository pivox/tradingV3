<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use App\Indicator\Core\AtrCalculator;
use App\Indicator\Context\CanonicalPullbackAgeCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;

/**
 * Calculates the canonical indicator context without consulting php-trader.
 *
 * Explicit *Php methods mirror the fallback formulas in the corresponding Core
 * services so scalar and series results remain stable across environments.
 */
final readonly class CanonicalPhpIndicatorCalculator
{
    public function __construct(
        private Rsi $rsi,
        private Macd $macd,
        private Ema $ema,
        private Adx $adx,
        private Sma $sma,
        private AtrCalculator $atr,
        private Vwap $vwap,
        private Bollinger $bollinger,
        private CanonicalPullbackAgeCalculator $pullbackAgeCalculator = new CanonicalPullbackAgeCalculator(),
        private CanonicalFiniteSeriesValidator $finiteSeriesValidator = new CanonicalFiniteSeriesValidator(),
    ) {
    }

    /**
     * @return array{
     *     close: float,
     *     high_series: list<float>,
     *     low_series: list<float>,
     *     rsi: float,
     *     ema_20: float,
     *     ema_50: float,
     *     ema_200: float,
     *     macd_hist: float,
     *     vwap: float,
     *     atr: float,
     *     adx: array{14: float, 15: float},
     *     ma9: float,
     *     ma21: float,
     *     bb_upper: float,
     *     bb_middle: float,
     *     bb_lower: float,
     *     ema: array{9: float, 20: float, 21: float, 50: float, 200: float},
     *     ema_prev: array{9: float, 20: float, 21: float, 50: float, 200: float},
     *     ema_200_slope: float,
     *     ema_200_series: list<float>,
     *     ema_200_series_timestamps: list<int>,
     *     macd: array{macd: float, signal: float, hist: float},
     *     macd_hist_series: list<float>,
     *     macd_hist_series_timestamps: list<int>,
     *     macd_line_signal_series: list<float>,
     *     macd_line_signal_series_timestamps: list<int>,
     *     macd_hist_last3: list<float>,
     *     series_order: 'oldest_to_newest',
     *     series_timestamps: list<int>,
     *     pullback_age_bars: int|null,
     *     volume_ratio: float|null,
     *     ma_21_plus_k_atr: float
     * }
     */
    public function calculate(CanonicalIndicatorWindow $window): array
    {
        $closes = $highs = $lows = $volumes = $ohlc = $seriesTimestamps = [];
        foreach ($window->candles() as $candle) {
            $close = (float) $candle->close;
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            $closes[] = $close;
            $highs[] = $high;
            $lows[] = $low;
            $volumes[] = (float) $candle->volume;
            $ohlc[] = ['high' => $high, 'low' => $low, 'close' => $close];
            $seriesTimestamps[] = $candle->openTimestamp()->getTimestamp();
        }
        $totalVolume = array_sum($volumes);
        if (!\is_finite($totalVolume) || $totalVolume <= 0.0) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
        }

        $close = $this->finiteFloat($closes[array_key_last($closes)]);
        $bollinger = $this->bollinger->calculatePhp($closes, 20, 2.0);
        $macdValues = $this->macd->calculatePhp($closes, 12, 26, 9);
        $macd = [
            'macd' => $this->finiteFloat($macdValues['macd']),
            'signal' => $this->finiteFloat($macdValues['signal']),
            'hist' => $this->finiteFloat($macdValues['hist']),
        ];
        $previousCloses = array_slice($closes, 0, -1);
        $ema = [];
        $emaPrev = [];
        foreach ([9, 20, 21, 50, 200] as $period) {
            $ema[$period] = $this->finiteFloat($this->ema->calculatePhp($closes, $period));
            $emaPrev[$period] = $this->finiteFloat($this->ema->calculatePhp($previousCloses, $period));
        }
        $rawMacdFull = $this->macd->calculateFullPhp($closes, 12, 26, 9);
        $macdFull = [
            'macd' => $this->finiteSeriesValidator->validate($rawMacdFull['macd']),
            'signal' => $this->finiteSeriesValidator->validate($rawMacdFull['signal']),
            'hist' => $this->finiteSeriesValidator->validate($rawMacdFull['hist']),
        ];
        $macdHistSeries = array_slice($macdFull['hist'], -60);
        $macdHistSeriesTimestamps = $macdHistSeries === []
            ? []
            : array_slice($seriesTimestamps, -count($macdHistSeries));
        $ema9Series = $this->ema->calculateSeriesPhp($closes, 9);
        $ema21Series = $this->ema->calculateSeriesPhp($closes, 21);
        $vwapSeries = array_values(array_map(
            'floatval',
            $this->vwap->calculateFull($highs, $lows, $closes, $volumes),
        ));
        $pullbackAgeBars = $this->pullbackAgeCalculator->age(
            $ema9Series,
            $ema21Series,
            $closes,
            $vwapSeries,
            100,
            0.0015,
        );
        $atr = $this->finiteFloat($this->atr->computePhp($ohlc, 14));
        $ma21 = $this->finiteFloat($this->sma->calculatePhp($closes, 21));
        $result = [
            'close' => $close,
            'high_series' => array_slice($highs, -60),
            'low_series' => array_slice($lows, -60),
            'rsi' => $this->finiteFloat($this->rsi->calculatePhp($closes, 14)),
            'ema_20' => $ema[20],
            'ema_50' => $ema[50],
            'ema_200' => $ema[200],
            'macd_hist' => $macd['hist'],
            'vwap' => $this->finiteFloat($this->vwap->calculate($highs, $lows, $closes, $volumes)),
            'atr' => $atr,
            'adx' => [
                14 => $this->finiteFloat($this->adx->calculatePhp($highs, $lows, $closes, 14)),
                15 => $this->finiteFloat($this->adx->calculatePhp($highs, $lows, $closes, 15)),
            ],
            'ma9' => $this->finiteFloat($this->sma->calculatePhp($closes, 9)),
            'ma21' => $ma21,
            'bb_upper' => $this->finiteFloat($bollinger['upper']),
            'bb_middle' => $this->finiteFloat($bollinger['middle']),
            'bb_lower' => $this->finiteFloat($bollinger['lower']),
            'ema' => $ema,
            'ema_prev' => $emaPrev,
            'ema_200_slope' => $ema[200] - $emaPrev[200],
            'ema_200_series' => [$emaPrev[200], $ema[200]],
            'ema_200_series_timestamps' => array_slice($seriesTimestamps, -2),
            'macd' => $macd,
            'macd_hist_series' => $macdHistSeries,
            'macd_hist_series_timestamps' => $macdHistSeriesTimestamps,
            'macd_line_signal_series' => $macdHistSeries,
            'macd_line_signal_series_timestamps' => $macdHistSeriesTimestamps,
            'macd_hist_last3' => array_slice($macdHistSeries, -3),
            'series_order' => 'oldest_to_newest',
            'series_timestamps' => $seriesTimestamps,
            'pullback_age_bars' => $pullbackAgeBars,
            'volume_ratio' => $this->volumeRatio($volumes),
            'ma_21_plus_k_atr' => $ma21 + (1.3 * $atr),
        ];

        $this->validateContext($result);

        return $result;
    }

    private function finiteFloat(mixed $value): float
    {
        if (!\is_float($value) || !\is_finite($value)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
        }

        return $value;
    }

    /** @param list<float> $volumes */
    private function volumeRatio(array $volumes): ?float
    {
        if (count($volumes) < 3) {
            return null;
        }
        $current = (float) end($volumes);
        $history = array_values(array_filter(
            array_slice($volumes, -21, 20),
            static fn (float $volume): bool => $volume > 0.0,
        ));
        if ($current <= 0.0 || $history === []) {
            return null;
        }
        $average = array_sum($history) / count($history);

        return $average > 0.0 ? $current / $average : null;
    }

    /** @param array<string|int, mixed> $context */
    private function validateContext(array $context): void
    {
        foreach ($context as $key => $value) {
            if (\is_array($value)) {
                $this->validateContext($value);
                continue;
            }
            if ($value === null && \in_array($key, ['pullback_age_bars', 'volume_ratio'], true)) {
                continue;
            }
            if ($key === 'series_order' && $value === 'oldest_to_newest') {
                continue;
            }
            if (\is_int($value)) {
                continue;
            }
            if (!\is_float($value) || !\is_finite($value)) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
            }
        }
    }
}
