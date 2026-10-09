<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

/** Values only: this object certifies no source venue or execution authority. */
final readonly class CanonicalIndicatorNumericSeries
{
    /**
     * @param list<float> $opens
     * @param list<float> $highs
     * @param list<float> $lows
     * @param list<float> $closes
     * @param list<float> $volumes
     * @param list<int> $timestamps UTC open seconds
     */
    public function __construct(public array $opens, public array $highs, public array $lows, public array $closes, public array $volumes, public array $timestamps)
    {
        foreach ([$opens, $highs, $lows, $closes, $volumes, $timestamps] as $values) {
            if (!array_is_list($values) || count($values) !== 250) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
            }
        }
        foreach ($timestamps as $i => $timestamp) {
            foreach ([$opens[$i], $highs[$i], $lows[$i], $closes[$i], $volumes[$i]] as $value) {
                if (!is_float($value) || !is_finite($value)) {
                    throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
                }
            }
            if (min($opens[$i], $highs[$i], $lows[$i], $closes[$i]) <= 0.0
                || $highs[$i] < max($opens[$i], $closes[$i]) || $lows[$i] > min($opens[$i], $closes[$i])
                || $volumes[$i] < 0.0 || !is_int($timestamp) || $timestamp < 0
                || ($i > 0 && $timestamp <= $timestamps[$i - 1])) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
            }
        }
        $total = array_sum($volumes);
        if (!is_finite($total) || $total <= 0.0) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
        }
    }

    public static function fromCanonicalWindow(CanonicalIndicatorWindow $window): self
    {
        $opens = $highs = $lows = $closes = $volumes = $timestamps = [];
        foreach ($window->candles() as $candle) {
            $opens[] = (float) $candle->open;
            $highs[] = (float) $candle->high;
            $lows[] = (float) $candle->low;
            $closes[] = (float) $candle->close;
            $volumes[] = (float) $candle->volume;
            $timestamps[] = $candle->openTimestamp()->getTimestamp();
        }
        return new self($opens, $highs, $lows, $closes, $volumes, $timestamps);
    }
}
