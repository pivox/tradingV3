<?php
declare(strict_types=1);


namespace App\Service\Signals;


use App\Service\Indicator\AtrCalculator;
use App\Service\Risk\PositionSizer;
use Psr\Log\LoggerInterface;


/**
 * MTF signal service for SCALPING v1.2
 *
 * The service expects indicator snapshots per timeframe to be provided by the caller
 * (e.g. EMA/RSI/MACD/Ichimoku/Donchian/VWAP computed elsewhere), and returns an
 * order plan (side, entry hint, SL, TP1, trailing params, time-stop policy).
 */
final class SignalScalpingService
{
    public function __construct(private LoggerInterface $logger) {}

    private function executionOkLong(array $i): bool
    {
// any of 15m/5m/1m micro-conditions
        $m15 = $i['15m'] ?? [];
        $m5 = $i['5m'] ?? [];
        $m1 = $i['1m'] ?? [];


        $ok15 = ($m15['ema_fast_gt_slow'] ?? false)
            && (($m15['macd_hist'] ?? -INF) > 0)
            && ($m15['stochrsi_k_cross_up_d'] ?? false)
            && ($m15['choppiness'] ?? 100) < 61
            && ($m15['close_above_donchian_mid'] ?? false);


        $ok5 = ($m5['ema_fast_gt_slow'] ?? false)
            && (($m5['macd_hist'] ?? -INF) > 0)
            && ($m5['close_above_vwap'] ?? false);


        $ok1 = ($m1['breakout_above_donchian_high'] ?? false)
            || ($m1['retest_vwap_bullish_wick'] ?? false);


        return $ok15 || $ok5 || $ok1;
    }


    private function executionOkShort(array $i): bool
    {
        $m15 = $i['15m'] ?? [];
        $m5 = $i['5m'] ?? [];
        $m1 = $i['1m'] ?? [];


        $ok15 = ($m15['ema_fast_lt_slow'] ?? false)
            && (($m15['macd_hist'] ?? INF) < 0)
            && ($m15['stochrsi_k_cross_down_d'] ?? false)
            && ($m15['choppiness'] ?? 100) < 61
            && ($m15['close_below_donchian_mid'] ?? false);


        $ok5 = ($m5['ema_fast_lt_slow'] ?? false)
            && (($m5['macd_hist'] ?? INF) < 0)
            && ($m5['close_below_vwap'] ?? false);


        $ok1 = ($m1['breakdown_below_donchian_low'] ?? false)
            || ($m1['retest_vwap_bearish_wick'] ?? false);


        return $ok15 || $ok5 || $ok1;
    }


    /** @return array<int, array{high: float, low: float, close: float}> */
    private function extractOhlc(array $i, string $tf): array
    {
// Adapter: caller should populate $i[$tf]['ohlc'] with an array of candles
        $candles = $i[$tf]['ohlc'] ?? [];
        if (!is_array($candles) || count($candles) < 20) {
            throw new \InvalidArgumentException("Insufficient OHLC data for timeframe {$tf}");
        }
        return $candles;
    }
}
