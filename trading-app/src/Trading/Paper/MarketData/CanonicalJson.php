<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class CanonicalJson
{
    private const SERIALIZE_PRECISION_SETTING = 'serialize_precision';
    private const CANONICAL_SERIALIZE_PRECISION = '-1';

    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Encodes normalized PHP values: sequential integer-key arrays follow array_is_list() semantics,
     * non-list arrays are maps, and object instances are unsupported.
     */
    public static function encode(mixed $value): string
    {
        $normalized = self::normalize($value);
        $previousPrecision = self::configureCanonicalSerializePrecision();

        try {
            try {
                return json_encode($normalized, self::ENCODE_FLAGS);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException('paper_canonical_json_encoding_failed', previous: $exception);
            }
        } finally {
            self::restoreSerializePrecision($previousPrecision);
        }
    }

    private static function configureCanonicalSerializePrecision(): string
    {
        $previousPrecision = ini_get(self::SERIALIZE_PRECISION_SETTING);
        if (!\is_string($previousPrecision)) {
            throw new \InvalidArgumentException('paper_canonical_json_serialize_precision_unavailable');
        }

        if ($previousPrecision === self::CANONICAL_SERIALIZE_PRECISION) {
            return $previousPrecision;
        }

        if (
            ini_set(self::SERIALIZE_PRECISION_SETTING, self::CANONICAL_SERIALIZE_PRECISION) === false
            || ini_get(self::SERIALIZE_PRECISION_SETTING) !== self::CANONICAL_SERIALIZE_PRECISION
        ) {
            self::restoreSerializePrecision($previousPrecision);

            throw new \InvalidArgumentException('paper_canonical_json_serialize_precision_configuration_failed');
        }

        return $previousPrecision;
    }

    private static function restoreSerializePrecision(string $precision): void
    {
        if (ini_get(self::SERIALIZE_PRECISION_SETTING) === $precision) {
            return;
        }

        if (
            ini_set(self::SERIALIZE_PRECISION_SETTING, $precision) === false
            || ini_get(self::SERIALIZE_PRECISION_SETTING) !== $precision
        ) {
            throw new \InvalidArgumentException('paper_canonical_json_serialize_precision_restore_failed');
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
