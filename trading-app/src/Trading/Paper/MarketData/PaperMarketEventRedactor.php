<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class PaperMarketEventRedactor
{
    /** Maximum number of value occurrences expanded while validating or detaching one payload. */
    public const MAX_PAYLOAD_NODES = 20_000;

    /** Maximum bytes accepted for one payload key before normalization. */
    public const MAX_PAYLOAD_KEY_BYTES = 1_048_576;

    /** Maximum aggregate bytes across string keys and string values in one expanded payload. */
    public const MAX_PAYLOAD_BYTES = 1_048_576;

    /** Maximum bytes accepted for one string value before content scanning. */
    public const MAX_PAYLOAD_STRING_BYTES = 1_048_576;

    /** Maximum nested canonical transformations applied to an encoded string. */
    public const MAX_SENSITIVE_DECODE_DEPTH = 4;

    /** Maximum decoded value occurrences scanned across one payload. */
    public const MAX_SENSITIVE_DECODE_NODES = 4_096;

    /** Maximum aggregate decoded bytes scanned across one payload. */
    public const MAX_SENSITIVE_DECODE_BYTES = 1_048_576;

    private const MAX_NESTING_DEPTH = 128;

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'authorization',
        'api_key',
        'apikey',
        'api_secret',
        'secret_key',
        'passphrase',
        'private_key',
        'sign',
        'signature',
        'wallet',
        'mnemonic',
        'seed_phrase',
        'password',
    ];

    /** @var list<string> Exact normalized aliases used by supported venue clients and common API headers. */
    private const SENSITIVE_KEY_ALIASES = [
        'x_api_key',
        'ok_access_key',
        'ok_access_sign',
        'ok_access_passphrase',
        'api_secret_key',
        'access_token',
        'client_secret',
    ];

    private const SENSITIVE_VALUE_PATTERN = <<<'REGEX'
~(?:
    \bbearer[\t ]+[a-z0-9._\~+/=-]+ |
    ["']?(?:
        authorization |
        api[._\x20-]*key |
        api[._\x20-]*secret |
        secret[._\x20-]*key |
        private[._\x20-]*key |
        access[._\x20-]*token |
        client[._\x20-]*secret |
        passphrase |
        (?<![a-z0-9])sign(?:ature)? |
        wallet |
        mnemonic |
        seed[._\x20-]*phrase |
        password |
        ok[._\x20-]*access[._\x20-]*(?:key|sign|passphrase)
    )["']?[\t ]*[:=]
)~ix
REGEX;

    private const BASIC_VALUE_PATTERN = '~\bbasic[\t ]+([A-Za-z0-9+/]+=*)(?![A-Za-z0-9+/=])~i';

    private const PHP_SERIALIZED_VALUE_PATTERN = <<<'REGEX'
~\A(?:
    N; |
    b:[01]; |
    i:[+-]?\d+; |
    d:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:E[+-]?\d+)?|NAN|[+-]?INF); |
    [sS]:\d+:" |
    a:\d+:\{ |
    O:\d+:" |
    C:\d+:" |
    E:\d+:" |
    [rR]:\d+;
)~xD
REGEX;

    private const PRIVATE_KEY_ENVELOPE_MARKER_PATTERN = <<<'REGEX'
