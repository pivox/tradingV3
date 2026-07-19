<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class PaperMarketEventRedactor
{
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
    ];

    /**
     * @param array<array-key, mixed> $value
     */
    public static function assertSafe(array $value): void
    {
        foreach ($value as $key => $item) {
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
                self::assertSafe($item);
            }
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
