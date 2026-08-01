<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Configuration;

use App\Trading\Paper\MarketData\CanonicalJson;

final class PaperConfigurationSnapshotFactory
{
    /** @var list<string> */
    private const ALLOWED_SECTIONS = [
        'strategy',
        'risk',
        'execution',
        'models',
        'symbols',
        'runtime',
    ];

    private const FORBIDDEN_KEY_PATTERN = '/(?:\A|_)(?:api_?key|apikey|secret|token|password|passphrase|credential|wallet|signer|signature|private_?key)(?:_|\z)/D';

    /** @param array<array-key, mixed> $configuration */
    public function create(#[\SensitiveParameter] array $configuration): PaperConfigurationSnapshot
    {
        $this->assertAllowedSections($configuration);

        $canonicalJson = CanonicalJson::encode([
            'schema_version' => PaperConfigurationSnapshot::SCHEMA_VERSION,
            'configuration' => $configuration,
        ]);

        try {
            $detached = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_encoding_failed');
        }
        if (!\is_array($detached) || !\is_array($detached['configuration'] ?? null)) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_shape_invalid');
        }

        /** @var array<string, mixed> $safeConfiguration */
        $safeConfiguration = $detached['configuration'];
        $this->assertSafeKeys($safeConfiguration);

        return new PaperConfigurationSnapshot(
            id: 'sha256:' . hash('sha256', $canonicalJson),
            canonicalJson: $canonicalJson,
            configuration: $safeConfiguration,
        );
    }

    /** @param array<array-key, mixed> $configuration */
    private function assertAllowedSections(array $configuration): void
    {
        if ($configuration === [] || array_is_list($configuration)) {
            throw new \InvalidArgumentException('paper_configuration_section_not_allowed');
        }

        foreach (array_keys($configuration) as $section) {
            if (!\is_string($section) || !\in_array($section, self::ALLOWED_SECTIONS, true)) {
                throw new \InvalidArgumentException('paper_configuration_section_not_allowed');
            }
        }
    }

    private function assertSafeKeys(mixed $value): void
    {
        if (!\is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            if (\is_string($key) && preg_match(self::FORBIDDEN_KEY_PATTERN, $this->normalizeKey($key)) === 1) {
                throw new \InvalidArgumentException('paper_configuration_forbidden_key');
            }

            $this->assertSafeKeys($item);
        }
    }

    private function normalizeKey(string $key): string
    {
        $key = preg_replace('/(?<!\A)[A-Z]/', '_$0', $key) ?? $key;
        $key = strtolower($key);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key, '_');
    }
}
