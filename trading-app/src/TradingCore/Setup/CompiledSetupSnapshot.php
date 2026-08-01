<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

final readonly class CompiledSetupSnapshot
{
    /**
     * @param array<string, string> $modeVersions
     * @param list<array{file: string, line_range: string, content_sha256: string, commit: string}> $sourceOrigins
     * @param array<string, mixed> $ast
     * @param array<string, string> $provenanceByKey
     */
    public function __construct(
        public string $setupId,
        public string $setupVersion,
        public array $modeVersions,
        public array $sourceOrigins,
        public string $configHash,
        public ?string $conditionCatalogHash,
        public bool $publishable,
        public array $ast,
        public array $provenanceByKey,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
