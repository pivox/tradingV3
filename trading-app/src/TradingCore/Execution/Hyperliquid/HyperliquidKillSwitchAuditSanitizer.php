<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final class HyperliquidKillSwitchAuditSanitizer
{
    private const MAX_REASON_LENGTH = 128;
    private const MAX_VALUE_LENGTH = 128;
    private const MAX_CONTEXT_BYTES = 4_096;
    private const MAX_ITEMS = 16;
    private const MAX_DEPTH = 3;
    private const SENSITIVE_KEY_PATTERN = '/(?:raw|payload|secret|token|api[_-]?key|private[_-]?key|passphrase|password|authorization|cookie|signature|credential|memo|(?:^|[^a-z0-9])(?:key|sign)(?:$|[^a-z0-9])|key$)/i';
    private const SENSITIVE_VALUE_PATTERN = '/(?:(?<![0-9a-f])(?:0x)?[0-9a-f]{64}(?![0-9a-f])|\b(?:bearer|basic)\s+\S+|\b(?:sk|pk|ghp|github_pat|xox[baprs])[-_][A-Za-z0-9_-]{16,}\b|(?<![A-Za-z0-9])(?:["\'])?(?:api[\s_-]+key|secret|token|private[\s_-]+key|passphrase|password|authorization|cookie|signature|credentials?|memo)(?:["\'])?\s*[:=]\s*(?:["\'])?\S+|\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)/i';

    public function sanitizeReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || $this->isSensitiveValue($reason)) {
            return 'hyperliquid_kill_switch_tripped';
        }

        return substr($reason, 0, self::MAX_REASON_LENGTH);
    }

    public function isSafeOpaqueValue(string $value): bool
    {
        return !$this->isSensitiveValue($value);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sanitizeContext(array $context): array
    {
        return $this->sanitize($context, preserveSensitiveKeys: false);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sanitizeAuditPayload(array $context): array
    {
        return $this->sanitize($context, preserveSensitiveKeys: true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context, bool $preserveSensitiveKeys): array
    {
        $bounded = $this->sanitizeArray($context, 0, $preserveSensitiveKeys);
        $encoded = json_encode($bounded);
        if (is_string($encoded) && strlen($encoded) <= self::MAX_CONTEXT_BYTES) {
            return $bounded;
        }

        $correlationId = $bounded['correlation_id'] ?? null;

        return is_string($correlationId) ? ['correlation_id' => $correlationId] : [];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values, int $depth, bool $preserveSensitiveKeys): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $sanitized = [];
        foreach (array_slice($values, 0, self::MAX_ITEMS, true) as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $originalKey = trim($key);
            $sensitiveKey = preg_match(self::SENSITIVE_KEY_PATTERN, $originalKey) === 1;
            $key = substr($originalKey, 0, 64);
            if ($key === '') {
                continue;
            }
            if (array_key_exists($key, $sanitized)) {
                $sanitized[$key] = '[redacted]';
                continue;
            }
            if ($sensitiveKey) {
                if ($preserveSensitiveKeys) {
                    $sanitized[$key] = '[redacted]';
                }
                continue;
            }
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value, $depth + 1, $preserveSensitiveKeys);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->isSensitiveValue($value)
                    ? '[redacted]'
                    : substr($value, 0, self::MAX_VALUE_LENGTH);
            } elseif (is_int($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            } elseif (is_float($value) && is_finite($value)) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveValue(string $value): bool
    {
        return preg_match(self::SENSITIVE_VALUE_PATTERN, $value) === 1;
    }
}
