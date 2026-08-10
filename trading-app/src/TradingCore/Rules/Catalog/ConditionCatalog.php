<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Catalog;

final readonly class ConditionCatalog
{
    /**
     * @param array<string, array<string, int>> $inputFreshnessSeconds
     * @param array<string, ConditionDefinition> $definitions
     */
    public function __construct(
        public string $schemaVersion,
        public string $catalogVersion,
        private array $inputFreshnessSeconds,
        private array $definitions,
    ) {
    }

    /** @return list<string> */
    public function conditionIds(): array
    {
        return array_keys($this->definitions);
    }

    public function definition(string $conditionId): ConditionDefinition
    {
        return $this->definitions[$conditionId]
            ?? throw new ConditionCatalogException(sprintf('Unknown condition "%s".', $conditionId));
    }

    public function stableHash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function freshnessSeconds(string $source, string $timeframe): int
    {
        return $this->inputFreshnessSeconds[$source][$timeframe]
            ?? throw new ConditionCatalogException(sprintf('No freshness contract for %s/%s.', $source, $timeframe));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'catalog_version' => $this->catalogVersion,
            'input_freshness_seconds' => $this->inputFreshnessSeconds,
            'conditions' => array_map(
                static fn (ConditionDefinition $definition): array => $definition->toArray(),
                array_values($this->definitions),
            ),
        ];
    }
}
