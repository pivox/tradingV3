<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

final readonly class DemoTradingSafetyPolicy
{
    /** @var list<string> */
    public array $allowedSymbols;

    /** @var list<string> */
    public array $allowedMarkets;

    /** @var array<string,mixed> */
    public array $auditContext;

    /**
     * @param list<string> $allowedSymbols
     * @param list<string> $allowedMarkets
     * @param array<string,mixed> $auditContext
     */
    public function __construct(
        public ExchangeRuntimeEnvironment $environment,
        public bool $dryRun = true,
        public bool $mainnetWriteEnabled = false,
        public bool $demoTestnetWriteEnabled = false,
        public bool $killSwitchEnabled = true,
        public bool $requireStopLoss = true,
        array $allowedSymbols = [],
        array $allowedMarkets = [],
        public ?float $maxNotional = null,
        array $auditContext = [],
    ) {
        if ($maxNotional !== null && $maxNotional <= 0.0) {
            throw new \InvalidArgumentException('maxNotional must be positive when provided.');
        }

        $this->allowedSymbols = self::normalizeStringList($allowedSymbols, 'allowedSymbols');
        $this->allowedMarkets = self::normalizeStringList($allowedMarkets, 'allowedMarkets');
        $this->auditContext = $auditContext;
    }

    public function isWriteRequested(): bool
    {
        return $this->dryRun === false;
    }

    /**
     * @return array<string,mixed>
     */
    public function toRedactedArray(): array
    {
        return [
            'environment' => $this->environment->value,
            'dry_run' => $this->dryRun,
            'mainnet_write_enabled' => $this->mainnetWriteEnabled,
            'demo_testnet_write_enabled' => $this->demoTestnetWriteEnabled,
            'kill_switch_enabled' => $this->killSwitchEnabled,
            'require_stop_loss' => $this->requireStopLoss,
            'allowed_symbols' => $this->allowedSymbols,
            'allowed_markets' => $this->allowedMarkets,
            'max_notional' => $this->maxNotional,
            'audit_context' => self::redact($this->auditContext),
        ];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function normalizeStringList(array $values, string $field): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException(sprintf('%s must contain only strings.', $field));
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                $normalized[] = $trimmed;
            }
        }

        return array_values($normalized);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
        $compacted = str_replace('_', '', $normalized);

        foreach (['secret', 'token', 'api_key', 'private_key', 'passphrase', 'password', 'signature', 'authorization', 'cookie'] as $needle) {
            if (str_contains($normalized, $needle) || str_contains($compacted, str_replace('_', '', $needle))) {
                return true;
            }
        }

        return $normalized === 'key'
            || str_ends_with($normalized, '_key')
            || str_ends_with($normalized, '_sign')
            || str_ends_with($compacted, 'key');
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = self::redact(
                $childValue,
                is_string($childKey) ? $childKey : null,
            );
        }

        return $redacted;
    }
}
