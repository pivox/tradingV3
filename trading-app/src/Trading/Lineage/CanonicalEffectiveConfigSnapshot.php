<?php

declare(strict_types=1);

namespace App\Trading\Lineage;

final readonly class CanonicalEffectiveConfigSnapshot
{
    private const LAYERS = ['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'];
    /** @param array<string,mixed> $snapshot */
    private function __construct(private array $snapshot) {}

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,string|null> $identity
     */
    public static function fromArray(array $snapshot, array $identity): self
    {
        $fields = ['request', 'config', 'config_hash', 'condition_catalog_hash', 'ordered_layers', 'ordered_files', 'provenance', 'executable', 'blockers'];
        foreach ($fields as $field) {
            if (!\array_key_exists($field, $snapshot)) {
                throw new LineageContextException('canonical_identity_missing:effective_config_snapshot.' . $field);
            }
        }
        if (array_diff(array_keys($snapshot), $fields) !== [] || !\is_array($snapshot['request']) || !\is_array($snapshot['config']) || !\is_array($snapshot['blockers'])) {
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot');
        }
        self::assertMetadata($snapshot);
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

    /** @param array<string,mixed> $snapshot */
    private static function assertMetadata(array $snapshot): void
    {
        $layers = $snapshot['ordered_layers'];
        $files = $snapshot['ordered_files'];
        $provenance = $snapshot['provenance'];
        if (!\is_array($layers) || !array_is_list($layers) || !\is_array($files) || !array_is_list($files)
            || !\is_array($provenance) || $provenance === [] || array_is_list($provenance)) {
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot.metadata');
        }
        if (array_column($layers, 'type') !== self::LAYERS || array_column($layers, 'path') !== $files) {
            throw new LineageContextException('canonical_identity_mismatch:effective_config_snapshot.ordered_files');
        }
        foreach ($layers as $layer) {
            self::assertLayer($layer);
        }
        foreach ($provenance as $path => $layer) {
            if (!\is_string($path) || $path === '') {
                throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot.provenance');
            }
            self::assertLayer($layer);
        }
    }

    private static function assertLayer(mixed $layer): void
    {
        if (!\is_array($layer)) {
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot.layer');
        }
        $keys = array_keys($layer);
        sort($keys, SORT_STRING);
        if ($keys !== ['name', 'path', 'required', 'type']
            || !\is_string($layer['type']) || $layer['type'] === ''
            || !\is_string($layer['name']) || $layer['name'] === ''
            || !\is_string($layer['path']) || $layer['path'] === ''
            || $layer['required'] !== true) {
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot.layer');
        }
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
            self::canonicalize(['config' => $config, 'condition_catalog_hash' => $conditionCatalogHash], true),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private static function canonicalize(mixed $value, bool $normalizeIntegralFloats = false): mixed
    {
        if (!\is_array($value)) {
            if (\is_float($value)) {
                if (!\is_finite($value)) {
                    throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot');
                }
                if ($normalizeIntegralFloats && floor($value) === $value && $value >= PHP_INT_MIN && $value <= PHP_INT_MAX) {
                    return (int) $value;
                }
            }
            if ($value === null || \is_scalar($value)) { return $value; }
            throw new LineageContextException('canonical_identity_invalid:effective_config_snapshot');
        }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { $value[$key] = self::canonicalize($item, $normalizeIntegralFloats); }
        return $value;
    }
}
