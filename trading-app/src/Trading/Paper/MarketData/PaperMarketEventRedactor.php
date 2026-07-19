<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class PaperMarketEventRedactor
{
    private const MAX_NESTING_DEPTH = 128;
    private const MAX_SCANNED_STRING_BYTES = 1_048_576;

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

    private const SENSITIVE_VALUE_PATTERN = <<<'REGEX'
~(?:
    \b(?:
        bearer[\t ]+[a-z0-9._\~+/=-]{8,} |
        basic[\t ]+[a-z0-9+/]{8,}={0,2}
    ) |
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

    /**
     * @param array<array-key, mixed> $value
     */
    public static function assertSafe(array $value): void
    {
        $activeArrayReferences = [];
        self::assertSafeArray($value, 0, $activeArrayReferences);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, true>     $activeArrayReferences
     */
    private static function assertSafeArray(array &$value, int $depth, array &$activeArrayReferences): void
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            throw new \InvalidArgumentException('paper_market_payload_depth_exceeded');
        }

        $referenceId = self::arrayReferenceId($value);
        if (isset($activeArrayReferences[$referenceId])) {
            throw new \InvalidArgumentException('paper_market_payload_cycle_detected');
        }

        $activeArrayReferences[$referenceId] = true;

        try {
            foreach (array_keys($value) as $key) {
                $item = &$value[$key];

                if (\is_string($key)) {
                    $normalizedKey = self::normalizeKey($key);
                    if (
                        \in_array($normalizedKey, self::SENSITIVE_KEYS, true)
                        || \in_array($normalizedKey, self::SENSITIVE_KEY_ALIASES, true)
                    ) {
                        throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
                    }
                }

                if (\is_array($item)) {
                    self::assertSafeArray($item, $depth + 1, $activeArrayReferences);
                }

                if (\is_string($item)) {
                    self::assertSafeString($item);
                }

                unset($item);
            }
        } finally {
            unset($activeArrayReferences[$referenceId]);
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

    private static function assertSafeString(string $value): void
    {
        if (\strlen($value) > self::MAX_SCANNED_STRING_BYTES) {
            throw new \InvalidArgumentException('paper_market_payload_string_too_large');
        }

        $serializedMatch = preg_match(self::PHP_SERIALIZED_VALUE_PATTERN, $value);
        if ($serializedMatch === false) {
            throw new \InvalidArgumentException('paper_market_sensitive_scan_failed');
        }

        if ($serializedMatch === 1) {
            throw new \InvalidArgumentException('paper_market_sensitive_field_rejected');
        }

        $match = preg_match(self::SENSITIVE_VALUE_PATTERN, $value);
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
}
