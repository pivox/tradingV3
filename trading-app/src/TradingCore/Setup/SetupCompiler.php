<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

use App\TradingCore\Setup\Exception\SetupContractException;

final class SetupCompiler
{
    public function compile(SetupContract $contract, ?ConditionCatalog $conditionCatalog = null): CompiledSetupSnapshot
    {
        $document = $contract->toArray();
        if ($conditionCatalog !== null) {
            $referencedConditions = [];
            foreach (['regime', 'context', 'trigger', 'confirmations'] as $role) {
                $this->collectConditions($document['context'][$role], $referencedConditions);
            }
            $this->collectConditions($document['filters'], $referencedConditions);
            $this->collectConditions($document['no_trade_rules'], $referencedConditions);
            $externalDependencies = array_column($document['data_condition_contract']['external_dependencies'], 'dependency_id');
            $missingConditions = array_values(array_diff(
                array_keys($referencedConditions),
                $conditionCatalog->conditionIds,
                $externalDependencies,
            ));
            sort($missingConditions, SORT_STRING);
            if ($missingConditions !== []) {
                throw new SetupContractException('Supplied condition catalog is missing: ' . implode(', ', $missingConditions) . '.');
            }
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
            if ($conditionCatalog === null) {
                throw new SetupContractException('Defined condition catalog hash requires a supplied typed condition catalog.');
            }
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

        return new CompiledSetupSnapshot(
            $contract->setupId,
            $contract->setupVersion,
            $modeVersions,
            $configHash,
            $catalogHash,
            $publishable,
            $ast,
            $provenance,
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
