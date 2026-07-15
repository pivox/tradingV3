<?php

declare(strict_types=1);

namespace App\Exchange\Value;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;

final readonly class ExactOrderQuantities
{
    private function __construct(
        public string $quantity,
        public string $filled,
        public string $remaining,
        private BigDecimal $quantityValue,
        private BigDecimal $filledValue,
        private BigDecimal $remainingValue,
    ) {
    }

    public static function fromStrings(string $quantity, string $filled, string $remaining): self
    {
        $quantityValue = self::parseNonNegative($quantity);
        $filledValue = self::parseNonNegative($filled);
        $remainingValue = self::parseNonNegative($remaining);
        if ($quantityValue->compareTo(BigDecimal::zero()) <= 0
            || $filledValue->compareTo($quantityValue) > 0
            || $filledValue->plus($remainingValue)->compareTo($quantityValue) !== 0) {
            throw new \InvalidArgumentException('exact_order_quantities_invalid');
        }

        return new self($quantity, $filled, $remaining, $quantityValue, $filledValue, $remainingValue);
    }

    public static function fromQuantityAndFilled(string $quantity, string $filled): self
    {
        $quantityValue = self::parseNonNegative($quantity);
        $filledValue = self::parseNonNegative($filled);
        if ($quantityValue->compareTo(BigDecimal::zero()) <= 0 || $filledValue->compareTo($quantityValue) > 0) {
            throw new \InvalidArgumentException('exact_order_quantities_invalid');
        }

        return self::fromStrings($quantity, $filled, $quantityValue->minus($filledValue)->__toString());
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function fromArray(array $values): ?self
    {
        $keys = ['quantity_decimal', 'filled_quantity_decimal', 'remaining_quantity_decimal'];
        $present = array_filter($keys, static fn (string $key): bool => array_key_exists($key, $values));
        if ($present === []) {
            return null;
        }
        if (count($present) !== count($keys)) {
            throw new \InvalidArgumentException('exact_order_quantities_invalid');
        }
        foreach ($keys as $key) {
            if (!\is_string($values[$key])) {
                throw new \InvalidArgumentException('exact_order_quantities_invalid');
            }
        }

        return self::fromStrings(
            $values['quantity_decimal'],
            $values['filled_quantity_decimal'],
            $values['remaining_quantity_decimal'],
        );
    }

    public static function canonicalNonNegative(string $value): string
    {
        return self::parseNonNegative($value)->__toString();
    }

    /** @return array{quantity_decimal: string, filled_quantity_decimal: string, remaining_quantity_decimal: string} */
    public function toArray(): array
    {
        return [
            'quantity_decimal' => $this->quantity,
            'filled_quantity_decimal' => $this->filled,
            'remaining_quantity_decimal' => $this->remaining,
        ];
    }

    public function quantityValue(): BigDecimal
    {
        return $this->quantityValue;
    }

    public function filledValue(): BigDecimal
    {
        return $this->filledValue;
    }

    public function remainingValue(): BigDecimal
    {
        return $this->remainingValue;
    }

    private static function parseNonNegative(string $value): BigDecimal
    {
        if (!preg_match('/^\d+(?:\.\d{1,18})?$/', $value)) {
            throw new \InvalidArgumentException('exact_order_quantity_invalid');
        }
        $integer = explode('.', $value, 2)[0];
        if (strlen(ltrim($integer, '0')) > 18) {
            throw new \InvalidArgumentException('exact_order_quantity_invalid');
        }

        try {
            return BigDecimal::of($value);
        } catch (NumberFormatException) {
            throw new \InvalidArgumentException('exact_order_quantity_invalid');
        }
    }
}
