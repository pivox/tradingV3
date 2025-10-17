<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Dto;

/**
 * DTO représentant les données de marché pour l'étape Post-Validation
 */
final class MarketDataDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly float $lastPrice,
        public readonly float $bidPrice,
        public readonly float $askPrice,
        public readonly float $markPrice,
        public readonly float $indexPrice,
        public readonly float $spreadBps,
        public readonly float $depthTopUsd,
        public readonly float $vwap,
        public readonly float $atr1m,
        public readonly float $atr5m,
        public readonly float $rsi1m,
        public readonly float $volumeRatio1m,
        public readonly float $fundingRate,
        public readonly float $openInterest,
        public readonly int $lastUpdateTimestamp,
        public readonly bool $isStale, // Si les données sont obsolètes (>2s)
        public readonly array $contractDetails, // Tick, lot, etc.
        public readonly array $leverageBracket // Limites de levier
    ) {
    }

    public function getMidPrice(): float
    {
        return ($this->bidPrice + $this->askPrice) / 2.0;
    }

    public function getPriceAgeSeconds(): int
    {
        return time() - $this->lastUpdateTimestamp;
    }

    public function isDataFresh(): bool
    {
        return !$this->isStale && $this->getPriceAgeSeconds() <= 2;
    }

    public function getMarkIndexGapBps(): float
    {
        if ($this->indexPrice <= 0) {
            return 0.0;
        }
        return abs(($this->markPrice - $this->indexPrice) / $this->indexPrice) * 10000;
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'last_price' => $this->lastPrice,
            'bid_price' => $this->bidPrice,
            'ask_price' => $this->askPrice,
            'mark_price' => $this->markPrice,
            'index_price' => $this->indexPrice,
            'mid_price' => $this->getMidPrice(),
            'spread_bps' => $this->spreadBps,
            'depth_top_usd' => $this->depthTopUsd,
            'vwap' => $this->vwap,
            'atr_1m' => $this->atr1m,
            'atr_5m' => $this->atr5m,
            'rsi_1m' => $this->rsi1m,
            'volume_ratio_1m' => $this->volumeRatio1m,
            'funding_rate' => $this->fundingRate,
            'open_interest' => $this->openInterest,
            'last_update_timestamp' => $this->lastUpdateTimestamp,
            'price_age_seconds' => $this->getPriceAgeSeconds(),
            'is_stale' => $this->isStale,
            'is_data_fresh' => $this->isDataFresh(),
            'mark_index_gap_bps' => $this->getMarkIndexGapBps(),
            'contract_details' => $this->contractDetails,
            'leverage_bracket' => $this->leverageBracket
        ];
    }
}

