<?php
declare(strict_types=1);

namespace App\TradingCore\Risk\Service;

use App\TradingCore\Risk\Dto\LeverageCalculationRequest;
use App\TradingCore\Risk\Dto\LeverageCalculationResult;

final class LeverageCalculator
{
    public function __construct(private readonly LeverageCapResolver $capResolver) {}

    public function calculate(LeverageCalculationRequest $request): LeverageCalculationResult
    {
        $warnings = [];
        $rawLeverage = $this->resolveRawLeverage($request);

        $timeframeMultiplier = $this->positiveMultiplierOrDefault($request->timeframeMultiplier);
        $liquidityMultiplier = $this->positiveMultiplierOrDefault($request->liquidityMultiplier);
        $preCapLeverage = $rawLeverage * $timeframeMultiplier * $liquidityMultiplier;

        [$cappedLeverage, $capsApplied] = $this->capResolver->applyCaps(
            leverage: $preCapLeverage,
            exchangeCap: $request->exchangeCap,
            profileCap: $request->profileCap,
            symbolCap: $request->symbolCap,
        );

        $bounded = min(max($cappedLeverage, (float)$request->minLeverage), (float)$request->maxLeverage);

        if ($request->floor !== null && \is_finite($request->floor) && $request->floor > 0.0) {
            // Floor cannot bypass exchange/profile/symbol caps: cap it to cappedLeverage first.
            $bounded = max($bounded, min($request->floor, $cappedLeverage));
        }

        $rounded = $this->round($bounded, $request->roundingMode);
        $rounded = max(1, $rounded);
        $rounded = max($request->minLeverage, $rounded);
        $rounded = min($request->maxLeverage, $rounded);
        // Enforce integer compliance against each configured float cap.
        // Checking individual cap values (not cappedLeverage vs preCapLeverage) handles both
        // the "cap reduced leverage" case and the "raw leverage exactly equals a fractional cap"
        // edge case (e.g. rawLeverage=5.5, exchangeCap=5.5 → ceil=6 must be clamped to 5).
        foreach ([$request->exchangeCap, $request->profileCap, $request->symbolCap] as $cap) {
            if ($cap !== null && \is_finite($cap) && $cap > 0.0 && $rounded > $cap) {
                $rounded = (int)floor($cap);
            }
        }
        $rounded = max(max(1, $request->minLeverage), $rounded);

        if ($request->maxLossPct !== null && \is_finite($request->maxLossPct) && $request->maxLossPct > 0.0) {
            $warnings[] = 'maxLossPct is represented for execution-time size/leverage capping; it is not applied to raw leverage in this preparatory module.';
        }

        return new LeverageCalculationResult(
            rawLeverage: $rawLeverage,
            cappedLeverage: $cappedLeverage,
            finalLeverage: $rounded,
            capsApplied: $capsApplied,
            warnings: $warnings,
            metadata: [
                'timeframe_multiplier' => $timeframeMultiplier,
                'liquidity_multiplier' => $liquidityMultiplier,
                'pre_cap_leverage' => $preCapLeverage,
                'floor' => $request->floor,
                'rounding_mode' => $request->roundingMode,
            ],
        );
    }

    private function resolveRawLeverage(LeverageCalculationRequest $request): float
    {
        if ($request->rawLeverage !== null) {
            if ($request->rawLeverage <= 0.0 || !\is_finite($request->rawLeverage)) {
                throw new \InvalidArgumentException('rawLeverage must be positive');
            }

            return $request->rawLeverage;
        }

        if ($request->stopPct === null || $request->stopPct <= 0.0 || !\is_finite($request->stopPct)) {
            throw new \InvalidArgumentException('stopPct must be positive');
        }
        if ($request->riskPct === null || $request->riskPct <= 0.0 || !\is_finite($request->riskPct)) {
            throw new \InvalidArgumentException('riskPct must be positive');
        }

        return $request->riskPct / $request->stopPct;
    }

    private function positiveMultiplierOrDefault(?float $multiplier): float
    {
        if ($multiplier === null || !\is_finite($multiplier) || $multiplier <= 0.0) {
            return 1.0;
        }

        return $multiplier;
    }

    private function round(float $value, string $mode): int
    {
        return match (strtolower($mode)) {
            'floor' => (int)floor($value),
            'round' => (int)round($value),
            default => (int)ceil($value),
        };
    }
}
