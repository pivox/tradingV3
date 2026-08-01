<?php

declare(strict_types=1);

namespace App\TradingCore\Setup;

use App\TradingCore\Setup\Exception\SetupContractException;

final class SetupCompiler
{
    /** @param list<string> $conditionNames */
    public function compile(SetupContract $contract, array $conditionNames = []): CompiledSetupSnapshot
    {
        foreach ($conditionNames as $condition) {
            if (!in_array($condition, SetupContractValidator::CONDITION_IDS, true)) {
                throw new SetupContractException(sprintf('Unknown condition "%s"; compilation is non-publishable and fails closed.', $condition));
            }
        }
        $document = $contract->toArray();
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
        $ast = [
            'kind' => 'setup',
            'side' => $contract->side,
            'regime' => $this->canonicalize($document['context']['regime']),
            'context' => $this->canonicalize($document['context']['context']),
            'trigger' => $this->canonicalize($document['context']['trigger']),
            'confirmations' => $this->canonicalize($document['context']['confirmations']),
            'filters' => $this->canonicalize(['op' => 'all_of', 'nodes' => $document['filters']]),
            'no_trade_rules' => $this->canonicalize(['op' => 'all_of', 'nodes' => $document['no_trade_rules']]),
            'execution' => $document['execution'],
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
}
