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

    /** @var list<string> Exact public metadata keys that contain an otherwise sensitive token sequence. */
    private const ALLOWED_SEMANTIC_KEYS = [
        'authorization_status',
        'api_key_hint',
        'signature_count',
        'signed_price',
        'signal',
        'wallet_balance_model',
        'seed_phrase_model',
    ];

    private const SENSITIVE_VALUE_PATTERN = <<<'REGEX'
~(?:
    \bbearer[\t ]+[a-z0-9._\~+/=-]{8,} |
    ["']?(?:
        authorization |
        api[._\x20-]*key |
        api[._\x20-]*secret |
        secret[._\x20-]*key |
        private[._\x20-]*key |
        access[._\x20-]*token |
        client[._\x20-]*secret |
        passphrase |
        sign(?:ature)? |
        wallet |
        mnemonic |
        seed[._\x20-]*phrase |
        ok[._\x20-]*access[._\x20-]*(?:key|sign|passphrase)
    )["']?[\t ]*[:=]
)~ix
REGEX;

    private const BASIC_VALUE_PATTERN = '~\bbasic[\t ]+([A-Za-z0-9+/]+={0,2})(?![A-Za-z0-9+/=])~i';

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

    private const RAW_FORM_VALUE_PATTERN = '~\A[A-Za-z0-9_.\~%+\[\]-]+=[^&]*(?:&[A-Za-z0-9_.\~%+\[\]-]+=[^&]*)*\z~D';

    /**
     * @param array<array-key, mixed> $value
     */
    public static function assertSafe(array $value): void
    {
        $activeArrayReferences = [];
        $nodeCount = 0;
        $byteCount = 0;
        self::assertSafeArray($value, 0, $activeArrayReferences, $nodeCount, $byteCount);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, true>     $activeArrayReferences
     */
    private static function assertSafeArray(
        array &$value,
        int $depth,
        array &$activeArrayReferences,
        int &$nodeCount,
        int &$byteCount,
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
                    if (self::isSensitiveKey($normalizedKey)) {
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
                    );
                } else {
                    self::consumeNode($nodeCount);
                    if (\is_string($item)) {
                        self::assertSafeString($item, $byteCount);
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

    private static function assertSafeString(string $value, int &$byteCount): void
    {
        if (\strlen($value) > self::MAX_PAYLOAD_STRING_BYTES) {
            throw new \InvalidArgumentException('paper_market_payload_string_too_large');
        }

        self::consumeBytes($byteCount, \strlen($value));
        self::assertNotRawJsonContainer($value);
        self::assertNotRawFormPayload($value);

        $serializedMatch = preg_match(self::PHP_SERIALIZED_VALUE_PATTERN, ltrim($value));
        if ($serializedMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($serializedMatch === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        self::assertNoBasicCredentials($value);

        $match = preg_match(self::SENSITIVE_VALUE_PATTERN, $value);
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
            $decoded = base64_decode($match[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }
        }
    }

    private static function assertNotRawJsonContainer(string $value): void
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return;
        }

        try {
            $decoded = json_decode(
                $trimmed,
                associative: true,
                depth: self::MAX_NESTING_DEPTH + 1,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
            }

            return;
        }

        if (\is_array($decoded)) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }
    }

    private static function assertNotRawFormPayload(string $value): void
    {
        $match = preg_match(self::RAW_FORM_VALUE_PATTERN, $value);
        if ($match === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($match === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }
    }

    private static function normalizeKey(string $key): string
    {
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
        if (\in_array($normalizedKey, self::ALLOWED_SEMANTIC_KEYS, true)) {
            return false;
        }

        $boundedKey = '_' . $normalizedKey . '_';
        foreach ([self::SENSITIVE_KEYS, self::SENSITIVE_KEY_ALIASES] as $sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($boundedKey, '_' . $sensitiveKey . '_')) {
                    return true;
                }
            }
        }

        return false;
    }
}