~-----(?:BEGIN|END) (?:[A-Z0-9]+ )*PRIVATE KEY(?: BLOCK)?-----~D
REGEX;

    private const JSON_OBJECT_KEY_PATTERN = '~"((?:[^"\\\\]|\\\\.)*)"[\t\r\n ]*:~uD';

    private const RAW_FORM_KEY_PATTERN = '~\A[A-Za-z0-9_.\~%+\[\]\x80-\xFF-]+\z~D';

    /** @var array<string, string> Cyrillic and Greek cross-script credential-key confusables. */
    private const KEY_CONFUSABLES = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'К' => 'K', 'М' => 'M', 'Н' => 'H', 'О' => 'O',
        'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'а' => 'a', 'в' => 'b', 'е' => 'e',
        'і' => 'i', 'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'р' => 'p', 'с' => 'c',
        'ѕ' => 's', 'т' => 't', 'х' => 'x', 'у' => 'y',
        'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K', 'Μ' => 'M',
        'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X',
        'α' => 'a', 'β' => 'b', 'ε' => 'e', 'η' => 'h', 'ι' => 'i', 'κ' => 'k', 'μ' => 'm',
        'ν' => 'n', 'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'y', 'χ' => 'x',
    ];

    /**
     * @param array<array-key, mixed> $value
     */
    public static function assertSafe(array $value): void
    {
        $activeArrayReferences = [];
        $nodeCount = 0;
        $byteCount = 0;
        $decodedNodeCount = 0;
        $decodedByteCount = 0;
        $decodedStrings = [];
        self::assertSafeArray(
            $value,
            0,
            $activeArrayReferences,
            $nodeCount,
            $byteCount,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, true>     $activeArrayReferences
     * @param array<string, true>     $decodedStrings
     */
    private static function assertSafeArray(
        array &$value,
        int $depth,
        array &$activeArrayReferences,
        int &$nodeCount,
        int &$byteCount,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        self::consumeNode($nodeCount);

        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new \InvalidArgumentException('paper_market_payload_depth_exceeded');
        }

        $referenceId = self::arrayReferenceId($value);
        if (isset($activeArrayReferences[$referenceId])) {
            throw new \InvalidArgumentException('paper_market_payload_cycle_detected');
        }

        $activeArrayReferences[$referenceId] = true;

        try {
            foreach ($value as $key => &$item) {
                if (\is_string($key)) {
                    if (\strlen($key) > self::MAX_PAYLOAD_KEY_BYTES) {
                        throw new \InvalidArgumentException('paper_market_payload_key_too_large');
                    }

                    self::consumeBytes($byteCount, \strlen($key));
                    $normalizedKey = self::normalizeKey($key);
                    if (
                        self::isSensitiveKey($normalizedKey)
                        && !self::isExplicitlySafeMetadata($normalizedKey, $item)
                    ) {
                        throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
                    }
                }

                if (\is_array($item)) {
                    self::assertSafeArray(
                        $item,
                        $depth + 1,
                        $activeArrayReferences,
                        $nodeCount,
                        $byteCount,
                        $decodedNodeCount,
                        $decodedByteCount,
                        $decodedStrings,
                    );
                } else {
                    self::consumeNode($nodeCount);
                    if (\is_string($item)) {
                        self::assertSafeString(
                            $item,
                            $byteCount,
                            $decodedNodeCount,
                            $decodedByteCount,
                            $decodedStrings,
                        );
                    }
                }

                unset($item);
            }
        } finally {
            unset($activeArrayReferences[$referenceId]);
        }
    }

    private static function consumeBytes(int &$byteCount, int $bytes): void
    {
        if ($bytes > self::MAX_PAYLOAD_BYTES || $byteCount > self::MAX_PAYLOAD_BYTES - $bytes) {
            throw new \InvalidArgumentException('paper_market_payload_bytes_exceeded');
        }

        $byteCount += $bytes;
    }

    private static function consumeNode(int &$nodeCount): void
    {
        ++$nodeCount;
        if ($nodeCount > self::MAX_PAYLOAD_NODES) {
            throw new \InvalidArgumentException('paper_market_payload_nodes_exceeded');
        }
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function arrayReferenceId(array &$value): string
    {
        $holder = [&$value];
        $reference = \ReflectionReference::fromArrayElement($holder, 0);
        if ($reference === null) {
            throw new \LogicException('paper_market_payload_reference_unavailable');
        }

        return bin2hex($reference->getId());
    }

    /**
     * @param array<string, true> $decodedStrings
     */
    private static function assertSafeString(
        string $value,
        int &$byteCount,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if (\strlen($value) > self::MAX_PAYLOAD_STRING_BYTES) {
            throw new \InvalidArgumentException('paper_market_payload_string_too_large');
        }

        self::consumeBytes($byteCount, \strlen($value));
        self::scanEncodedString(
            $value,
            0,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
    }

    /**
     * Canonically decodes structured strings one transformation at a time. Valid public
     * containers remain allowed; every decoded key and value is scanned under shared bounds.
     *
     * @param array<string, true> $decodedStrings
     */
    private static function scanEncodedString(
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if ($decodeDepth > self::MAX_SENSITIVE_DECODE_DEPTH) {
            throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
        }

        $fingerprint = hash('sha256', $value);
        if (isset($decodedStrings[$fingerprint])) {
            return;
        }

        $decodedStrings[$fingerprint] = true;
        self::consumeDecodedNode($decodedNodeCount);

        $canonical = self::trimLeadingWhitespaceAndBom($value);
        if ($canonical === '') {
            return;
        }

        self::assertNoPrivateKeyEnvelopeMarkers($canonical);
        self::assertNoBasicCredentials($canonical);
        self::assertNoSensitiveAssignments($canonical);
        self::assertNoSensitiveJsonObjectKeys($canonical);

        $firstByte = $canonical[0];
        if ($firstByte === '{' || $firstByte === '[' || $firstByte === '"') {
            try {
                $decoded = json_decode(
                    $canonical,
                    associative: true,
                    depth: self::MAX_NESTING_DEPTH + 1,
                    flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                );
            } catch (\JsonException $exception) {
                if ($exception->getCode() === JSON_ERROR_DEPTH) {
                    throw new \InvalidArgumentException(
                        'paper_market_sensitive_decode_depth_exceeded',
                        previous: $exception,
                    );
                }

                $decoded = null;
            }

            if (isset($decoded) || $canonical === 'null') {
                $activeArrayReferences = [];
                self::scanDecodedValue(
                    $decoded,
                    $decodeDepth + 1,
                    0,
                    $activeArrayReferences,
                    $decodedNodeCount,
                    $decodedByteCount,
                    $decodedStrings,
                );

                return;
            }
        }

        $serializedMatch = preg_match(self::PHP_SERIALIZED_VALUE_PATTERN, $canonical);
        if ($serializedMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($serializedMatch === 1) {
            self::scanPhpSerializedValue(
                $canonical,
                $decodeDepth,
                $decodedNodeCount,
                $decodedByteCount,
                $decodedStrings,
            );

            return;
        }

        $percentMatch = preg_match('/%[0-9A-Fa-f]{2}/', $canonical);
        if ($percentMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($percentMatch === 1) {
            $decoded = rawurldecode($canonical);
            if ($decoded !== $canonical) {
                self::consumeDecodedBytes($decodedByteCount, \strlen($decoded));
                self::scanEncodedString(
                    $decoded,
                    $decodeDepth + 1,
                    $decodedNodeCount,
                    $decodedByteCount,
                    $decodedStrings,
                );

                return;
            }
        }

        $base64Decoded = self::decodeRecoverableBase64($canonical);
        if ($base64Decoded !== null) {
            self::consumeDecodedBytes($decodedByteCount, \strlen($base64Decoded));
            self::scanEncodedString(
                $base64Decoded,
                $decodeDepth + 1,
                $decodedNodeCount,
                $decodedByteCount,
                $decodedStrings,
            );

            return;
        }

        self::scanFormValue(
            $canonical,
            $decodeDepth,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
    }

    private static function assertNoPrivateKeyEnvelopeMarkers(string $value): void
    {
        $match = preg_match(self::PRIVATE_KEY_ENVELOPE_MARKER_PATTERN, $value);
        if ($match === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($match === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }
    }

    private static function assertNoBasicCredentials(string $value): void
    {
        $matches = [];
        $matchCount = preg_match_all(self::BASIC_VALUE_PATTERN, $value, $matches, PREG_SET_ORDER);
        if ($matchCount === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        foreach ($matches as $match) {
            $decoded = self::decodeRecoverableBase64($match[1], 1, false);
            if ($decoded !== null && str_contains($decoded, ':')) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }
        }
    }

    private static function assertNoSensitiveAssignments(string $value): void
    {
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
        if ($normalized === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        $match = preg_match(self::SENSITIVE_VALUE_PATTERN, strtr($normalized, self::KEY_CONFUSABLES));
        if ($match === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($match === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }
    }

    private static function assertNoSensitiveJsonObjectKeys(string $value): void
    {
        $matches = [];
        $matchCount = preg_match_all(self::JSON_OBJECT_KEY_PATTERN, $value, $matches, PREG_SET_ORDER);
        if ($matchCount === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        foreach ($matches as $match) {
            try {
                $key = json_decode('"' . $match[1] . '"', flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \InvalidArgumentException(
                    'paper_market_sensitive_field_rejected',
                    previous: $exception,
                );
            }

            if (!\is_string($key) || self::isSensitiveKey(self::normalizeKey($key))) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }
        }
    }

    /**
     * @param array<string, true> $decodedStrings
     */
    private static function scanPhpSerializedValue(
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if (preg_match('/\A[OCERr]:/', $value) === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        set_error_handler(static function (): never {
            throw new \UnexpectedValueException('paper_market_serialized_decode_failed');
        });

        try {
            $decoded = unserialize($value, ['allowed_classes' => false]);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException(
                'paper_market_sensitive_field_rejected',
                previous: $exception,
            );
        } finally {
            restore_error_handler();
        }

        $activeArrayReferences = [];
        self::scanDecodedValue(
            $decoded,
            $decodeDepth + 1,
            0,
            $activeArrayReferences,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
    }

    /**
     * @param array<string, true> $activeArrayReferences
     * @param array<string, true> $decodedStrings
     */
    private static function scanDecodedValue(
        mixed &$value,
        int $decodeDepth,
        int $nestingDepth,
        array &$activeArrayReferences,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if ($decodeDepth > self::MAX_SENSITIVE_DECODE_DEPTH || $nestingDepth > self::MAX_NESTING_DEPTH) {
            throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
        }

        self::consumeDecodedNode($decodedNodeCount);

        if (\is_array($value)) {
            $referenceId = self::arrayReferenceId($value);
            if (isset($activeArrayReferences[$referenceId])) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            $activeArrayReferences[$referenceId] = true;

            try {
                foreach ($value as $key => &$item) {
                    if (\is_string($key)) {
                        self::consumeDecodedBytes($decodedByteCount, \strlen($key));
                        $normalizedKey = self::normalizeKey($key);
                        if (
                            self::isSensitiveKey($normalizedKey)
                            && !self::isExplicitlySafeMetadata($normalizedKey, $item)
                        ) {
                            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
                        }
                    }

                    self::scanDecodedValue(
                        $item,
                        $decodeDepth,
                        $nestingDepth + 1,
                        $activeArrayReferences,
                        $decodedNodeCount,
                        $decodedByteCount,
                        $decodedStrings,
                    );
                    unset($item);
                }
            } finally {
                unset($activeArrayReferences[$referenceId]);
            }

            return;
        }

        if (\is_string($value)) {
            self::consumeDecodedBytes($decodedByteCount, \strlen($value));
            self::scanEncodedString(
                $value,
                $decodeDepth,
                $decodedNodeCount,
                $decodedByteCount,
                $decodedStrings,
            );

            return;
        }

        if (\is_object($value) || \is_resource($value)) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }
    }

    /**
     * @param array<string, true> $decodedStrings
     */
    private static function scanFormValue(
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if (!str_contains($value, '=')) {
            return;
        }

        /** @var list<array{string, string}> $pairs */
        $pairs = [];
        foreach (explode('&', $value) as $part) {
            if ($part === '' || !str_contains($part, '=')) {
                return;
            }

            [$rawKey, $rawValue] = explode('=', $part, 2);
            $keyMatch = preg_match(self::RAW_FORM_KEY_PATTERN, $rawKey);
            if ($keyMatch === false) {
                throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
            }

            if ($keyMatch !== 1) {
                return;
            }

            $pairs[] = [urldecode($rawKey), urldecode($rawValue)];
        }

        foreach ($pairs as [$key, $item]) {
            self::consumeDecodedBytes($decodedByteCount, \strlen($key));
            $normalizedKey = self::normalizeKey($key);
            if (
                self::isSensitiveKey($normalizedKey)
                && !self::isExplicitlySafeMetadata($normalizedKey, $item)
            ) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            $activeArrayReferences = [];
            self::scanDecodedValue(
                $item,
                $decodeDepth + 1,
                0,
                $activeArrayReferences,
                $decodedNodeCount,
                $decodedByteCount,
                $decodedStrings,
            );
        }
    }

    private static function decodeRecoverableBase64(
        string $value,
        int $minimumLength = 8,
        bool $allowUrlSafe = true,
    ): ?string {
        if (\strlen($value) < $minimumLength) {
            return null;
        }

        $unpadded = rtrim($value, '=');
        if (\strlen($unpadded) % 4 === 1) {
            return null;
        }

        $requiredPadding = (4 - \strlen($unpadded) % 4) % 4;
        $classicMatch = preg_match('/\A[A-Za-z0-9+\/]+=*\z/D', $value);
        $urlMatch = $allowUrlSafe ? preg_match('/\A[A-Za-z0-9_-]+=*\z/D', $value) : 0;
        if ($classicMatch === false || $urlMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($classicMatch !== 1 && $urlMatch !== 1) {
            return null;
        }

        $standardUnpadded = $urlMatch === 1 ? strtr($unpadded, '-_', '+/') : $unpadded;
        $padded = $standardUnpadded . str_repeat('=', $requiredPadding);
        $decoded = base64_decode($padded, true);
        $canonicalUnpadded = $decoded === false ? null : rtrim(base64_encode($decoded), '=');
        if ($canonicalUnpadded !== null && $urlMatch === 1) {
            $canonicalUnpadded = strtr($canonicalUnpadded, '+/', '-_');
        }

        if (
            $decoded === false
            || $canonicalUnpadded !== $unpadded
            || preg_match('//u', $decoded) !== 1
        ) {
            return null;
        }

        return $decoded;
    }

    private static function consumeDecodedNode(int &$decodedNodeCount): void
    {
        ++$decodedNodeCount;
        if ($decodedNodeCount > self::MAX_SENSITIVE_DECODE_NODES) {
            throw new \InvalidArgumentException('paper_market_sensitive_decode_nodes_exceeded');
        }
    }

    private static function consumeDecodedBytes(int &$decodedByteCount, int $bytes): void
    {
        if (
            $bytes > self::MAX_SENSITIVE_DECODE_BYTES
            || $decodedByteCount > self::MAX_SENSITIVE_DECODE_BYTES - $bytes
        ) {
            throw new \InvalidArgumentException('paper_market_sensitive_decode_bytes_exceeded');
        }

        $decodedByteCount += $bytes;
    }

    private static function trimLeadingWhitespaceAndBom(string $value): string
    {
        $trimmed = ltrim($value);
        while (str_starts_with($trimmed, "\xEF\xBB\xBF")) {
            $trimmed = ltrim(substr($trimmed, 3));
        }

        return $trimmed;
    }

    private static function normalizeKey(string $key): string
    {
        for ($depth = 0; $depth < self::MAX_SENSITIVE_DECODE_DEPTH; ++$depth) {
            $decoded = rawurldecode($key);
            if ($decoded === $key) {
                break;
            }

            $key = $decoded;
        }

        if (rawurldecode($key) !== $key) {
            throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
        }

        $compatibilityNormalized = \Normalizer::normalize($key, \Normalizer::FORM_KC);
        if ($compatibilityNormalized === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if (\strlen($compatibilityNormalized) > self::MAX_PAYLOAD_KEY_BYTES) {
            throw new \InvalidArgumentException('paper_market_payload_key_too_large');
        }

        $key = $compatibilityNormalized;
        $key = strtr($key, self::KEY_CONFUSABLES);
        $nonAsciiMatch = preg_match('/[^\x00-\x7F]/', $key);
        if ($nonAsciiMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($nonAsciiMatch === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $withWordBoundaries = preg_replace(
            [
                '/(?<=[a-z0-9])(?=[A-Z])/',
                '/(?<=[A-Z])(?=[A-Z][a-z])/',
            ],
            '_',
            trim($key),
        );
        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower($withWordBoundaries ?? ''));

        return trim($normalized ?? '', '_');
    }

    private static function isSensitiveKey(string $normalizedKey): bool
    {
        foreach ([self::SENSITIVE_KEYS, self::SENSITIVE_KEY_ALIASES] as $sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitiveKey) {
                if ($sensitiveKey !== 'sign') {
                    if (str_contains($normalizedKey, $sensitiveKey)) {
                        return true;
                    }

                    continue;
                }

                $match = preg_match(
                    '~(?:\A|_)' . preg_quote($sensitiveKey, '~') . '(?:s|[0-9]+)?(?:_|\z)~D',
                    $normalizedKey,
                );
                if ($match === false) {
                    throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
                }

                if ($match === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isExplicitlySafeMetadata(string $normalizedKey, mixed $value): bool
    {
        return match ($normalizedKey) {
            'authorization_status' => \is_bool($value)
                || $value === null
                || \in_array($value, ['not_applicable', 'not_present', 'absent', 'redacted', 'unknown'], true),
            'api_key_hint' => $value === null
                || \in_array($value, ['not_present', 'redacted', 'masked'], true),
            'signature_count' => \is_int($value) && $value >= 0,
            'wallet_balance_model' => \in_array($value, ['unknown', 'not_applicable', 'public_aggregate'], true),
            'seed_phrase_model' => \in_array($value, ['unknown', 'not_applicable'], true),
            default => false,
        };
    }
}
