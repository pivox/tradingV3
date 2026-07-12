<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

final readonly class HyperliquidQuantity
{
    public string $canonical;
    public string $lots;

    public function __construct(float $value, public int $precision, public string $step)
    {
        if (!is_finite($value) || $value < 0.0 || $precision < 0 || $precision > 18) {
            throw new \InvalidArgumentException('hyperliquid_quantity_invalid');
        }

        $expectedStep = self::stepForPrecision($precision);
        if ($step !== $expectedStep) {
            throw new \InvalidArgumentException('hyperliquid_quantity_step_invalid');
        }

        try {
            $decimal = BigDecimal::of((string) $value)->toScale($precision, RoundingMode::UNNECESSARY);
            $lots = $decimal->dividedBy(BigDecimal::of($step), 0, RoundingMode::UNNECESSARY);
        } catch (MathException) {
            throw new \InvalidArgumentException('hyperliquid_quantity_invalid');
        }

        $this->canonical = (string) $decimal;
        $this->lots = (string) $lots;
    }

    public function isZero(): bool
    {
        return $this->lots === '0';
    }

    public function isPositive(): bool
    {
        return !$this->isZero();
    }

    public function compareTo(self $other): int
    {
        $this->assertSameLattice($other);

        return BigInteger::of($this->lots)->compareTo(BigInteger::of($other->lots));
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function toFloat(): float
    {
        return (float) $this->canonical;
    }

    public static function stepForPrecision(int $precision): string
    {
        if ($precision < 0 || $precision > 18) {
            throw new \InvalidArgumentException('hyperliquid_quantity_precision_invalid');
        }

        return $precision === 0 ? '1' : '0.' . str_repeat('0', $precision - 1) . '1';
    }

    private function assertSameLattice(self $other): void
    {
        if ($this->precision !== $other->precision || $this->step !== $other->step) {
            throw new \LogicException('hyperliquid_quantity_lattice_mismatch');
        }
    }
}
