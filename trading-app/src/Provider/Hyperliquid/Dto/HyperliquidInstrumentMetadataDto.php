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
        public string $quantityStep,
        public string $minSize,
        public string $maxSize,
        public string $maxLeverage,
        public ?string $fundingRate,
        public ?\DateTimeImmutable $fundingTime,
        public array $qualityFlags,
    ) {
    }

    public function isCompleteForSizing(): bool
    {
        foreach ($this->qualityFlags as $flag) {
            if (str_starts_with($flag, 'missing_') || str_starts_with($flag, 'invalid_')) {
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
        $priceValid = $this->isMultipleOf($price, $this->priceTick);
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
            'price_quantized' => $this->quantizeDown($price, $this->priceTick),
            'quantity_quantized' => $this->quantizeDown($quantity, $this->quantityStep),
            'quality_flags' => $flags,
        ];
    }

    private function isMultipleOf(string $value, string $step): bool
    {
        return BigDecimal::of($value)->remainder(BigDecimal::of($step))->isZero();
    }

    private function quantizeDown(string $value, string $step): string
    {
        $stepDecimal = BigDecimal::of($step);
        $units = BigDecimal::of($value)->dividedBy($stepDecimal, 0, RoundingMode::DOWN);

        return (string) $units
            ->multipliedBy($stepDecimal)
            ->toScale($this->decimalPlaces($step), RoundingMode::UNNECESSARY);
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
