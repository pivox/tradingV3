<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;

/**
 * Calculates the canonical scalar indicator set without consulting php-trader.
 *
 * The private *Php methods mirror the fallback formulas in the corresponding
 * Core services so results remain stable regardless of installed extensions.
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
    ) {
    }

    /**
     * @return array{
     *     close: float,
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
     *     bb_lower: float
     * }
     */
    public function calculate(CanonicalIndicatorWindow $window): array
    {
        $closes = $highs = $lows = $volumes = $ohlc = [];
        foreach ($window->candles() as $candle) {
            $close = (float) $candle->close;
            $high = (float) $candle->high;
            $low = (float) $candle->low;
            $closes[] = $close;
            $highs[] = $high;
            $lows[] = $low;
            $volumes[] = (float) $candle->volume;
            $ohlc[] = ['high' => $high, 'low' => $low, 'close' => $close];
        }

        $close = $closes === [] ? null : $closes[array_key_last($closes)];
        $bollinger = $this->bollinger->calculatePhp($closes, 20, 2.0);
        $macd = $this->macd->calculatePhp($closes, 12, 26, 9);
        $result = [
            'close' => $close,
            'rsi' => $this->rsi->calculatePhp($closes, 14),
            'ema_20' => $this->ema->calculatePhp($closes, 20),
            'ema_50' => $this->ema->calculatePhp($closes, 50),
            'ema_200' => $this->ema->calculatePhp($closes, 200),
            'macd_hist' => $macd['hist'],
            'vwap' => $this->vwap->calculate($highs, $lows, $closes, $volumes),
            'atr' => $this->atr->computePhp($ohlc, 14),
            'adx' => [
                14 => $this->adx->calculatePhp($highs, $lows, $closes, 14),
                15 => $this->adx->calculatePhp($highs, $lows, $closes, 15),
            ],
            'ma9' => $this->sma->calculatePhp($closes, 9),
            'ma21' => $this->sma->calculatePhp($closes, 21),
            'bb_upper' => $bollinger['upper'],
            'bb_middle' => $bollinger['middle'],
            'bb_lower' => $bollinger['lower'],
        ];

        foreach ($result as $value) {
            $values = \is_array($value) ? $value : [$value];
            foreach ($values as $scalar) {
                if (!\is_float($scalar) || !\is_finite($scalar)) {
                    throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
                }
            }
        }

        /** @var array{close: float, rsi: float, ema_20: float, ema_50: float, ema_200: float, macd_hist: float, vwap: float, atr: float, adx: array{14: float, 15: float}, ma9: float, ma21: float, bb_upper: float, bb_middle: float, bb_lower: float} $result */
        return $result;
    }

}
