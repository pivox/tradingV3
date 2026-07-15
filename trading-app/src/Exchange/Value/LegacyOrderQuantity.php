<?php

declare(strict_types=1);

namespace App\Exchange\Value;

final readonly class LegacyOrderQuantity
{
    private function __construct(
        public int $integer,
        public string $canonical,
    ) {
    }

    public static function from(int|float|string $value): self
    {
        if (\is_int($value) && $value >= 0) {
            return new self($value, (string) $value);
        }
        if (!\is_string($value) || !preg_match('/^\d+$/', $value)) {
            throw new \InvalidArgumentException('futures_order_legacy_quantity_invalid');
        }

        $canonical = ExactOrderQuantities::canonicalNonNegative($value);
        $integerMax = (string) PHP_INT_MAX;
        if (strlen($canonical) > strlen($integerMax)
            || (strlen($canonical) === strlen($integerMax) && $canonical > $integerMax)) {
            throw new \InvalidArgumentException('futures_order_legacy_quantity_invalid');
        }

        return new self((int) $canonical, $canonical);
    }
}
