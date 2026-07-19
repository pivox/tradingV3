<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class CanonicalJson
{
    private const MAX_NESTING_DEPTH = 128;
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
        $activeArrayReferences = [];
        $normalized = self::normalize($value, 0, $activeArrayReferences);
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

        if (!\is_callable('ini_set')) {
            throw new \InvalidArgumentException('paper_canonical_json_serialize_precision_unavailable');
        }

        if (
            \ini_set(self::SERIALIZE_PRECISION_SETTING, self::CANONICAL_SERIALIZE_PRECISION) === false
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

        if (!\is_callable('ini_set')) {
            throw new \InvalidArgumentException('paper_canonical_json_serialize_precision_restore_failed');
        }

        if (
            \ini_set(self::SERIALIZE_PRECISION_SETTING, $precision) === false
            || ini_get(self::SERIALIZE_PRECISION_SETTING) !== $precision
        ) {
            throw new \InvalidArgumentException('paper_canonical_json_serialize_precision_restore_failed');
        }
    }

    /**
     * @param array<string, true> $activeArrayReferences
     */
    private static function normalize(mixed &$value, int $depth, array &$activeArrayReferences): mixed
    {
        if (\is_array($value)) {
            if ($depth > self::MAX_NESTING_DEPTH) {
                throw new \InvalidArgumentException('paper_canonical_json_depth_exceeded');
            }

            $referenceId = self::arrayReferenceId($value);
            if (isset($activeArrayReferences[$referenceId])) {
                throw new \InvalidArgumentException('paper_canonical_json_cycle_detected');
            }

            $activeArrayReferences[$referenceId] = true;

            try {
                $normalized = [];
                foreach (array_keys($value) as $key) {
                    $item = &$value[$key];
                    $normalized[$key] = self::normalize($item, $depth + 1, $activeArrayReferences);
                    unset($item);
                }

                if (array_is_list($value)) {
                    return $normalized;
                }

                ksort($normalized, SORT_STRING);

                return (object) $normalized;
            } finally {
                unset($activeArrayReferences[$referenceId]);
            }
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

    /**
     * @param array<array-key, mixed> $value
     */
    private static function arrayReferenceId(array &$value): string
    {
        $holder = [&$value];
        $reference = \ReflectionReference::fromArrayElement($holder, 0);
        if ($reference === null) {
            throw new \LogicException('paper_canonical_json_reference_unavailable');
        }

        return bin2hex($reference->getId());
    }
}
