<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Service;

use App\TradingCore\SlTp\Dto\StopLossRequest;
use App\TradingCore\SlTp\Dto\StopLossResult;

final class StopLossCalculator
{
    public function calculate(StopLossRequest $request): StopLossResult
    {
        if ($request->entryPrice <= 0.0 || !\is_finite($request->entryPrice)) {
            throw new \InvalidArgumentException('entryPrice must be positive');
        }

        $direction = $this->direction($request->direction);
        [$stopPrice, $source, $warnings] = $this->resolveStop($request, $direction);
        $this->assertStopSide($direction, $request->entryPrice, $stopPrice);

        $stopDistance = abs($request->entryPrice - $stopPrice);
        $stopPct = $stopDistance / $request->entryPrice;
        if ($stopPct <= 0.0 || !\is_finite($stopPct)) {
            throw new \InvalidArgumentException('stopPct must be positive');
        }

        return new StopLossResult(
            stopPrice: $this->normalize($stopPrice),
            stopPct: $this->normalize($stopPct),
            stopDistance: $this->normalize($stopDistance),
            stopSource: $source,
            isFullSize: $request->slFullSize,
            warnings: $warnings,
            metadata: $request->metadata + [
                'symbol' => $request->symbol,
                'instrument' => $request->instrument,
                'profile' => $request->profile,
                'exchange' => $request->exchange,
                'market_type' => $request->marketType,
                'direction' => $direction,
                'stop_from' => $request->stopFrom,
                'stop_fallback' => $request->stopFallback,
                'atr' => $request->atr,
                'atr_k' => $request->atrK,
                'pivot_price' => $request->pivotPrice,
                'pivot_sl_policy' => $request->pivotSlPolicy,
                'pivot_sl_buffer_pct' => $request->pivotSlBufferPct,
                'pivot_sl_min_keep_ratio' => $request->pivotSlMinKeepRatio,
                'sl_full_size' => $request->slFullSize,
                'position_size' => $request->positionSize,
            ],
        );
    }

    /**
     * @return array{0:float,1:string,2:list<string>}
     */
    private function resolveStop(StopLossRequest $request, string $direction): array
    {
        $stopFrom = strtolower($request->stopFrom);
        $warnings = [];

        if ($stopFrom === 'provided') {
            if ($request->providedStopPrice === null || $request->providedStopPrice <= 0.0 || !\is_finite($request->providedStopPrice)) {
                throw new \InvalidArgumentException('providedStopPrice must be positive when stopFrom=provided');
            }

            return [$request->providedStopPrice, 'provided', $warnings];
        }

        if ($stopFrom === 'pivot') {
            if ($request->pivotPrice !== null && $request->pivotPrice > 0.0 && \is_finite($request->pivotPrice)) {
                $buffer = abs($request->pivotSlBufferPct ?? 0.0);
                $stop = $direction === 'long'
                    ? $request->pivotPrice * (1.0 - $buffer)
                    : $request->pivotPrice * (1.0 + $buffer);

                if ($stop <= 0.0 || !\is_finite($stop)) {
                    throw new \InvalidArgumentException('pivot stop price must be positive; pivotSlBufferPct is too large');
                }

                $maxDistancePct = $request->pivotSlMaxDistancePct;
                if ($maxDistancePct !== null && \is_finite($maxDistancePct) && $maxDistancePct > 0.0) {
                    $distancePct = abs($request->entryPrice - $stop) / $request->entryPrice;
                    if ($distancePct > $maxDistancePct) {
                        $hasAtrInputs = $request->atr !== null && $request->atr > 0.0 && \is_finite($request->atr)
                            && $request->atrK !== null && $request->atrK > 0.0 && \is_finite($request->atrK);
                        if (strtolower((string)$request->stopFallback) === 'atr' && $hasAtrInputs) {
                            $warnings[] = 'Pivot stop exceeds max distance; falling back to ATR stop.';
                            return [$this->atrStop($request, $direction), 'atr_fallback', $warnings];
                        }
                        if (strtolower((string)$request->stopFallback) === 'risk'
                            && $request->providedStopPrice !== null
                            && $request->providedStopPrice > 0.0
                            && \is_finite($request->providedStopPrice)
                        ) {
                            $warnings[] = 'Pivot stop exceeds max distance; falling back to risk stop.';
                            return [$request->providedStopPrice, 'risk_fallback', $warnings];
                        }
                        $warnings[] = sprintf(
                            'Pivot stop distance %.4f exceeds max %.4f but no fallback is available.',
                            $distancePct,
                            $maxDistancePct,
                        );
                    }
                }

                return [$stop, 'pivot', $warnings];
            }

            if (strtolower((string)$request->stopFallback) === 'atr') {
                $warnings[] = 'Pivot stop unavailable; falling back to ATR stop.';

                return [$this->atrStop($request, $direction), 'atr_fallback', $warnings];
            }

            if (strtolower((string)$request->stopFallback) === 'risk') {
                if ($request->providedStopPrice !== null && $request->providedStopPrice > 0.0 && \is_finite($request->providedStopPrice)) {
                    $warnings[] = 'Pivot stop unavailable; falling back to risk stop.';

                    return [$request->providedStopPrice, 'risk_fallback', $warnings];
                }
            }

            throw new \InvalidArgumentException('pivotPrice must be positive when stopFrom=pivot');
        }

        if ($stopFrom === 'atr') {
            return [$this->atrStop($request, $direction), 'atr', $warnings];
        }

        if ($request->providedStopPrice !== null && $request->providedStopPrice > 0.0 && \is_finite($request->providedStopPrice)) {
            $warnings[] = 'Using providedStopPrice for legacy risk stop representation.';

            return [$request->providedStopPrice, 'risk', $warnings];
        }

        throw new \InvalidArgumentException('stop loss source cannot be resolved');
    }

    private function atrStop(StopLossRequest $request, string $direction): float
    {
        if ($request->atr === null || $request->atr <= 0.0 || !\is_finite($request->atr)) {
            throw new \InvalidArgumentException('atr must be positive for ATR stop');
        }
        if ($request->atrK === null || $request->atrK <= 0.0 || !\is_finite($request->atrK)) {
            throw new \InvalidArgumentException('atrK must be positive for ATR stop');
        }

        $distance = $request->atr * $request->atrK;

        $stopPrice = $direction === 'long'
            ? $request->entryPrice - $distance
            : $request->entryPrice + $distance;

        if ($stopPrice <= 0.0 || !\is_finite($stopPrice)) {
            throw new \InvalidArgumentException('atr stop price must be positive; atr * atrK exceeds entry price');
        }

        return $stopPrice;
    }

    private function assertStopSide(string $direction, float $entryPrice, float $stopPrice): void
    {
        if ($direction === 'long' && $stopPrice >= $entryPrice) {
            throw new \InvalidArgumentException('stop loss must be below entry for long positions');
        }
        if ($direction === 'short' && $stopPrice <= $entryPrice) {
            throw new \InvalidArgumentException('stop loss must be above entry for short positions');
        }
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
