<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Dto;

/**
 * DTO représentant une zone d'entrée calculée
 */
final class EntryZoneDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $side, // LONG | SHORT
        public readonly float $entryMin,
        public readonly float $entryMax,
        public readonly float $zoneWidth,
        public readonly float $vwapAnchor,
        public readonly float $atrValue,
        public readonly float $spreadBps,
        public readonly float $depthTopUsd,
        public readonly bool $qualityPassed,
        public readonly array $evidence, // Métriques de preuve
        public readonly int $timestamp,
        public readonly bool $isValidEntry = true,
        public readonly ?int $cancelAfterTs = null,
        public readonly ?float $suggestedLeverage = null,
        public readonly ?float $suggestedStopPrice = null
    ) {
    }

    public function getMidPrice(): float
    {
        return ($this->entryMin + $this->entryMax) / 2.0;
    }

    public function getZoneWidthBps(): float
    {
        $mid = $this->getMidPrice();
        return $mid > 0 ? (($this->zoneWidth / $mid) * 10000) : 0.0;
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'side' => $this->side,
            'entry_min' => $this->entryMin,
            'entry_max' => $this->entryMax,
            'mid_price' => $this->getMidPrice(),
            'zone_width' => $this->zoneWidth,
            'zone_width_bps' => $this->getZoneWidthBps(),
            'vwap_anchor' => $this->vwapAnchor,
            'atr_value' => $this->atrValue,
            'spread_bps' => $this->spreadBps,
            'depth_top_usd' => $this->depthTopUsd,
            'quality_passed' => $this->qualityPassed,
            'evidence' => $this->evidence,
            'timestamp' => $this->timestamp,
            'is_valid_entry' => $this->isValidEntry,
            'cancel_after_ts' => $this->cancelAfterTs,
            'suggested_leverage' => $this->suggestedLeverage,
            'suggested_stop_price' => $this->suggestedStopPrice
        ];
    }
}

