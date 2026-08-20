<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Usage;

use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;

final readonly class EffectiveConfigUsageReadService
{
    private const REFERENCE_PATTERN = '/\Aeffective-config-snapshot:(sha256:[0-9a-f]{64})\z/D';

    public function __construct(
        private EffectiveConfigUsageStoreInterface $store,
        private EffectiveConfigSnapshotRegistryInterface $registry,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(EffectiveConfigUsageScope $scope, string $identifier): array
    {
        if ($identifier === ''
            || $identifier !== trim($identifier)
            || strlen($identifier) > $scope->maxIdentifierLength()) {
            throw $this->failure(
                'invalid_effective_config_usage_identifier',
                400,
                'A non-empty canonical identifier within the persistent contract length is required.',
            );
        }

        $facts = $this->store->find($scope, $identifier);
        if ($facts === []) {
            throw $this->failure(
                'effective_config_usage_not_found',
                404,
                'No canonical effective-config usage was found for the requested identifier.',
            );
        }

        /** @var array<string,list<EffectiveConfigUsageFact>> $groups */
        $groups = [];
        foreach ($facts as $fact) {
            if (preg_match(self::REFERENCE_PATTERN, $fact->effectiveConfigReference ?? '', $matches) !== 1) {
                throw $this->failure(
                    'effective_config_reference_missing',
                    422,
                    'Canonical lineage exists but does not contain a valid effective-config reference.',
                );
            }
            $groups[$matches[1]][] = $fact;
        }

        if ($scope->requiresUniqueSnapshot() && count($groups) !== 1) {
            throw $this->failure(
                'effective_config_usage_conflict',
                409,
                'The canonical identifier refers to more than one effective-config snapshot.',
            );
        }

        ksort($groups, SORT_STRING);
        $snapshots = [];
        foreach ($groups as $snapshotHash => $groupFacts) {
            $record = $this->registry->find($snapshotHash);
            if ($record === null) {
                throw $this->failure(
                    'effective_config_snapshot_unregistered',
                    409,
                    'Canonical lineage refers to an unregistered effective-config snapshot.',
                );
            }

            $documentConfigHash = $record->document['config_hash'] ?? null;
            if (!is_string($documentConfigHash) || $documentConfigHash === '') {
                throw new \LogicException('effective_config_snapshot_invalid');
            }
            foreach ($groupFacts as $fact) {
                if ($fact->configHash !== null && !hash_equals($documentConfigHash, $fact->configHash)) {
                    throw $this->failure(
                        'effective_config_hash_conflict',
                        409,
                        'Canonical lineage config hash disagrees with the referenced snapshot.',
                    );
                }
            }

            $snapshots[] = [
                'snapshot' => ['document_kind' => 'historical_snapshot']
                    + array_diff_key($record->document, ['document_kind' => true]),
                'usage' => $this->usage($groupFacts),
            ];
        }

        return [
            'scope' => $scope->value,
            'identifier' => $identifier,
            'count' => count($snapshots),
            'snapshots' => $snapshots,
        ];
    }

    /**
     * @param list<EffectiveConfigUsageFact> $facts
     *
     * @return array<string,int>
     */
    private function usage(array $facts): array
    {
        $sourceCounts = [
            'trade_lineage' => 0,
            'order_intent' => 0,
            'trade_lifecycle_event' => 0,
        ];
        $decisionIds = [];
        $tradeIds = [];
        $internalTradeIds = [];
        foreach ($facts as $fact) {
            if (!array_key_exists($fact->source, $sourceCounts)) {
                throw new \LogicException('effective_config_usage_source_invalid');
            }
            ++$sourceCounts[$fact->source];
            $this->collect($decisionIds, $fact->decisionId);
            $this->collect($tradeIds, $fact->tradeId);
            $this->collect($internalTradeIds, $fact->internalTradeId);
        }

        return [
            'lineages' => $sourceCounts['trade_lineage'],
            'order_intents' => $sourceCounts['order_intent'],
            'lifecycle_events' => $sourceCounts['trade_lifecycle_event'],
            'decision_ids' => count($decisionIds),
            'trade_ids' => count($tradeIds),
            'internal_trade_ids' => count($internalTradeIds),
        ];
    }

    /** @param array<string,true> $values */
    private function collect(array &$values, ?string $value): void
    {
        if ($value !== null && trim($value) !== '') {
            $values[$value] = true;
        }
    }

    private function failure(string $code, int $status, string $message): EffectiveConfigUsageReadException
    {
        return new EffectiveConfigUsageReadException($code, $status, $message);
    }
}
