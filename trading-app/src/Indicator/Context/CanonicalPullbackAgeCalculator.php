<?php

declare(strict_types=1);

namespace App\Indicator\Context;

final readonly class CanonicalPullbackAgeCalculator
{
    /**
     * @param list<float> $ema9
     * @param list<float> $ema21
     * @param list<float> $closes
     * @param list<float> $vwaps
     */
    public function age(
        array $ema9,
        array $ema21,
        array $closes,
        array $vwaps,
        int $validityBars,
        float $nearVwapTolerance,
    ): ?int {
        $count = count($closes);
        if ($count < 2
            || count($ema9) !== $count
            || count($ema21) !== $count
            || count($vwaps) !== $count
            || $validityBars < 0
            || !is_finite($nearVwapTolerance)
            || $nearVwapTolerance < 0.0
        ) {
            return null;
        }
        foreach ([$ema9, $ema21, $closes, $vwaps] as $series) {
            foreach ($series as $value) {
                if (!is_float($value) || !is_finite($value)) {
                    return null;
                }
            }
        }

        for ($age = 0; $age <= $validityBars && $count - 1 - $age >= 1; ++$age) {
            $index = $count - 1 - $age;
            $crossedUp = $ema9[$index - 1] <= $ema21[$index - 1]
                && $ema9[$index] > $ema21[$index];
            $nearVwap = $vwaps[$index] > 0.0
                && abs(($closes[$index] / $vwaps[$index]) - 1.0) <= $nearVwapTolerance;
            if ($crossedUp || $nearVwap) {
                return $age;
            }
        }

        return null;
    }
}
