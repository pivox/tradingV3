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
    )["']?[\x09-\x0D ]*[:=]
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

    private const RAW_FORM_KEY_PATTERN = '~\A[A-Za-z0-9_.\~%+\[\]\x80-\xFF-]+\z~D';

    private const COMPOSED_FORM_KEY_PATTERN = '~\A["\']?[A-Za-z0-9_.\~%+\[\]\x5C=\-]+["\']?\z~D';

    private const MAX_PHP_SERIALIZED_SCALAR_BYTES = 128;

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
    public static function assertSafe(#[\SensitiveParameter] array $value): void
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
        #[\SensitiveParameter]
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
                    self::assertMapKeySafe(
                        $key,
                        $item,
                        $decodedNodeCount,
                        $decodedByteCount,
                    );
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
    private static function arrayReferenceId(#[\SensitiveParameter] array &$value): string
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
        #[\SensitiveParameter]
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
        #[\SensitiveParameter]
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if ($decodeDepth > self::MAX_SENSITIVE_DECODE_DEPTH) {
            throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
        }

        $fingerprint = $decodeDepth . ':' . hash('sha256', $value);
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
        self::assertNoSensitiveJsonObjectKeys(
            $canonical,
            $decodedNodeCount,
            $decodedByteCount,
        );

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
                    throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
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
            $candidateOffset = 0;
            $candidateNodeCount = 0;
            self::parsePhpSerializedCandidate(
                $canonical,
                $candidateOffset,
                0,
                $candidateNodeCount,
            );
            if ($candidateOffset !== \strlen($canonical)) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

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

        $base64Decoded = self::decodeRecoverableBase64(
            $canonical,
            rejectInvalidUtf8: self::hasBase64EncodingMarker($canonical),
        );
        $isFoldedBase64 = false;
        if ($base64Decoded === null) {
            $base64Decoded = self::decodeFoldedBase64($canonical);
            $isFoldedBase64 = $base64Decoded !== null;
        }

        if ($base64Decoded !== null) {
            self::consumeDecodedBytes($decodedByteCount, \strlen($base64Decoded));
            self::scanEncodedString(
                $base64Decoded,
                $decodeDepth + 1,
                $decodedNodeCount,
                $decodedByteCount,
                $decodedStrings,
            );

            if ($isFoldedBase64) {
                self::scanEmbeddedFoldedBase64Values(
                    $canonical,
                    $decodeDepth,
                    $decodedNodeCount,
                    $decodedByteCount,
                    $decodedStrings,
                );
            } elseif (!self::isCompleteJsonValue($base64Decoded)) {
                for ($alignment = 1; $alignment < 4; ++$alignment) {
                    self::scanBase64Stream(
                        $canonical,
                        $alignment,
                        $decodeDepth,
                        $decodedNodeCount,
                        $decodedByteCount,
                        $decodedStrings,
                    );
                }
            }

            return;
        }

        self::scanFormValue(
            $canonical,
            $decodeDepth,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
        self::scanEmbeddedPhpSerializedValues(
            $canonical,
            $decodeDepth,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
        self::scanEmbeddedBase64Values(
            $canonical,
            $decodeDepth,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
        self::scanEmbeddedFoldedBase64Values(
            $canonical,
            $decodeDepth,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
    }

    private static function assertNoPrivateKeyEnvelopeMarkers(
        #[\SensitiveParameter] string $value,
    ): void
    {
        $match = preg_match(self::PRIVATE_KEY_ENVELOPE_MARKER_PATTERN, $value);
        if ($match === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($match === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }
    }

    private static function assertNoBasicCredentials(#[\SensitiveParameter] string $value): void
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

    private static function assertNoSensitiveAssignments(#[\SensitiveParameter] string $value): void
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

    private static function assertNoSensitiveJsonObjectKeys(
        #[\SensitiveParameter] string $value,
        int &$decodedNodeCount,
        int &$decodedByteCount,
    ): void {
        self::assertNoSensitiveJsonMemberKeys($value);
        self::assertNoSensitiveUnquotedStructuredKeys(
            $value,
            $decodedNodeCount,
            $decodedByteCount,
        );
    }

    private static function assertNoSensitiveJsonMemberKeys(#[\SensitiveParameter] string $value): void
    {
        $length = \strlen($value);
        if ($length >= 2 && $value[0] === '"' && $value[$length - 1] === '"') {
            try {
                $decodedString = json_decode(
                    $value,
                    depth: 2,
                    flags: JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                $decodedString = null;
            }

            if (\is_string($decodedString)) {
                return;
            }
        }

        /**
         * @var array<string, array{
         *     encoded_key_start: int,
         *     slash_count: int,
         *     reject_malformed_key: bool
         * }|null> $openers
         */
        $openers = ['"' => null, "'" => null];

        for ($offset = 0; $offset < $length;) {
            if ($value[$offset] === '"' || $value[$offset] === "'") {
                $quoteTokenStart = $offset;
                $quoteOffset = $offset;
                ++$offset;
            } elseif ($value[$offset] === '\\') {
                $quoteTokenStart = $offset;
                while ($offset < $length && $value[$offset] === '\\') {
                    ++$offset;
                }

                if (
                    $offset >= $length
                    || ($value[$offset] !== '"' && $value[$offset] !== "'")
                ) {
                    continue;
                }

                $quoteOffset = $offset;
                ++$offset;
            } else {
                ++$offset;

                continue;
            }

            $quote = $value[$quoteOffset];
            $slashCount = $quoteOffset - $quoteTokenStart;
            $afterQuote = $offset;
            while ($afterQuote < $length && self::isAsciiWhitespace($value[$afterQuote])) {
                ++$afterQuote;
            }

            $opener = $openers[$quote];
            if ($opener !== null && $afterQuote < $length && $value[$afterQuote] === ':') {
                self::assertJsonObjectKeyCandidateSafe(
                    substr(
                        $value,
                        $opener['encoded_key_start'],
                        $quoteTokenStart - $opener['encoded_key_start'],
                    ),
                    $opener['reject_malformed_key'],
                );
                $openers[$quote] = null;
                $offset = $afterQuote + 1;

                continue;
            }

            if ($opener !== null && $opener['slash_count'] === $slashCount) {
                $openers[$quote] = null;

                continue;
            }

            if ($opener === null) {
                $openers[$quote] = [
                    'encoded_key_start' => $quoteOffset + 1,
                    'slash_count' => $slashCount,
                    'reject_malformed_key' => self::isJsonMemberLeftBoundary(
                        $value,
                        $quoteTokenStart,
                    ),
                ];
            }
        }
    }

    private static function isJsonMemberLeftBoundary(string $value, int $offset): bool
    {
        if ($offset === 0) {
            return true;
        }

        $boundaryOffset = $offset;
        while (
            $boundaryOffset > 0
            && self::isAsciiWhitespace($value[$boundaryOffset - 1])
        ) {
            --$boundaryOffset;
        }

        if ($boundaryOffset === 0) {
            return true;
        }

        $previousByte = $value[$boundaryOffset - 1];
        if ($boundaryOffset !== $offset) {
            return \in_array($previousByte, ['{', '[', ','], true);
        }

        return !self::isAsciiAlphaNumeric($previousByte)
            && !\in_array($previousByte, ['_', '\\', '"'], true);
    }

    private static function assertNoSensitiveUnquotedStructuredKeys(
        #[\SensitiveParameter] string $value,
        int &$decodedNodeCount,
        int &$decodedByteCount,
    ): void
    {
        $length = \strlen($value);
        for ($offset = 0; $offset < $length; ++$offset) {
            if ($value[$offset] !== ':') {
                continue;
            }

            $candidateEnd = $offset;
            while ($candidateEnd > 0 && self::isAsciiWhitespace($value[$candidateEnd - 1])) {
                --$candidateEnd;
            }

            $candidateStart = $candidateEnd;
            while (
                $candidateStart > 0
                && self::isPotentialEncodedKeyByte($value[$candidateStart - 1])
            ) {
                --$candidateStart;
            }

            if ($candidateStart === $candidateEnd) {
                continue;
            }

            $candidate = substr($value, $candidateStart, $candidateEnd - $candidateStart);
            if (!str_contains($candidate, '\\') && !str_contains($candidate, '%')) {
                continue;
            }

            self::assertMapKeySafe(
                $candidate,
                'synthetic-untrusted-structured-value',
                $decodedNodeCount,
                $decodedByteCount,
            );
        }
    }

    private static function isPotentialEncodedKeyByte(string $byte): bool
    {
        return self::isAsciiAlphaNumeric($byte)
            || \in_array($byte, ['_', '-', '.', '%', '+', '/', '\\'], true);
    }

    private static function assertJsonObjectKeyCandidateSafe(
        #[\SensitiveParameter]
        string $encodedKey,
        bool $rejectMalformedKey = true,
    ): void
    {
        for ($decodeDepth = 0; $decodeDepth <= self::MAX_SENSITIVE_DECODE_DEPTH; ++$decodeDepth) {
            if (self::isSensitiveKey(self::normalizeKey($encodedKey))) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            try {
                $key = json_decode('"' . $encodedKey . '"', flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $jsonUnicodeEscapeMatch = preg_match('/\\\\u[0-9A-Fa-f]{4}/', $encodedKey);
                if ($jsonUnicodeEscapeMatch === false) {
                    throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
                }

                if (
                    $jsonUnicodeEscapeMatch !== 1
                    && (
                        !$rejectMalformedKey
                        || self::isWindowsPathLikePublicKeyCandidate($encodedKey)
                    )
                ) {
                    return;
                }

                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            if (!\is_string($key)) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            if ($key === $encodedKey) {
                return;
            }

            if ($decodeDepth === self::MAX_SENSITIVE_DECODE_DEPTH) {
                throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
            }

            $encodedKey = $key;
        }
    }

    private static function isWindowsPathLikePublicKeyCandidate(
        #[\SensitiveParameter] string $key,
    ): bool
    {
        if (\strlen($key) < 4 || $key[1] !== ':' || $key[2] !== '\\') {
            return false;
        }

        $drive = ord($key[0]);
        $pathMatch = preg_match('/\A[-A-Za-z0-9._ ]+\z/D', substr($key, 3));
        if ($pathMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        return (($drive >= ord('A') && $drive <= ord('Z')) || ($drive >= ord('a') && $drive <= ord('z')))
            && $pathMatch === 1;
    }

    /**
     * @param array<string, true> $decodedStrings
     */
    private static function scanPhpSerializedValue(
        #[\SensitiveParameter]
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
            $decoded = unserialize($value, [
                'allowed_classes' => false,
                'max_depth' => self::MAX_NESTING_DEPTH + 1,
            ]);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
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
     * Scans only self-delimiting PHP serialization values. Arrays are also recognized after an
     * alphanumeric decoded prefix because their complete grammar is unambiguous; other types keep
     * the public-prose boundary guard. A small parser finds the exact candidate without trusting
     * attacker-provided string lengths, array sizes, or nesting before native decoding is attempted.
     *
     * @param array<string, true> $decodedStrings
     */
    private static function scanEmbeddedPhpSerializedValues(
        #[\SensitiveParameter]
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        $length = \strlen($value);
        for ($offset = 0; $offset < $length;) {
            if (!self::isPhpSerializedCandidateStart($value, $offset)) {
                ++$offset;

                continue;
            }

            if (
                !self::isPhpSerializedCandidateLeftBoundary($value, $offset)
                && $value[$offset] !== 'a'
            ) {
                ++$offset;

                continue;
            }

            $candidateStart = $offset;
            $candidateNodeCount = 0;
            self::parsePhpSerializedCandidate($value, $offset, 0, $candidateNodeCount);
            $candidate = substr($value, $candidateStart, $offset - $candidateStart);
            self::scanPhpSerializedValue(
                $candidate,
                $decodeDepth,
                $decodedNodeCount,
                $decodedByteCount,
                $decodedStrings,
            );
        }
    }

    private static function isPhpSerializedCandidateStart(string $value, int $offset): bool
    {
        if (substr($value, $offset, 2) === 'N;') {
            return true;
        }

        if (!isset($value[$offset + 2]) || $value[$offset + 1] !== ':') {
            return false;
        }

        $type = $value[$offset];
        $firstValueByte = $value[$offset + 2];

        return match ($type) {
            'b' => $firstValueByte === '0' || $firstValueByte === '1',
            'i' => self::isAsciiDigit($firstValueByte)
                || $firstValueByte === '+'
                || $firstValueByte === '-',
            'd' => self::isAsciiDigit($firstValueByte)
                || \in_array($firstValueByte, ['+', '-', '.', 'N', 'I'], true),
            'a' => self::hasPhpSerializedArrayDelimiter($value, $offset + 2),
            's', 'S', 'O', 'C', 'E', 'r', 'R' => self::isAsciiDigit($firstValueByte)
                || $firstValueByte === '+'
                || $firstValueByte === '-',
            default => false,
        };
    }

    private static function hasPhpSerializedArrayDelimiter(string $value, int $offset): bool
    {
        if (isset($value[$offset]) && ($value[$offset] === '+' || $value[$offset] === '-')) {
            ++$offset;
        }

        if (!isset($value[$offset]) || !self::isAsciiDigit($value[$offset])) {
            return false;
        }

        do {
            ++$offset;
        } while (isset($value[$offset]) && self::isAsciiDigit($value[$offset]));

        return substr($value, $offset, 2) === ':{';
    }

    private static function parsePhpSerializedCandidate(
        #[\SensitiveParameter]
        string $value,
        int &$offset,
        int $nestingDepth,
        int &$candidateNodeCount,
    ): void {
        ++$candidateNodeCount;
        if (
            $candidateNodeCount > self::MAX_SENSITIVE_DECODE_NODES
            || $nestingDepth > self::MAX_NESTING_DEPTH
            || !isset($value[$offset])
        ) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $type = $value[$offset];
        if ($type === 'N') {
            self::requirePhpSerializedBytes($value, $offset, 'N;');

            return;
        }

        if (!isset($value[$offset + 1]) || $value[$offset + 1] !== ':') {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $offset += 2;
        if (\in_array($type, ['b', 'i', 'd'], true)) {
            self::parsePhpSerializedScalar($value, $offset, $type);

            return;
        }

        if ($type === 's' || $type === 'S') {
            self::parsePhpSerializedString($value, $offset, $type === 'S');

            return;
        }

        if ($type === 'a') {
            $itemCount = self::parsePhpSerializedUnsignedInteger(
                $value,
                $offset,
                intdiv(self::MAX_SENSITIVE_DECODE_NODES, 2),
            );
            self::requirePhpSerializedBytes($value, $offset, ':{');

            for ($index = 0; $index < $itemCount; ++$index) {
                self::parsePhpSerializedCandidate(
                    $value,
                    $offset,
                    $nestingDepth + 1,
                    $candidateNodeCount,
                );
                self::parsePhpSerializedCandidate(
                    $value,
                    $offset,
                    $nestingDepth + 1,
                    $candidateNodeCount,
                );
            }

            self::requirePhpSerializedBytes($value, $offset, '}');

            return;
        }

        // Objects, custom payloads, enums, and references are never public market data.
        throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
    }

    private static function parsePhpSerializedScalar(
        #[\SensitiveParameter] string $value,
        int &$offset,
        string $type,
    ): void
    {
        $terminator = strpos($value, ';', $offset);
        if ($terminator === false || $terminator - $offset > self::MAX_PHP_SERIALIZED_SCALAR_BYTES) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $scalar = substr($value, $offset, $terminator - $offset);
        $pattern = match ($type) {
            'b' => '/\A[01]\z/D',
            'i' => '/\A[+-]?[0-9]+\z/D',
            'd' => '/\A(?:[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:E[+-]?[0-9]+)?|NAN|[+-]?INF)\z/D',
            default => throw new \LogicException('paper_market_serialized_scalar_type_invalid'),
        };
        $match = preg_match($pattern, $scalar);
        if ($match !== 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $offset = $terminator + 1;
    }

    private static function parsePhpSerializedString(
        #[\SensitiveParameter]
        string $value,
        int &$offset,
        bool $escaped,
    ): void {
        $declaredLength = self::parsePhpSerializedUnsignedInteger(
            $value,
            $offset,
            self::MAX_SENSITIVE_DECODE_BYTES,
        );
        self::requirePhpSerializedBytes($value, $offset, ':"');

        if (!$escaped) {
            if ($declaredLength > \strlen($value) - $offset - 2) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            $offset += $declaredLength;
        } else {
            for ($decodedLength = 0; $decodedLength < $declaredLength; ++$decodedLength) {
                if (!isset($value[$offset])) {
                    throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
                }

                if ($value[$offset] === '\\') {
                    if (
                        !isset($value[$offset + 2])
                        || !ctype_xdigit($value[$offset + 1])
                        || !ctype_xdigit($value[$offset + 2])
                    ) {
                        throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
                    }

                    $offset += 3;

                    continue;
                }

                ++$offset;
            }
        }

        self::requirePhpSerializedBytes($value, $offset, '";');
    }

    private static function parsePhpSerializedUnsignedInteger(
        #[\SensitiveParameter]
        string $value,
        int &$offset,
        int $maximum,
    ): int {
        if (!isset($value[$offset]) || !self::isAsciiDigit($value[$offset])) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $number = 0;
        do {
            $digit = ord($value[$offset]) - ord('0');
            if ($number > intdiv($maximum - $digit, 10)) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            $number = $number * 10 + $digit;
            ++$offset;
        } while (isset($value[$offset]) && self::isAsciiDigit($value[$offset]));

        return $number;
    }

    private static function requirePhpSerializedBytes(
        #[\SensitiveParameter]
        string $value,
        int &$offset,
        string $expected,
    ): void {
        if (substr($value, $offset, \strlen($expected)) !== $expected) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $offset += \strlen($expected);
    }

    /**
     * Decodes complete Base64 tokens segmented at padding and non-token boundaries. Each token is
     * examined at its four possible alignments, a constant amount of work rather than a sliding
     * substring scan. Padding followed by another alphabet byte starts a new token.
     *
     * @param array<string, true> $decodedStrings
     */
    private static function scanEmbeddedBase64Values(
        #[\SensitiveParameter]
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        $length = \strlen($value);
        for ($offset = 0; $offset < $length;) {
            if (!self::isBase64TokenByte($value[$offset], false)) {
                ++$offset;

                continue;
            }

            $candidateStart = $offset;
            while ($offset < $length && self::isBase64TokenByte($value[$offset], false)) {
                ++$offset;
            }

            while ($offset < $length && $value[$offset] === '=') {
                ++$offset;
            }

            $candidateLength = $offset - $candidateStart;
            if ($candidateLength < 8) {
                continue;
            }

            $candidate = substr($value, $candidateStart, $candidateLength);
            for ($alignment = 0; $alignment < 4; ++$alignment) {
                self::scanBase64Stream(
                    $candidate,
                    $alignment,
                    $decodeDepth,
                    $decodedNodeCount,
                    $decodedByteCount,
                    $decodedStrings,
                );
            }
        }
    }

    /** @param array<string, true> $decodedStrings */
    private static function scanEmbeddedFoldedBase64Values(
        #[\SensitiveParameter]
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        $length = \strlen($value);
        for ($offset = 0; $offset < $length;) {
            if (!self::isBase64TokenByte($value[$offset], false)) {
                ++$offset;

                continue;
            }

            $compacted = '';
            /** @var list<int> $segmentStarts */
            $segmentStarts = [];

            while (true) {
                $segmentStart = \strlen($compacted);
                $segmentStarts[] = $segmentStart;
                $segmentHasPadding = false;
                while ($offset < $length && self::isBase64TokenByte($value[$offset], true)) {
                    $segmentHasPadding = $segmentHasPadding || $value[$offset] === '=';
                    $compacted .= $value[$offset];
                    ++$offset;
                }

                while ($offset < $length && self::isAsciiWhitespace($value[$offset])) {
                    ++$offset;
                }

                $hasNextSegment = $offset < $length
                    && self::isBase64TokenByte($value[$offset], false);
                if (!$hasNextSegment || $segmentHasPadding) {
                    break;
                }
            }

            if (\count($segmentStarts) < 2) {
                continue;
            }

            // Each whitespace boundary is a semantic reset point. Its four possible Base64
            // alignments are constant work; aggregate limits bound inputs containing many segments.
            foreach ($segmentStarts as $segmentStart) {
                for ($alignment = 0; $alignment < 4; ++$alignment) {
                    self::scanBase64Stream(
                        $compacted,
                        $segmentStart + $alignment,
                        $decodeDepth,
                        $decodedNodeCount,
                        $decodedByteCount,
                        $decodedStrings,
                    );
                }
            }
        }
    }

    /** @param array<string, true> $decodedStrings */
    private static function scanBase64Stream(
        #[\SensitiveParameter] string $compacted,
        int $start,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if (\strlen($compacted) - $start < 8) {
            return;
        }

        $decoded = self::decodeBase64AlignmentStream($compacted, $start);
        if ($decoded === null) {
            return;
        }

        self::consumeDecodedBytes($decodedByteCount, \strlen($decoded));
        if (preg_match('//u', $decoded) !== 1) {
            $decoded = mb_scrub($decoded, 'UTF-8');
        }

        self::scanEncodedString(
            $decoded,
            $decodeDepth + 1,
            $decodedNodeCount,
            $decodedByteCount,
            $decodedStrings,
        );
    }

    private static function isCompleteJsonValue(#[\SensitiveParameter] string $value): bool
    {
        $canonical = self::trimLeadingWhitespaceAndBom($value);
        if ($canonical === '' || !\in_array($canonical[0], ['{', '[', '"'], true)) {
            return false;
        }

        try {
            json_decode(
                $canonical,
                associative: true,
                depth: self::MAX_NESTING_DEPTH + 1,
                flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );

            return true;
        } catch (\JsonException) {
            return false;
        }
    }

    private static function decodeBase64AlignmentStream(
        #[\SensitiveParameter] string $compacted,
        int $start,
    ): ?string {
        $candidate = substr($compacted, $start);
        $unpadded = rtrim($candidate, '=');
        if (str_contains($unpadded, '=')) {
            return null;
        }

        $remainder = \strlen($unpadded) % 4;
        if ($remainder === 1) {
            if ($unpadded !== $candidate) {
                return null;
            }

            $unpadded = substr($unpadded, 0, -1);
            $remainder = 0;
        }

        $standard = strtr($unpadded, '-_', '+/');
        $decoded = base64_decode(
            $standard . str_repeat('=', (4 - $remainder) % 4),
            true,
        );

        return $decoded === false ? null : $decoded;
    }

    private static function isPhpSerializedCandidateLeftBoundary(string $value, int $offset): bool
    {
        return $offset === 0
            || (
                !self::isAsciiAlphaNumeric($value[$offset - 1])
                && $value[$offset - 1] !== '_'
            );
    }

    private static function isBase64TokenByte(string $byte, bool $allowPadding): bool
    {
        return self::isAsciiDigit($byte)
            || ($byte >= 'A' && $byte <= 'Z')
            || ($byte >= 'a' && $byte <= 'z')
            || \in_array($byte, ['+', '/', '-', '_'], true)
            || ($allowPadding && $byte === '=');
    }

    private static function isAsciiDigit(string $byte): bool
    {
        return $byte >= '0' && $byte <= '9';
    }

    private static function isAsciiAlphaNumeric(string $byte): bool
    {
        return self::isAsciiDigit($byte)
            || ($byte >= 'A' && $byte <= 'Z')
            || ($byte >= 'a' && $byte <= 'z');
    }

    private static function isAsciiWhitespace(string $byte): bool
    {
        return $byte === ' '
            || ($byte >= "\x09" && $byte <= "\x0D");
    }

    /**
     * @param array<string, true> $activeArrayReferences
     * @param array<string, true> $decodedStrings
     */
    private static function scanDecodedValue(
        #[\SensitiveParameter]
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
                        self::assertMapKeySafe(
                            $key,
                            $item,
                            $decodedNodeCount,
                            $decodedByteCount,
                        );
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
        #[\SensitiveParameter]
        string $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        array &$decodedStrings,
    ): void {
        if (!str_contains($value, '=')) {
            return;
        }

        $length = \strlen($value);
        $offset = 0;
        while ($offset <= $length) {
            $separator = strpos($value, '&', $offset);
            $partEnd = $separator === false ? $length : $separator;
            $leadingDecodedKeyBytes = 0;
            $partStart = self::formKeyStartAfterLeadingSpace(
                $value,
                $offset,
                $partEnd,
                $leadingDecodedKeyBytes,
            );
            $equals = self::formAssignmentOffset($value, $partStart, $partEnd);
            if ($equals === false || $equals >= $partEnd) {
                if ($separator === false) {
                    break;
                }

                $offset = $partEnd + 1;
                continue;
            }

            $rawKey = rtrim(
                substr($value, $partStart, $equals - $partStart),
                "\x09\x0A\x0B\x0C\x0D ",
            );
            $key = urldecode($rawKey);
            $decodedKeyBytes = $leadingDecodedKeyBytes + \strlen($key);
            $key = rtrim($key, "\x09\x0A\x0B\x0C\x0D ");
            $item = urldecode(substr($value, $equals + 1, $partEnd - $equals - 1));
            $rawKeyMatch = preg_match(self::RAW_FORM_KEY_PATTERN, $rawKey);
            $composedKeyMatch = preg_match(self::COMPOSED_FORM_KEY_PATTERN, $key);
            if ($rawKeyMatch === false || $composedKeyMatch === false) {
                throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
            }

            self::consumeDecodedBytes($decodedByteCount, $decodedKeyBytes);
            if ($rawKeyMatch !== 1 && $composedKeyMatch !== 1) {
                self::assertEmbeddedComposedMapKeyCandidatesSafe(
                    $key,
                    $item,
                    $decodeDepth,
                    $decodedNodeCount,
                    $decodedByteCount,
                );

                // An early assignment delimiter can leave the real composed relation in the
                // nominal value (for example, ="encoded-key"=value). Inspect that component for
                // composed key candidates without recursively reinterpreting arbitrary public
                // value bytes as an unbounded chain of nested forms.
                self::assertEmbeddedComposedMapKeyCandidatesSafe(
                    $item,
                    null,
                    $decodeDepth,
                    $decodedNodeCount,
                    $decodedByteCount,
                );

                if ($separator === false) {
                    break;
                }

                $offset = $partEnd + 1;
                continue;
            }

            self::assertMapKeySafe(
                $key,
                $item,
                $decodedNodeCount,
                $decodedByteCount,
            );

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

            if ($separator === false) {
                break;
            }

            $offset = $partEnd + 1;
        }
    }

    private static function formKeyStartAfterLeadingSpace(
        #[\SensitiveParameter]
        string $value,
        int $partStart,
        int $partEnd,
        int &$leadingDecodedKeyBytes,
    ): int {
        while (
            $partStart < $partEnd
            && (
                self::isAsciiWhitespace($value[$partStart])
                || $value[$partStart] === '+'
            )
        ) {
            ++$partStart;
            ++$leadingDecodedKeyBytes;
        }

        return $partStart;
    }

    private static function formAssignmentOffset(
        #[\SensitiveParameter]
        string $value,
        int $partStart,
        int $partEnd,
    ): int|false {
        $firstEquals = $partStart + strcspn($value, '=', $partStart, $partEnd - $partStart);
        if ($firstEquals >= $partEnd) {
            return false;
        }

        $quote = $value[$partStart];
        if ($quote !== '"' && $quote !== "'") {
            return $firstEquals;
        }

        for ($offset = $partStart + 1; $offset < $partEnd; ++$offset) {
            if ($value[$offset] === '\\') {
                ++$offset;
                continue;
            }

            if ($value[$offset] !== $quote) {
                continue;
            }

            $assignmentOffset = $offset + 1;
            while (
                $assignmentOffset < $partEnd
                && (
                    self::isAsciiWhitespace($value[$assignmentOffset])
                    || $value[$assignmentOffset] === '+'
                )
            ) {
                ++$assignmentOffset;
            }

            if ($assignmentOffset < $partEnd && $value[$assignmentOffset] === '=') {
                return $assignmentOffset;
            }
        }

        return $firstEquals;
    }

    private static function decodeRecoverableBase64(
        #[\SensitiveParameter]
        string $value,
        int $minimumLength = 8,
        bool $allowUrlSafe = true,
        bool $rejectInvalidUtf8 = true,
        bool $recoverInvalidUtf8 = true,
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

        if ($decoded === false || $canonicalUnpadded !== $unpadded) {
            return null;
        }

        if (preg_match('//u', $decoded) !== 1) {
            self::assertNoSensitiveBinaryAssignments($decoded);
            if ($rejectInvalidUtf8) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            return $recoverInvalidUtf8 ? mb_scrub($decoded, 'UTF-8') : null;
        }

        return $decoded;
    }

    private static function decodeFoldedBase64(#[\SensitiveParameter] string $value): ?string
    {
        $segments = preg_split('/[\x09-\x0D ]+/', $value);
        if ($segments === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if (\count($segments) < 2) {
            return null;
        }

        $compacted = '';
        foreach ($segments as $segment) {
            $match = preg_match('/\A[A-Za-z0-9+\/_-]+=*\z/D', $segment);
            if ($match === false) {
                throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
            }

            if ($match !== 1) {
                return null;
            }

            $compacted .= $segment;
        }

        return self::decodeRecoverableBase64(
            $compacted,
            rejectInvalidUtf8: self::hasBase64EncodingMarker($compacted),
        );
    }

    private static function assertNoSensitiveBinaryAssignments(
        #[\SensitiveParameter] string $value,
    ): void {
        $match = preg_match(self::SENSITIVE_VALUE_PATTERN, strtr($value, self::KEY_CONFUSABLES));
        if ($match === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($match === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }
    }

    private static function hasBase64EncodingMarker(string $value): bool
    {
        return strpbrk($value, '+/=') !== false;
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

    private static function normalizeKey(#[\SensitiveParameter] string $key): string
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

    private static function assertMapKeySafe(
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] mixed $value,
        int &$decodedNodeCount,
        int &$decodedByteCount,
        int $startingDecodeDepth = 0,
    ): void
    {
        for (
            $decodeDepth = $startingDecodeDepth;
            $decodeDepth <= self::MAX_SENSITIVE_DECODE_DEPTH;
            ++$decodeDepth
        ) {
            $normalizedKey = self::normalizeKey($key);
            if (self::isSensitiveKey($normalizedKey)) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            $decoded = rawurldecode($key);
            if ($decoded === $key) {
                $jsonDecoded = null;
                try {
                    if (
                        \strlen($key) >= 2
                        && $key[0] === '"'
                        && $key[\strlen($key) - 1] === '"'
                    ) {
                        $jsonDecoded = json_decode($key, flags: JSON_THROW_ON_ERROR);
                    } elseif (str_contains($key, '\\')) {
                        $jsonDecoded = json_decode('"' . $key . '"', flags: JSON_THROW_ON_ERROR);
                    }
                } catch (\JsonException) {
                    $jsonDecoded = null;
                }

                if (\is_string($jsonDecoded)) {
                    $decoded = $jsonDecoded;
                } elseif (
                    \strlen($key) > 1
                    && str_starts_with($key, "'")
                    && str_ends_with($key, "'")
                ) {
                    $decoded = substr($key, 1, -1);
                } elseif (
                    \strlen($key) > 1
                    && (str_starts_with($key, "'") xor str_ends_with($key, "'"))
                ) {
                    $decoded = str_starts_with($key, "'") ? substr($key, 1) : substr($key, 0, -1);
                } elseif (
                    \strlen($key) > 1
                    && (str_starts_with($key, '"') xor str_ends_with($key, '"'))
                ) {
                    $decoded = str_starts_with($key, '"') ? substr($key, 1) : substr($key, 0, -1);
                }
            }

            if ($decoded === $key) {
                $base64Decoded = self::decodeRecoverableBase64(
                    $key,
                    rejectInvalidUtf8: false,
                    recoverInvalidUtf8: false,
                );
                if ($base64Decoded === null) {
                    $base64Decoded = self::decodeNonCanonicalBase64MapKey($key);
                }

                if ($base64Decoded === null) {
                    self::assertEmbeddedComposedMapKeyCandidatesSafe(
                        $key,
                        $value,
                        $decodeDepth,
                        $decodedNodeCount,
                        $decodedByteCount,
                    );

                    return;
                }

                $decoded = $base64Decoded;
            }

            if ($decodeDepth === self::MAX_SENSITIVE_DECODE_DEPTH) {
                throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
            }

            self::consumeDecodedNode($decodedNodeCount);
            self::consumeDecodedBytes($decodedByteCount, \strlen($decoded));
            $key = $decoded;
        }
    }

    /**
     * Streams over composition boundaries inside an otherwise non-canonical map key. Quoted
     * candidates and delimiter-bounded Base64 tokens are checked independently without building
     * a candidate list; ordinary punctuation and prose remain valid when no candidate decodes to
     * a sensitive key.
     */
    private static function assertEmbeddedComposedMapKeyCandidatesSafe(
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] mixed $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
    ): void {
        self::scanEmbeddedJsonUnicodeMapKeyCandidate(
            $key,
            $value,
            $decodeDepth,
            $decodedNodeCount,
            $decodedByteCount,
        );
        self::scanEmbeddedBase64MapKeyCandidates(
            $key,
            $value,
            $decodeDepth,
            $decodedNodeCount,
            $decodedByteCount,
        );

        $length = \strlen($key);
        for ($offset = 0; $offset < $length;) {
            $quote = $key[$offset];
            if ($quote !== '"' && $quote !== "'") {
                ++$offset;
                continue;
            }

            $quoteStart = $offset;
            ++$offset;
            while ($offset < $length && $key[$offset] !== $quote) {
                if ($key[$offset] === '\\') {
                    $offset += 2;

                    continue;
                }

                ++$offset;
            }

            $hasClosingQuote = $offset < $length;
            $quotedCandidate = substr(
                $key,
                $quoteStart,
                ($hasClosingQuote ? $offset + 1 : $length) - $quoteStart,
            );
            if ($hasClosingQuote) {
                ++$offset;
            }

            $decodedCandidate = null;
            if ($quote === '"') {
                try {
                    $decodedCandidate = json_decode(
                        $hasClosingQuote
                            ? $quotedCandidate
                            : $quotedCandidate . '"',
                        flags: JSON_THROW_ON_ERROR,
                    );
                } catch (\JsonException) {
                    $unicodeEscapeMatch = preg_match(
                        '/\\\\u[0-9A-Fa-f]{4}/',
                        $quotedCandidate,
                    );
                    if ($unicodeEscapeMatch === false) {
                        throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
                    }

                    if ($unicodeEscapeMatch === 1) {
                        throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
                    }
                }
            } else {
                $decodedCandidate = substr(
                    $quotedCandidate,
                    1,
                    \strlen($quotedCandidate) - ($hasClosingQuote ? 2 : 1),
                );
            }

            if (\is_string($decodedCandidate)) {
                self::scanDecodedMapKeyCandidate(
                    $decodedCandidate,
                    $value,
                    $decodeDepth + 1,
                    $decodedNodeCount,
                    $decodedByteCount,
                );
            }
        }
    }

    private static function scanEmbeddedJsonUnicodeMapKeyCandidate(
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] mixed $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
    ): void {
        $unicodeEscapePattern = '/\\\\u(?:[dD][89AaBb][0-9A-Fa-f]{2}'
            . '\\\\u[dD][c-fC-F][0-9A-Fa-f]{2}|[0-9A-Fa-f]{4})/';
        $unicodeEscapeMatch = preg_match($unicodeEscapePattern, $key);
        if ($unicodeEscapeMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($unicodeEscapeMatch !== 1) {
            return;
        }

        $decodedCandidate = preg_replace_callback(
            $unicodeEscapePattern,
            static function (array $matches): string {
                try {
                    $decoded = json_decode(
                        '"' . $matches[0] . '"',
                        flags: JSON_THROW_ON_ERROR,
                    );
                } catch (\JsonException) {
                    throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
                }

                if (!\is_string($decoded)) {
                    throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
                }

                return $decoded;
            },
            $key,
        );
        if ($decodedCandidate === null) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        self::scanDecodedMapKeyCandidate(
            $decodedCandidate,
            $value,
            $decodeDepth + 1,
            $decodedNodeCount,
            $decodedByteCount,
        );
    }

    private static function scanEmbeddedBase64MapKeyCandidates(
        #[\SensitiveParameter] string $key,
        #[\SensitiveParameter] mixed $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
    ): void {
        $length = \strlen($key);
        for ($offset = 0; $offset < $length;) {
            if (!self::isBase64TokenByte($key[$offset], false)) {
                ++$offset;

                continue;
            }

            $candidateStart = $offset;
            while ($offset < $length && self::isBase64TokenByte($key[$offset], false)) {
                ++$offset;
            }
            while ($offset < $length && $key[$offset] === '=') {
                ++$offset;
            }

            $candidate = substr($key, $candidateStart, $offset - $candidateStart);
            self::scanBase64MapKeyCandidateAlignments(
                $candidate,
                $value,
                $decodeDepth,
                $decodedNodeCount,
                $decodedByteCount,
            );

            if (str_ends_with($candidate, '=')) {
                continue;
            }

            // Whitespace-folded Base64 is one composed representation. Compact only the current
            // maximal run, retain no segment list, and inspect the same four possible alignments.
            $compacted = $candidate;
            $segmentCount = 1;
            $foldedOffset = $offset;
            while ($foldedOffset < $length) {
                $whitespaceStart = $foldedOffset;
                while (
                    $foldedOffset < $length
                    && self::isAsciiWhitespace($key[$foldedOffset])
                ) {
                    ++$foldedOffset;
                }

                if (
                    $foldedOffset === $whitespaceStart
                    || $foldedOffset >= $length
                    || !self::isBase64TokenByte($key[$foldedOffset], false)
                ) {
                    break;
                }

                $segmentHasPadding = false;
                while (
                    $foldedOffset < $length
                    && self::isBase64TokenByte($key[$foldedOffset], true)
                ) {
                    $segmentHasPadding = $segmentHasPadding || $key[$foldedOffset] === '=';
                    $compacted .= $key[$foldedOffset];
                    ++$foldedOffset;
                }
                ++$segmentCount;

                if ($segmentHasPadding) {
                    break;
                }
            }

            if ($segmentCount > 1) {
                self::scanBase64MapKeyCandidateAlignments(
                    $compacted,
                    $value,
                    $decodeDepth,
                    $decodedNodeCount,
                    $decodedByteCount,
                );
                $offset = $foldedOffset;
            }
        }
    }

    private static function scanBase64MapKeyCandidateAlignments(
        #[\SensitiveParameter] string $candidate,
        #[\SensitiveParameter] mixed $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
    ): void {
        if (\strlen($candidate) < 8) {
            return;
        }

        for ($alignment = 0; $alignment < 4; ++$alignment) {
            $decodedCandidate = self::decodeBase64AlignmentStream($candidate, $alignment);
            if ($decodedCandidate === null) {
                continue;
            }

            self::scanDecodedMapKeyCandidate(
                $decodedCandidate,
                $value,
                $decodeDepth + 1,
                $decodedNodeCount,
                $decodedByteCount,
            );
        }
    }

    private static function scanDecodedMapKeyCandidate(
        #[\SensitiveParameter] string $candidate,
        #[\SensitiveParameter] mixed $value,
        int $decodeDepth,
        int &$decodedNodeCount,
        int &$decodedByteCount,
    ): void {
        // Adjacent prefix/composed quotes produce an empty speculative interval. It carries no
        // representation and must not consume decode depth before the next opener is inspected.
        if ($candidate === '') {
            return;
        }

        if ($decodeDepth > self::MAX_SENSITIVE_DECODE_DEPTH) {
            throw new \InvalidArgumentException('paper_market_sensitive_decode_depth_exceeded');
        }

        self::consumeDecodedNode($decodedNodeCount);
        self::consumeDecodedBytes($decodedByteCount, \strlen($candidate));
        if (preg_match('//u', $candidate) !== 1) {
            self::assertNormalizedMapKeyCandidateSafe(mb_scrub($candidate, 'UTF-8'));
            self::assertNoSensitiveBinaryAssignments($candidate);

            return;
        }

        $hasNonAscii = self::assertNormalizedMapKeyCandidateSafe($candidate);
        if ($hasNonAscii) {
            return;
        }

        self::assertMapKeySafe(
            $candidate,
            $value,
            $decodedNodeCount,
            $decodedByteCount,
            $decodeDepth,
        );
    }

    /** Returns whether non-ASCII code points remain after supported confusable mapping. */
    private static function assertNormalizedMapKeyCandidateSafe(
        #[\SensitiveParameter] string $candidate,
    ): bool {
        $compatibilityNormalized = \Normalizer::normalize($candidate, \Normalizer::FORM_KC);
        if ($compatibilityNormalized === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        $candidateWithMappedConfusables = strtr(
            $compatibilityNormalized,
            self::KEY_CONFUSABLES,
        );
        $withWordBoundaries = preg_replace(
            [
                '/(?<=[a-z0-9])(?=[A-Z])/',
                '/(?<=[A-Z])(?=[A-Z][a-z])/',
            ],
            '_',
            trim($candidateWithMappedConfusables),
        );
        $normalizedCandidate = preg_replace(
            '/[^a-z0-9]+/',
            '_',
            strtolower($withWordBoundaries ?? ''),
        );
        if (self::isSensitiveKey(trim($normalizedCandidate ?? '', '_'))) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $nonAsciiMatch = preg_match('/[^\x00-\x7F]/', $candidateWithMappedConfusables);
        if ($nonAsciiMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        return $nonAsciiMatch === 1;
    }

    private static function decodeNonCanonicalBase64MapKey(
        #[\SensitiveParameter] string $value,
    ): ?string
    {
        $unpadded = preg_replace('/[\x09-\x0D =]/', '', $value);
        if ($unpadded === null) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($unpadded === $value) {
            return null;
        }

        return self::decodeRecoverableBase64($unpadded);
    }

    private static function isSensitiveKey(#[\SensitiveParameter] string $normalizedKey): bool
    {
        foreach ([self::SENSITIVE_KEYS, self::SENSITIVE_KEY_ALIASES] as $sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($normalizedKey, $sensitiveKey)) {
                    return true;
                }
            }
        }

        return false;
    }
}
