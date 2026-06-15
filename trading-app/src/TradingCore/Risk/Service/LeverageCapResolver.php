<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Service;

final class LeverageCapResolver
{
    /**
     * @return array{0:float,1:list<string>}
     */
    public function applyCaps(
        float $leverage,
        ?float $exchangeCap,
        ?float $profileCap,
        ?float $symbolCap,
    ): array {
        $capsApplied = [];
        $capped = $leverage;

        if ($exchangeCap !== null && \is_finite($exchangeCap) && $exchangeCap > 0.0) {
            $capsApplied[] = 'exchange_cap';
            $capped = min($capped, $exchangeCap);
        }
        if ($profileCap !== null && \is_finite($profileCap) && $profileCap > 0.0) {
            $capsApplied[] = 'profile_cap';
            $capped = min($capped, $profileCap);
        }
        if ($symbolCap !== null && \is_finite($symbolCap) && $symbolCap > 0.0) {
            $capsApplied[] = 'symbol_cap';
            $capped = min($capped, $symbolCap);
        }

        return [$capped, $capsApplied];
    }
}
