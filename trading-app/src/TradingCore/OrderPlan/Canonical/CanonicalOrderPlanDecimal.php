<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use Brick\Math\BigDecimal;

final class CanonicalOrderPlanDecimal
{
    public static function fromFloat(float $value, string $reasonCode): BigDecimal
    {
        if (!\is_finite($value)) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
        $previousPrecision = ini_get('serialize_precision');
        if ($previousPrecision === false) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
        $changed = $previousPrecision !== '-1';
        if ($changed && ini_set('serialize_precision', '-1') === false) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        $encoded = false;
        $restored = true;
        try {
            $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } finally {
            if ($changed) {
                $restored = ini_set('serialize_precision', $previousPrecision) !== false;
            }
        }
        if (!\is_string($encoded) || !$restored) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return BigDecimal::of($encoded);
    }

    public static function encodeCanonicalJson(mixed $value, string $reasonCode): string
    {
        $previousPrecision = ini_get('serialize_precision');
        if ($previousPrecision === false) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
        $changed = $previousPrecision !== '-1';
        if ($changed && ini_set('serialize_precision', '-1') === false) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        $encoded = false;
        $restored = true;
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        } finally {
            if ($changed) {
                $restored = ini_set('serialize_precision', $previousPrecision) !== false;
            }
        }
        if (!\is_string($encoded) || !$restored) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $encoded;
    }
}
