<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final class EffectiveConfigRedactor
{
    public const REDACTED = '***REDACTED***';

    /** @var list<string> */
    private const SENSITIVE_SEGMENTS = [
        'api_key', 'api_secret', 'secret', 'password', 'passwords', 'passphrase',
        'token', 'tokens', 'credential', 'credentials', 'private_key', 'signature',
        'wallet', 'signer', 'wallet_signer',
    ];

    /** @param array<string,mixed> $document */
    public function redact(array $document): EffectiveConfigRedactionResult
    {
        $paths = [];
        $redacted = $this->walk($document, '', $paths);
        sort($paths, SORT_STRING);

        /** @var array<string,mixed> $redacted */
        return new EffectiveConfigRedactionResult($redacted, array_values(array_unique($paths)));
    }

    /**
     * @param list<string> $paths
     */
    private function walk(mixed $value, string $path, array &$paths): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $child) {
            $keyString = (string) $key;
            $childPath = $path === '' ? $keyString : $path . '.' . $keyString;
            if ($this->isSensitiveKey($keyString) || $this->isCredentialDsn($keyString, $child)) {
                $result[$key] = self::REDACTED;
                $paths[] = $childPath;
                continue;
            }
            $result[$key] = $this->walk($child, $childPath, $paths);
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $normalized) ?? $normalized;
        $normalized = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $normalized), '_'));
        if (preg_match('/(?:^|_)token_budgets?(?:_|$)/', $normalized) === 1) {
            return false;
        }

        foreach (self::SENSITIVE_SEGMENTS as $sensitive) {
            if ($normalized === $sensitive
                || str_starts_with($normalized, $sensitive . '_')
                || str_ends_with($normalized, '_' . $sensitive)
                || str_contains($normalized, '_' . $sensitive . '_')
            ) {
                return true;
            }
        }

        return false;
    }

    private function isCredentialDsn(string $key, mixed $value): bool
    {
        if (!is_string($value) || !in_array(strtolower($key), ['dsn', 'url', 'uri'], true)) {
            return false;
        }

        return is_string(parse_url($value, PHP_URL_USER)) || is_string(parse_url($value, PHP_URL_PASS));
    }
}
