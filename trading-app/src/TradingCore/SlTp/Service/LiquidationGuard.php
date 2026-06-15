<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Service;

use App\TradingCore\SlTp\Dto\LiquidationCheckRequest;
use App\TradingCore\SlTp\Dto\LiquidationCheckResult;

final class LiquidationGuard
{
    public function check(LiquidationCheckRequest $request): LiquidationCheckResult
    {
        if ($request->entryPrice <= 0.0 || !\is_finite($request->entryPrice)) {
            throw new \InvalidArgumentException('entryPrice must be positive');
        }

        $direction = $this->direction($request->direction);

        $isStopOnCorrectSide = $direction === 'long'
            ? $request->stopPrice < $request->entryPrice
            : $request->stopPrice > $request->entryPrice;
        if (!$isStopOnCorrectSide) {
            return $this->unsafe($request, null, null, null, 'stop_on_wrong_side_of_entry');
        }

        $stopDistance = abs($request->entryPrice - $request->stopPrice);
        if ($stopDistance <= 0.0 || !\is_finite($stopDistance)) {
            return $this->unsafe($request, null, null, null, 'stop_distance_not_positive');
        }

        $liquidationPrice = $this->resolveLiquidationPrice($request, $direction);
        if ($liquidationPrice === null) {
            return $this->unsafe(
                request: $request,
                liquidationPrice: null,
                liquidationDistancePct: null,
                ratio: null,
                reason: 'insufficient_liquidation_data',
                warnings: ['Liquidation price cannot be derived without leverage or an exchange-provided liquidation price.'],
            );
        }

        $liquidationDistance = abs($request->entryPrice - $liquidationPrice);
        $liquidationDistancePct = $liquidationDistance / $request->entryPrice;
        $ratio = $liquidationDistance / $stopDistance;

        $isBeyondStop = $direction === 'long'
            ? $liquidationPrice < $request->stopPrice
            : $liquidationPrice > $request->stopPrice;
        if (!$isBeyondStop) {
            return $this->unsafe($request, $liquidationPrice, $liquidationDistancePct, $ratio, 'liquidation_not_beyond_stop');
        }

        if ($ratio < $request->minDistanceRatio) {
            return $this->unsafe($request, $liquidationPrice, $liquidationDistancePct, $ratio, 'liquidation_distance_below_min_ratio');
        }

        return new LiquidationCheckResult(
            isSafe: true,
            liquidationPrice: $this->normalize($liquidationPrice),
            liquidationDistancePct: $this->normalize($liquidationDistancePct),
            stopToLiquidationRatio: $this->normalize($ratio),
            warnings: [],
            reasonIfUnsafe: null,
            metadata: $this->metadata($request, $direction),
        );
    }

    private function resolveLiquidationPrice(LiquidationCheckRequest $request, string $direction): ?float
    {
        if ($request->liquidationPrice !== null && $request->liquidationPrice > 0.0 && \is_finite($request->liquidationPrice)) {
            return $request->liquidationPrice;
        }

        if ($request->leverage === null || $request->leverage <= 0) {
            return null;
        }

        $maintenance = $request->maintenanceMarginRate !== null && \is_finite($request->maintenanceMarginRate)
            ? max(0.0, $request->maintenanceMarginRate)
            : 0.0;

        if ($direction === 'long') {
            return $request->entryPrice * max(0.0, 1.0 - (1.0 / $request->leverage) + $maintenance);
        }

        return $request->entryPrice * (1.0 + (1.0 / $request->leverage) - $maintenance);
    }

    /**
     * @param list<string> $warnings
     */
    private function unsafe(
        LiquidationCheckRequest $request,
        ?float $liquidationPrice,
        ?float $liquidationDistancePct,
        ?float $ratio,
        string $reason,
        array $warnings = [],
    ): LiquidationCheckResult {
        return new LiquidationCheckResult(
            isSafe: false,
            liquidationPrice: $liquidationPrice !== null ? $this->normalize($liquidationPrice) : null,
            liquidationDistancePct: $liquidationDistancePct !== null ? $this->normalize($liquidationDistancePct) : null,
            stopToLiquidationRatio: $ratio !== null ? $this->normalize($ratio) : null,
            warnings: $warnings,
            reasonIfUnsafe: $reason,
            metadata: $this->metadata($request, strtolower($request->direction)),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function metadata(LiquidationCheckRequest $request, string $direction): array
    {
        return $request->metadata + [
            'symbol' => $request->symbol,
            'instrument' => $request->instrument,
            'exchange' => $request->exchange,
            'market_type' => $request->marketType,
            'direction' => $direction,
            'entry_price' => $request->entryPrice,
            'stop_price' => $request->stopPrice,
            'leverage' => $request->leverage,
            'maintenance_margin_rate' => $request->maintenanceMarginRate,
            'min_distance_ratio' => $request->minDistanceRatio,
        ];
    }

    private function direction(string $direction): string
    {
        $normalized = strtolower($direction);
        if (!\in_array($normalized, ['long', 'short'], true)) {
            throw new \InvalidArgumentException('direction must be long or short');
        }

        return $normalized;
    }

    private function normalize(float $value): float
    {
        return round($value, 12);
    }
}
