<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Setup\Exception\SetupContractException;

final class SetupCompiler
{
    public function compile(SetupContract $contract, ?ConditionCatalog $conditionCatalog = null): CompiledSetupSnapshot
    {
        $conditionCatalog ??= (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        );
        $document = $contract->toArray();
        $referencedConditions = [];
        foreach (['regime', 'context', 'trigger', 'confirmations'] as $role) {
            $this->collectConditions($document['context'][$role], $referencedConditions);
        }
        $this->collectConditions($document['filters'], $referencedConditions);
        $this->collectConditions($document['no_trade_rules'], $referencedConditions);
        $externalDependencies = array_column($document['data_condition_contract']['external_dependencies'], 'dependency_id');
        $missingConditions = array_values(array_diff(
            array_keys($referencedConditions),
            $conditionCatalog->conditionIds(),
            $externalDependencies,
        ));
        sort($missingConditions, SORT_STRING);
        if ($missingConditions !== []) {
            throw new SetupContractException('Supplied condition catalog is missing: ' . implode(', ', $missingConditions) . '.');
        }
        $modeVersions = [];
        foreach ($document['compatible_modes'] as $mode) {
            $modeVersions[$mode['mode_id']] = $mode['mode_version'];
        }
        ksort($modeVersions);
        $provenance = [];
        foreach ($document['provenance'] as $row) {
            $provenance[$row['path']] = $row['source'];
        }
        ksort($provenance);
        $canonical = $this->canonicalize($document);
        $configHash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $catalogDecision = $document['data_condition_contract']['condition_catalog_hash'];
        $catalogHash = $catalogDecision['state'] === 'defined' ? $catalogDecision['value'] : null;
        if ($catalogHash !== null) {
            if (!hash_equals($catalogHash, $conditionCatalog->stableHash())) {
                throw new SetupContractException('Condition catalog hash mismatch; compilation fails closed.');
            }
        }
        $ast = [
            'kind' => 'setup',
            'side' => $contract->side,
            'regime' => $this->canonicalize($document['context']['regime']),
            'context' => $this->canonicalize($document['context']['context']),
            'trigger' => $this->canonicalize($document['context']['trigger']),
            'confirmations' => $this->canonicalize($document['context']['confirmations']),
            'filters' => $this->canonicalize(['op' => 'all_of', 'nodes' => $document['filters']]),
            'no_trade_rules' => $this->canonicalize(['op' => 'all_of', 'nodes' => $document['no_trade_rules']]),
            'execution' => $this->canonicalize($document['execution']),
        ];
        $publishable = $contract->isExecutable()
            && $catalogHash !== null
            && $document['governance']['activation_requires_trace']
            && $document['governance']['activation_requires_certified_net_baseline'];
        $blockers = [];
        if (!$contract->isExecutable()) {
            $blockers[] = 'contract_not_executable:' . $contract->status;
        }
        foreach ($contract->unresolvedPaths() as $path) {
            $blockers[] = 'unresolved:' . $path;
        }
        foreach ($document['data_condition_contract']['missing_conditions'] as $conditionId) {
            $blockers[] = 'missing_condition:' . $conditionId;
        }
        if ($catalogHash === null) {
            $blockers[] = 'condition_catalog_hash_unresolved';
        }
        if (!$publishable) {
            $blockers[] = 'compiled_snapshot_not_publishable';
        }
        $blockers = array_values(array_unique($blockers));
        sort($blockers, SORT_STRING);

        $canonicalPayload = $this->canonicalize([
            'schema_version' => 'compiled-setup.v1',
            'setup_id' => $contract->setupId,
            'setup_version' => $contract->setupVersion,
            'status' => $contract->status,
            'executable' => $contract->isExecutable(),
            'publishable' => $publishable,
            'family' => $document['family'],
            'side' => $contract->side,
            'thesis' => $document['thesis'],
            'hypothesis' => $document['hypothesis'],
            'mode_versions' => $modeVersions,
            'mode_compatibility' => $document['mode_compatibility'],
            'ast' => $ast,
            'missing_data_policy' => $document['missing_data_policy'],
            'data_condition_contract' => $document['data_condition_contract'],
            'validity_window' => $document['validity_window'],
            'governance' => $document['governance'],
            'known_defects' => $document['known_defects'],
            'ownership_model' => $document['ownership_model'],
            'source_origins' => $document['source_origins'] ?? [$document['source_origin']],
            'contract_provenance' => $provenance,
            'contract_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'blockers' => $blockers,
        ]);
        $canonicalPayload['payload_hash'] = hash('sha256', json_encode(
            $canonicalPayload,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $canonicalPayload = $this->canonicalize($canonicalPayload);

        return new CompiledSetupSnapshot(
            $contract->setupId,
            $contract->setupVersion,
            $modeVersions,
            $document['source_origins'] ?? [$document['source_origin']],
            $configHash,
            $catalogHash,
            $publishable,
            $ast,
            $provenance,
            $canonicalPayload,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /** @param array<string, true> $conditions */
    private function collectConditions(mixed $node, array &$conditions): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['condition']) && is_string($node['condition'])) {
            $conditions[$node['condition']] = true;
        }
        foreach ($node as $value) {
            $this->collectConditions($value, $conditions);
        }
    }
}
