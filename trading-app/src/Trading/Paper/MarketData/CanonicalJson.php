<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class CanonicalJson
{
    /** Maximum value occurrences expanded by one direct canonical encode. */
    public const MAX_NODES = 20_000;

    /** Maximum aggregate bytes across expanded string keys and scalar values. */
    public const MAX_BYTES = 1_048_576;

    /** Maximum associative key occurrences expanded by one direct canonical encode. */
    public const MAX_KEYS = 10_000;

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
    public static function encode(#[\SensitiveParameter] mixed $value): string
    {
        $activeArrayReferences = [];
        $nodeCount = 0;
        $byteCount = 0;
        $keyCount = 0;
        $normalized = self::normalize(
            $value,
            0,
            $activeArrayReferences,
            $nodeCount,
            $byteCount,
            $keyCount,
        );
        $previousPrecision = self::configureCanonicalSerializePrecision();

        try {
            try {
                return json_encode($normalized, self::ENCODE_FLAGS);
            } catch (\JsonException) {
                throw new \InvalidArgumentException('paper_canonical_json_encoding_failed');
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
    private static function normalize(
        #[\SensitiveParameter]
        mixed &$value,
        int $depth,
        array &$activeArrayReferences,
        int &$nodeCount,
        int &$byteCount,
        int &$keyCount,
    ): mixed {
        if (\is_array($value)) {
            if ($depth > self::MAX_NESTING_DEPTH) {
                throw new \InvalidArgumentException('paper_canonical_json_depth_exceeded');
            }

            self::consumeNode($nodeCount);
            $isList = array_is_list($value);

            $referenceId = self::arrayReferenceId($value);
            if (isset($activeArrayReferences[$referenceId])) {
                throw new \InvalidArgumentException('paper_canonical_json_cycle_detected');
            }

            $activeArrayReferences[$referenceId] = true;

            try {
                $normalized = [];
                foreach (array_keys($value) as $key) {
                    if (!$isList) {
                        self::consumeKey($keyCount);
                        self::consumeBytes($byteCount, \strlen((string) $key));
                    }

                    $item = &$value[$key];
                    $normalized[$key] = self::normalize(
                        $item,
                        $depth + 1,
                        $activeArrayReferences,
                        $nodeCount,
                        $byteCount,
                        $keyCount,
                    );
                    unset($item);
                }

                if ($isList) {
                    return $normalized;
                }

                if (self::hasContiguousZeroBasedIntegerKeySet($value)) {
                    throw new \InvalidArgumentException('paper_canonical_json_ambiguous_integer_key_map');
                }

                ksort($normalized, SORT_STRING);

                return (object) $normalized;
            } finally {
                unset($activeArrayReferences[$referenceId]);
            }
        }

        self::consumeNode($nodeCount);

        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('paper_canonical_json_non_finite_number');
        }

        if (\is_object($value) || \is_resource($value)) {
            throw new \InvalidArgumentException('paper_canonical_json_unsupported_type');
        }

        if (\is_string($value)) {
            self::consumeBytes($byteCount, \strlen($value));

            return $value;
        }

        if ($value === null) {
            self::consumeBytes($byteCount, 4);

            return null;
        }

        if (\is_bool($value)) {
            self::consumeBytes($byteCount, $value ? 4 : 5);

            return $value;
        }

        if (\is_int($value)) {
            self::consumeBytes($byteCount, \strlen((string) $value));

            return $value;
        }

        if (\is_float($value)) {
            self::consumeBytes($byteCount, 32);

            return $value;
        }

        throw new \InvalidArgumentException('paper_canonical_json_unsupported_type');
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function hasContiguousZeroBasedIntegerKeySet(
        #[\SensitiveParameter] array $value,
    ): bool
    {
        $keyCount = \count($value);
        foreach (array_keys($value) as $key) {
            if (!\is_int($key) || $key < 0 || $key >= $keyCount) {
                return false;
            }
        }

        return $keyCount > 0;
    }

    private static function consumeNode(int &$nodeCount): void
    {
        ++$nodeCount;
        if ($nodeCount > self::MAX_NODES) {
            throw new \InvalidArgumentException('paper_canonical_json_nodes_exceeded');
        }
    }

    private static function consumeBytes(int &$byteCount, int $bytes): void
    {
        if ($bytes > self::MAX_BYTES || $byteCount > self::MAX_BYTES - $bytes) {
            throw new \InvalidArgumentException('paper_canonical_json_bytes_exceeded');
        }

        $byteCount += $bytes;
    }

    private static function consumeKey(int &$keyCount): void
    {
        ++$keyCount;
        if ($keyCount > self::MAX_KEYS) {
            throw new \InvalidArgumentException('paper_canonical_json_keys_exceeded');
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function arrayReferenceId(#[\SensitiveParameter] array &$value): string
    {
        $holder = [&$value];
        $reference = \ReflectionReference::fromArrayElement($holder, 0);
        if ($reference === null) {
            throw new \LogicException('paper_canonical_json_reference_unavailable');
        }

        return bin2hex($reference->getId());
    }
}
