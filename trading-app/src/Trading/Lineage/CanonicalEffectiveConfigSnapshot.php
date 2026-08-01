<?php

declare(strict_types=1);

namespace App\Trading\Lineage;

final readonly class CanonicalEffectiveConfigSnapshot
{
    /** @param array<string,mixed> $snapshot */
    private function __construct(private array $snapshot) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,string|null> $identity
     */
    public static function fromArray(array $snapshot, array $identity): self
    {
        foreach (['request', 'config', 'config_hash', 'condition_catalog_hash', 'executable', 'blockers'] as $field) {
            if (!\array_key_exists($field, $snapshot)) {
                throw new LineageContextException('canonical_identity_missing:effective_config_snapshot.' . $field);
            }
        }
        if (!\is_array($snapshot['request']) || !\is_array($snapshot['config']) || !\is_array($snapshot['blockers'])) {
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot');
        }
        foreach (['config_hash', 'condition_catalog_hash'] as $field) {
            if (!\is_string($snapshot[$field]) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot[$field]) !== 1) {
                throw new LineageContextException('canonical_identity_invalid:' . $field);
            }
            if (($identity[$field] ?? null) !== $snapshot[$field]) {
                throw new LineageContextException('canonical_identity_mismatch:' . $field);
            }
        }
        foreach (['mode_id', 'mode_version', 'setup_id', 'setup_version', 'exchange'] as $field) {
            if (($snapshot['request'][$field] ?? null) !== ($identity[$field] ?? null)) {
                throw new LineageContextException('canonical_identity_mismatch:' . $field);
            }
        }
        if (($snapshot['request']['side'] ?? null) !== strtolower((string) ($identity['side'] ?? ''))) {
            throw new LineageContextException('canonical_identity_mismatch:side');
        }
        if (!\is_string($snapshot['request']['environment'] ?? null) || $snapshot['request']['environment'] === '') {
            throw new LineageContextException('canonical_identity_missing:environment');
        }
        if (($identity['environment'] ?? null) !== $snapshot['request']['environment']) {
            throw new LineageContextException('canonical_identity_mismatch:environment');
        }
        $computed = self::calculateConfigHash($snapshot['config'], $snapshot['condition_catalog_hash']);
        if ($computed !== $snapshot['config_hash']) {
            throw new LineageContextException('canonical_identity_mismatch:config_hash');
        }
        return new self(self::canonicalize($snapshot));
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->snapshot; }
    public function executable(): bool { return $this->snapshot['executable'] === true && $this->snapshot['blockers'] === []; }
    /** @return array<string,mixed> */
    public function config(): array { return $this->snapshot['config']; }

    /** @param array<string,mixed> $config */
    public static function calculateConfigHash(array $config, string $conditionCatalogHash): string
    {
        return 'sha256:' . hash('sha256', json_encode(
            ['config' => self::canonicalize($config), 'condition_catalog_hash' => $conditionCatalogHash],
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            if (\is_float($value) && !\is_finite($value)) {
                throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot');
            }
            if ($value === null || \is_scalar($value)) { return $value; }
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot');
        }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item); }
        return $value;
    }
}
