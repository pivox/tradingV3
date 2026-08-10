<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use Brick\Math\BigDecimal;

final class CanonicalPortfolioDecimal
{
    public static function fromFloat(float $value, string $reasonCode): BigDecimal
    {
        if (!\is_finite($value)) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return BigDecimal::of(self::encode($value, $reasonCode));
    }

    public static function encode(mixed $value, string $reasonCode): string
    {
        $previousPrecision = ini_get('serialize_precision');
        if ($previousPrecision === false) {
            throw new CanonicalPortfolioException($reasonCode);
        }
        $changed = $previousPrecision !== '-1';
        if ($changed && ini_set('serialize_precision', '-1') === false) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        $encoded = false;
        $restored = true;
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new CanonicalPortfolioException($reasonCode, [], $exception);
        } finally {
            if ($changed) {
                $restored = ini_set('serialize_precision', $previousPrecision) !== false;
            }
        }
        if (!\is_string($encoded) || !$restored) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return $encoded;
    }

    public static function toFiniteFloat(BigDecimal $value, string $reasonCode): float
    {
        $float = $value->toFloat();
        if (!\is_finite($float)) {
            throw new CanonicalPortfolioException($reasonCode);
        }

        return $float;
    }
}
