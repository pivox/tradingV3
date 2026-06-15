<?php
declare(strict_types=1);

namespace App\TradingCore\Entry\Service;

use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\Entry\Dto\EntryZoneRequest;

final class EntryZoneCalculator
{
    private const DEFAULT_K_ATR = 0.35;
    private const DEFAULT_W_MIN = 0.0005;
    private const DEFAULT_W_MAX = 0.0100;
    private const DEFAULT_TTL_SEC = 240;

    public function calculate(EntryZoneRequest $request): EntryZone
    {
        $center = $this->resolveCenter($request);
        $kAtr = $this->floatConfig($request->config, 'k_atr')
            ?? $this->floatConfig($request->config, 'offset_k')
            ?? self::DEFAULT_K_ATR;
        $wMin = $this->floatConfig($request->config, 'w_min') ?? self::DEFAULT_W_MIN;
        $wMax = $this->floatConfig($request->config, 'w_max')
            ?? $this->floatConfig($request->config, 'max_deviation_pct')
            ?? self::DEFAULT_W_MAX;
        $ttlSec = $this->intConfig($request->config, 'ttl_sec') ?? self::DEFAULT_TTL_SEC;
        $quantize = (bool)($request->config['quantize_to_exchange_step'] ?? false);

        $halfFromAtr = $request->atr !== null && \is_finite($request->atr) && $request->atr > 0.0
            ? $request->atr * $kAtr
            : 0.0;
        $minHalf = $center * $wMin;
        $maxHalf = $center * $wMax;
        $half = min(max($halfFromAtr, $minHalf), $maxHalf);

        $low = $center - $half;
        $high = $center + $half;
        $wasQuantized = false;
        if ($quantize && $request->tickSize !== null && $request->tickSize > 0.0) {
            $low = $this->quantizeDown($low, $request->tickSize);
            $high = $this->quantizeUp($high, $request->tickSize);
            $wasQuantized = true;
        }

        $widthPct = $center > 0.0 ? ($high - $low) / $center : 0.0;
        $expiresAt = $ttlSec > 0 ? (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $ttlSec)) : null;

        return new EntryZone(
            low: $low,
            high: $high,
            center: $center,
            widthPct: $widthPct,
            ttlSec: $ttlSec,
            expiresAt: $expiresAt,
            source: $this->resolveSource($request),
            atrUsed: $request->atr,
            quantized: $wasQuantized,
            metadata: $request->metadata + [
                'symbol' => $request->symbol,
                'instrument' => $request->instrument,
                'profile' => $request->profile,
                'exchange' => $request->exchange,
                'market_type' => $request->marketType,
                'direction' => $request->direction,
                'execution_timeframe' => $request->executionTimeframe,
                'reference_price' => $request->referencePrice,
                'current_price' => $request->currentPrice,
                'spread_bps' => $request->spreadBps,
                'slippage_bps' => $request->slippageBps,
                'k_atr' => $kAtr,
                'w_min' => $wMin,
                'w_max' => $wMax,
            ],
        );
    }

    private function resolveCenter(EntryZoneRequest $request): float
    {
        if ($request->vwap !== null && \is_finite($request->vwap) && $request->vwap > 0.0) {
            return $request->vwap;
        }

        return $request->referencePrice > 0.0 ? $request->referencePrice : $request->currentPrice;
    }

    private function resolveSource(EntryZoneRequest $request): string
    {
        $anchor = $request->config['anchor'] ?? $request->config['from'] ?? null;
        if (\is_string($anchor) && trim($anchor) !== '') {
            return strtolower(trim($anchor));
        }

        return $request->vwap !== null ? 'vwap' : 'reference_price';
    }

    /**
     * @param array<string,mixed> $config
     */
    private function floatConfig(array $config, string $key): ?float
    {
        return isset($config[$key]) && \is_numeric($config[$key]) ? (float)$config[$key] : null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function intConfig(array $config, string $key): ?int
    {
        return isset($config[$key]) && \is_numeric($config[$key]) ? max(0, (int)$config[$key]) : null;
    }

    private function quantizeDown(float $price, float $tickSize): float
    {
        return $this->normalizePrecision(floor($price / $tickSize) * $tickSize, $tickSize);
    }

    private function quantizeUp(float $price, float $tickSize): float
    {
        return $this->normalizePrecision(ceil($price / $tickSize) * $tickSize, $tickSize);
    }

    private function normalizePrecision(float $price, float $tickSize): float
    {
        $decimals = 0;
        $tick = rtrim(rtrim(sprintf('%.12F', $tickSize), '0'), '.');
        if (str_contains($tick, '.')) {
            $decimals = strlen(substr(strrchr($tick, '.'), 1));
        }

        return round($price, $decimals);
    }
}
