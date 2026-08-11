<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Catalog;

final readonly class ConditionCatalogResolver
{
    public function __construct(
        private ?ConditionCatalogLoader $loader = null,
        private ?string $catalogRoot = null,
    ) {
    }

    /** @param array<string, mixed> $document */
    public function forSetupDocument(array $document, ?ConditionCatalog $suppliedCatalog = null): ConditionCatalog
    {
        $dataContract = $document['data_condition_contract'] ?? null;
        $catalogDecision = is_array($dataContract) && !array_is_list($dataContract)
            ? ($dataContract['condition_catalog_hash'] ?? null)
            : null;
        $source = is_array($catalogDecision) && !array_is_list($catalogDecision)
            ? ($catalogDecision['source'] ?? null)
            : null;
        if (!is_string($source)
            || preg_match('#^config/trading/condition_catalog/((?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))\.yaml$#D', $source, $matches) !== 1
        ) {
            throw new ConditionCatalogException('Setup contract must pin an exact versioned condition catalog source.');
        }

        $sourceVersion = $matches[1];
        $declaredVersion = $dataContract['condition_catalog_version'] ?? $sourceVersion;
        if (!is_string($declaredVersion) || $declaredVersion !== $sourceVersion) {
            throw new ConditionCatalogException('Condition catalog version/source mismatch; resolution fails closed.');
        }

        $catalog = $suppliedCatalog ?? ($this->loader ?? new ConditionCatalogLoader())->loadVersion(
            $declaredVersion,
            $this->catalogRoot,
        );
        if ($catalog->catalogVersion !== $declaredVersion) {
            throw new ConditionCatalogException('Supplied condition catalog version mismatch; resolution fails closed.');
        }

        if (($catalogDecision['state'] ?? null) === 'defined') {
            $declaredHash = $catalogDecision['value'] ?? null;
            if (!is_string($declaredHash) || !hash_equals($catalog->stableHash(), $declaredHash)) {
                throw new ConditionCatalogException('Condition catalog hash mismatch; resolution fails closed.');
            }
        }

        return $catalog;
    }
}
