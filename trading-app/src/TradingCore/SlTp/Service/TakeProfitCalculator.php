<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Service;

use App\TradingCore\SlTp\Dto\TakeProfitRequest;
use App\TradingCore\SlTp\Dto\TakeProfitResult;

final class TakeProfitCalculator
{
    public function calculate(TakeProfitRequest $request): TakeProfitResult
    {
        if ($request->entryPrice <= 0.0 || !\is_finite($request->entryPrice)) {
            throw new \InvalidArgumentException('entryPrice must be positive');
        }
        if ($request->rMultiple <= 0.0 || !\is_finite($request->rMultiple)) {
            throw new \InvalidArgumentException('rMultiple must be positive');
        }

        $direction = $this->direction($request->direction);
        $riskDistance = $request->riskDistance ?? abs($request->entryPrice - $request->stopPrice);
        if ($riskDistance <= 0.0 || !\is_finite($riskDistance)) {
            throw new \InvalidArgumentException('riskDistance must be positive');
        }

        // tp1R defaults to rMultiple when absent; tp2 is emitted only when rMultiple is strictly
        // farther than tp1R — same direction, larger R = farther price for both long and short.
        $tp1R = $request->tp1R !== null && $request->tp1R > 0.0 ? $request->tp1R : $request->rMultiple;
        $tp2R = $request->tp1R !== null && $request->tp1R > 0.0 && $request->rMultiple > $tp1R
            ? $request->rMultiple
            : null;

        $tp1Price = $this->priceForR($request->entryPrice, $riskDistance, $direction, $tp1R);
        $tp2Price = $tp2R !== null ? $this->priceForR($request->entryPrice, $riskDistance, $direction, $tp2R) : null;

        // Buffer only applies during pivot alignment — not for pure R-multiple policies.
        $bufferPct = $request->tpBufferPct !== null ? max(0.0, $request->tpBufferPct) : 0.0;
        $isPivotPolicy = str_contains(strtolower($request->tpPolicy), 'pivot');
        if ($bufferPct > 0.0 && $isPivotPolicy) {
            $buffer = $request->entryPrice * $bufferPct;
            $tp1Price = $direction === 'long' ? $tp1Price - $buffer : $tp1Price + $buffer;
            if ($tp2Price !== null) {
                $tp2Price = $direction === 'long' ? $tp2Price - $buffer : $tp2Price + $buffer;
            }

            $effectiveR = $this->effectiveR($request->entryPrice, $tp1Price, $riskDistance, $direction);
            if ($effectiveR < $tp1R * max(0.0, $request->tpMinKeepRatio)) {
                $tp1Price = $this->priceForR($request->entryPrice, $riskDistance, $direction, $tp1R);
                // Suppress TP2 if the buffer left it closer to entry than the restored TP1.
                if ($tp2Price !== null) {
                    $tp2StillFarther = $direction === 'long' ? $tp2Price > $tp1Price : $tp2Price < $tp1Price;
                    if (!$tp2StillFarther) {
                        $tp2Price = null;
                    }
                }
            }
        }

        if ($tp2Price !== null && $request->tpMaxExtraR !== null && $request->tpMaxExtraR >= 0.0) {
            $capR = $request->rMultiple + $request->tpMaxExtraR;
            $tp2EffectiveR = $this->effectiveR($request->entryPrice, $tp2Price, $riskDistance, $direction);
            if ($tp2EffectiveR > $capR) {
                $tp2Price = $this->priceForR($request->entryPrice, $riskDistance, $direction, $capR);
            }
        }

        $expectedR = $this->effectiveR($request->entryPrice, $tp1Price, $riskDistance, $direction);
        $costR = $this->costR($request, $riskDistance);
        $expectedNetR = $costR !== null ? $expectedR - $costR : null;
        $warnings = [];
        if ($expectedNetR !== null && $expectedNetR <= 0.0) {
            $warnings[] = 'expectedNetR is not positive after fees/spread/slippage.';
        }

        return new TakeProfitResult(
            tp1Price: $this->normalize($tp1Price),
            tp2Price: $tp2Price !== null ? $this->normalize($tp2Price) : null,
            expectedR: $this->normalize($expectedR),
            expectedNetR: $expectedNetR !== null ? $this->normalize($expectedNetR) : null,
            tpPolicyApplied: $request->tpPolicy,
            warnings: $warnings,
            metadata: $request->metadata + [
                'symbol' => $request->symbol,
                'instrument' => $request->instrument,
                'profile' => $request->profile,
                'exchange' => $request->exchange,
                'market_type' => $request->marketType,
                'direction' => $direction,
                'risk_distance' => $riskDistance,
                'r_multiple' => $request->rMultiple,
                'tp1_r' => $tp1R,
                'tp_buffer_pct' => $request->tpBufferPct,
                'tp_min_keep_ratio' => $request->tpMinKeepRatio,
                'tp_max_extra_r' => $request->tpMaxExtraR,
                'fees_bps' => $request->feesBps,
                'spread_bps' => $request->spreadBps,
                'slippage_bps' => $request->slippageBps,
            ],
        );
    }

    private function priceForR(float $entry, float $riskDistance, string $direction, float $r): float
    {
        return $direction === 'long'
            ? $entry + $riskDistance * $r
            : $entry - $riskDistance * $r;
    }

    private function effectiveR(float $entry, float $target, float $riskDistance, string $direction): float
    {
        return $direction === 'long'
            ? ($target - $entry) / $riskDistance
            : ($entry - $target) / $riskDistance;
    }

    private function costR(TakeProfitRequest $request, float $riskDistance): ?float
    {
        $costBps = 0.0;
        $hasCost = false;
        foreach ([$request->feesBps, $request->spreadBps, $request->slippageBps] as $cost) {
            if ($cost !== null && \is_finite($cost) && $cost > 0.0) {
                $costBps += $cost;
                $hasCost = true;
            }
        }

        if (!$hasCost) {
            return null;
        }

        return ($request->entryPrice * ($costBps / 10000.0)) / $riskDistance;
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
