<?php

declare(strict_types=1);

namespace App\Domain\Leverage\Dto;

class LeverageConfigDto
{
    public function __construct(
        public readonly string $mode,
        public readonly float $floor,
        public readonly float $exchangeCap,
        public readonly array $perSymbolCaps,
        public readonly ConfidenceMultiplierConfig $confidenceMultiplier,
        public readonly ConvictionConfig $conviction,
        public readonly RoundingConfig $rounding
    ) {
    }

    public function getSymbolCap(string $symbol): float
    {
        foreach ($this->perSymbolCaps as $cap) {
            if (preg_match($cap['symbol_regex'], $symbol)) {
                return $cap['cap'];
            }
        }
        return $this->exchangeCap; // Default to exchange cap if no specific cap found
    }
}

