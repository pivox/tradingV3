<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use Brick\Math\BigDecimal;

final class HyperliquidPriceStep
{
    private const MAXIMUM_SIGNIFICANT_FIGURES = 5;

    public static function forPrice(BigDecimal $price, BigDecimal $minimumTick): BigDecimal
    {
        if (!$price->isPositive() || !$minimumTick->isPositive()) {
            throw new \InvalidArgumentException('hyperliquid_price_step_input_invalid');
        }
        $normalizedPrice = $price->stripTrailingZeros();
        $orderOfMagnitude = strlen((string) $normalizedPrice->getUnscaledValue())
            - $normalizedPrice->getScale()
            - 1;
        $significantStep = BigDecimal::one()->withPointMovedRight(
            $orderOfMagnitude - self::MAXIMUM_SIGNIFICANT_FIGURES + 1,
        );
        $scale = max($minimumTick->getScale(), $significantStep->getScale());
        $tickUnits = $minimumTick->toScale($scale)->getUnscaledValue();
        $significantUnits = $significantStep->toScale($scale)->getUnscaledValue();
        $commonUnits = $tickUnits
            ->dividedBy($tickUnits->gcd($significantUnits))
            ->multipliedBy($significantUnits);

        return BigDecimal::ofUnscaledValue($commonUnits, $scale)->stripTrailingZeros();
    }

    public static function isValid(BigDecimal $price, BigDecimal $minimumTick): bool
    {
        return $price->isPositive()
            && $minimumTick->isPositive()
            && $price->remainder(self::forPrice($price, $minimumTick))->isZero();
    }
}
