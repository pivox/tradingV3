<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final class EffectiveConfigCanonicalJson
{
    /** @param array<string,mixed> $value */
    public static function encode(array $value): string
    {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (\JsonException | \UnexpectedValueException) {
            throw new \LogicException('effective_config_document_not_canonical');
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \UnexpectedValueException();
            }

            return $value;
        }
        if (!is_array($value)) {
            throw new \UnexpectedValueException();
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $child) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException();
            }
            $value[$key] = self::canonicalize($child);
        }

        return $value;
    }
}
