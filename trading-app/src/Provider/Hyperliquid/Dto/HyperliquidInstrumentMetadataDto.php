<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid\Dto;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class HyperliquidInstrumentMetadataDto
{
    /**
     * @param list<string> $qualityFlags
     */
    public function __construct(
        public string $symbol,
        public string $coin,
        public int $assetId,
        public string $priceTick,
        public int $priceMaxDecimals,
        public string $quantityStep,
        public string $minSize,
        public string $maxSize,
        public string $maxLeverage,
        public ?string $fundingRate,
        public ?\DateTimeImmutable $fundingTime,
        public array $qualityFlags,
        public string $status = 'live',
    ) {
    }

    public function isCompleteForSizing(): bool
    {
        foreach ($this->qualityFlags as $flag) {
            if (str_starts_with($flag, 'missing_') || str_starts_with($flag, 'invalid_') || $flag === 'market_suspended') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *     price_valid: bool,
     *     quantity_valid: bool,
     *     price_quantized: string,
     *     quantity_quantized: string,
     *     quality_flags: list<string>
     * }
     */
    public function validateOrderShape(string $price, string $quantity): array
    {
        $priceValid = $this->isMultipleOf($price, $this->priceTick) && $this->isAllowedHyperliquidPrice($price);
        $quantityValid = $this->isMultipleOf($quantity, $this->quantityStep);
        $flags = [];

        if (!$priceValid) {
            $flags[] = 'price_precision_mismatch';
        }
        if (!$quantityValid) {
            $flags[] = 'quantity_rounding_changes_risk';
        }

        return [
            'price_valid' => $priceValid,
            'quantity_valid' => $quantityValid,
            'price_quantized' => $this->quantizePriceDown($price),
            'quantity_quantized' => $this->quantizeDown($quantity, $this->quantityStep),
            'quality_flags' => $flags,
        ];
    }

    private function isMultipleOf(string $value, string $step): bool
    {
        $stepDecimal = BigDecimal::of($step);
        if ($stepDecimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            return false;
        }

        return BigDecimal::of($value)->remainder($stepDecimal)->isZero();
    }

    private function isAllowedHyperliquidPrice(string $price): bool
    {
        $normalized = $this->normalizeDecimal($price);
        $decimalPlaces = $this->decimalPlaces($normalized);
        if ($decimalPlaces > $this->priceMaxDecimals) {
            return false;
        }

        if ($decimalPlaces === 0) {
            return true;
        }

        return $this->significantFigures($normalized) <= 5;
    }

    private function quantizePriceDown(string $price): string
    {
        for ($scale = $this->priceMaxDecimals; $scale >= 0; --$scale) {
            $candidate = $this->quantizeDown($price, $this->stepFromDecimalPlaces($scale));
            if ($this->isAllowedHyperliquidPrice($candidate)) {
                return $candidate;
            }
        }

        return $this->quantizeDown($price, '1');
    }

    private function quantizeDown(string $value, string $step): string
    {
        $stepDecimal = BigDecimal::of($step);
        if ($stepDecimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            return $value;
        }

        $units = BigDecimal::of($value)->dividedBy($stepDecimal, 0, RoundingMode::DOWN);

        return (string) $units
            ->multipliedBy($stepDecimal)
            ->toScale($this->decimalPlaces($step), RoundingMode::UNNECESSARY);
    }

    private function stepFromDecimalPlaces(int $places): string
    {
        return $places === 0 ? '1' : '0.' . str_repeat('0', $places - 1) . '1';
    }

    private function normalizeDecimal(string $value): string
    {
        $normalized = (string) BigDecimal::of($value);
        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }

        return $normalized === '-0' ? '0' : $normalized;
    }

    private function significantFigures(string $value): int
    {
        $digits = str_replace(['-', '.'], '', $value);
        $digits = ltrim($digits, '0');

        return strlen($digits);
    }

    private function decimalPlaces(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr($value, strpos($value, '.') + 1), '0'));
    }
}
