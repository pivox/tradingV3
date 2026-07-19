<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class CanonicalJson
{
    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    public static function encode(mixed $value): string
    {
        try {
            return json_encode(self::normalize($value), self::ENCODE_FLAGS);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('paper_canonical_json_encoding_failed', previous: $exception);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::normalize(...), $value);
            }

            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = self::normalize($item);
            }
            ksort($normalized, SORT_STRING);

            return (object) $normalized;
        }

        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('paper_canonical_json_non_finite_number');
        }

        if (\is_object($value) || \is_resource($value)) {
            throw new \InvalidArgumentException('paper_canonical_json_unsupported_type');
        }

        if ($value === null || \is_bool($value) || \is_int($value) || \is_float($value) || \is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('paper_canonical_json_unsupported_type');
    }
}
