<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Configuration;

final readonly class PaperConfigurationSnapshot
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $configuration */
    public function __construct(
        public string $id,
        public string $canonicalJson,
        public array $configuration,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_schema_unsupported');
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $id) !== 1) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_id_invalid');
        }
        if (!hash_equals($id, 'sha256:' . hash('sha256', $canonicalJson))) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_id_mismatch');
        }
    }
}
