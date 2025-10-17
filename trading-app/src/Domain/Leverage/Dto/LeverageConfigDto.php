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
            $patternRaw = isset($cap['symbol_regex']) && is_string($cap['symbol_regex']) ? $cap['symbol_regex'] : null;
            $capValue = isset($cap['cap']) ? (float) $cap['cap'] : null;
            if ($patternRaw === null || $capValue === null) {
                continue;
            }

            $pattern = $this->normalizeRegex($patternRaw);
            $match = @preg_match($pattern, $symbol);
            if ($match === 1) {
                return $capValue;
            }
        }
        return $this->exchangeCap; // Default to exchange cap if no specific cap found
    }

    private function normalizeRegex(string $pattern): string
    {
        $pattern = trim($pattern);
        // Si déjà délimité (/, #, ~) avec éventuels flags, on ne modifie pas
        if (preg_match('/^([\/\#\~]).*\1[imsxuADSUXJ]*$/', $pattern) === 1) {
            return $pattern;
        }
        // Sinon, on échappe les / et on entoure avec des / ... /i
        $escaped = str_replace('/', '\/', $pattern);
        return '/'.$escaped.'/i';
    }
}

