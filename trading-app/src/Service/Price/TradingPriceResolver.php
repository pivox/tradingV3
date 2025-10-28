<?php

declare(strict_types=1);

namespace App\Service\Price;

use App\Common\Enum\SignalSide;
use App\Provider\Bitmart\Http\BitmartHttpClientPublic;

final class TradingPriceResolver
{
    public function __construct(
        private readonly PriceProviderService $priceProvider,
        private readonly BitmartHttpClientPublic $bitmartClient,
    ) {
    }

    public function resolve(string $symbol, SignalSide $side, ?float $snapshotPrice, ?float $atr): ?TradingPriceResolution
    {
        $normalizedSnapshot = $this->normalizePrice($snapshotPrice);
        $providerPrice = $this->normalizePrice($this->priceProvider->getPrice($symbol) ?? null);
        $atrValue = $atr !== null ? max(0.0, (float) $atr) : null;

        $bestBid = null;
        $bestAsk = null;
        $orderbookPrice = null;

        $relativeDiff = null;
        $allowedDiff = null;
        $forceRealtimeFallback = false;

        $orderbookPrice = $this->priceProvider->getPrice($symbol);

        if ($normalizedSnapshot !== null && $providerPrice !== null) {
            $relativeDiff = abs($providerPrice - $normalizedSnapshot) / max($normalizedSnapshot, 1e-8);
            $atrRatio = ($atrValue !== null && $normalizedSnapshot > 0.0) ? ($atrValue / $normalizedSnapshot) : null;
            $allowedDiff = $atrRatio !== null
                ? max(0.0005, min(0.01, $atrRatio * 0.75))
                : 0.001;

            if ($relativeDiff > $allowedDiff) {
                $forceRealtimeFallback = true;
            }
        }

        if ($providerPrice === null && $normalizedSnapshot === null) {
            $forceRealtimeFallback = true;
        }

        $fallbackPrice = null;
        if ($forceRealtimeFallback) {
            $fallbackPrice = $this->fetchFallbackPrice($symbol);
        }

        if ($fallbackPrice !== null) {
            return new TradingPriceResolution(
                price: $fallbackPrice,
                source: 'bitmart_last_price',
                snapshotPrice: $normalizedSnapshot,
                providerPrice: $providerPrice,
                fallbackPrice: $fallbackPrice,
                bestBid: $bestBid,
                bestAsk: $bestAsk,
                relativeDiff: $relativeDiff,
                allowedDiff: $allowedDiff,
                fallbackEngaged: true,
            );
        }

        if ($orderbookPrice !== null) {
            return new TradingPriceResolution(
                price: $orderbookPrice,
                source: $side === SignalSide::SHORT ? 'orderbook_best_bid' : 'orderbook_best_ask',
                snapshotPrice: $normalizedSnapshot,
                providerPrice: $providerPrice,
                fallbackPrice: $fallbackPrice,
                bestBid: $bestBid,
                bestAsk: $bestAsk,
                relativeDiff: $relativeDiff,
                allowedDiff: $allowedDiff,
                fallbackEngaged: $forceRealtimeFallback,
            );
        }

        if ($providerPrice !== null) {
            return new TradingPriceResolution(
                price: $providerPrice,
                source: 'price_provider',
                snapshotPrice: $normalizedSnapshot,
                providerPrice: $providerPrice,
                fallbackPrice: $fallbackPrice,
                bestBid: $bestBid,
                bestAsk: $bestAsk,
                relativeDiff: $relativeDiff,
                allowedDiff: $allowedDiff,
                fallbackEngaged: $forceRealtimeFallback,
            );
        }

        if ($normalizedSnapshot !== null) {
            return new TradingPriceResolution(
                price: $normalizedSnapshot,
                source: 'mtf_snapshot',
                snapshotPrice: $normalizedSnapshot,
                providerPrice: $providerPrice,
                fallbackPrice: $fallbackPrice,
                bestBid: $bestBid,
                bestAsk: $bestAsk,
                relativeDiff: $relativeDiff,
                allowedDiff: $allowedDiff,
                fallbackEngaged: $forceRealtimeFallback,
            );
        }

        return null;
    }

    private function fetchFallbackPrice(string $symbol): ?float
    {
        try {
            $price = $this->bitmartClient->getLastPrice($symbol);
            return $this->normalizePrice($price);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getLastPrice(string $symbol): ?float
    {
        try {
            $marketTrade = $this->bitmartClient->getMarketTrade($symbol);
            if (
                !\is_array($marketTrade)
                || !is_array($marketTrade['data'])
                || !is_array($marketTrade['data'][0])
                ) {
                return null;
            }

            return (float) ($marketTrade['data'][0]['price'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizePrice(?float $price): ?float
    {
        if ($price === null) {
            return null;
        }

        $normalized = (float) $price;
        if ($normalized <= 0.0 || !is_finite($normalized)) {
            return null;
        }

        return $normalized;
    }
}
