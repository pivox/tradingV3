<?php

declare(strict_types=1);

namespace App\TradingCore\Backtesting\Indicator;

final readonly class CanonicalFiniteSeriesValidator
{
    /**
     * @param array<array-key, mixed> $series
     * @return list<float>
     */
    public function validate(array $series): array
    {
        if (!array_is_list($series)) {
            throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
        }

        $validated = [];
        foreach ($series as $value) {
            if (!\is_float($value) || !\is_finite($value)) {
                throw new CanonicalIndicatorProjectionException('canonical_indicator_calculation_invalid');
            }
            $validated[] = $value;
        }

        return $validated;
    }
}
