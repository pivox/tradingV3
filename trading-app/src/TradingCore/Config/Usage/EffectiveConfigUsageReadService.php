<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Usage;

use App\TradingCore\Config\Audit\EffectiveConfigSnapshotRegistryInterface;

final readonly class EffectiveConfigUsageReadService
{
    private const REFERENCE_PATTERN = '/\Aeffective-config-snapshot:(sha256:[0-9a-f]{64})\z/D';
    private const HASH_PATTERN = '/\Asha256:[0-9a-f]{64}\z/D';

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

        /**
         * @var array<string,array{
         *     source_counts:array{trade_lineage:int,order_intent:int,trade_lifecycle_event:int},
         *     config_hashes:array<string,true>,
         *     decision_ids:array<string,true>,
         *     trade_ids:array<string,true>,
         *     internal_trade_ids:array<string,true>
         * }> $groups
         */
        $groups = [];
        $found = false;
        foreach ($this->store->find($scope, $identifier) as $fact) {
            $found = true;
            if (preg_match(self::REFERENCE_PATTERN, $fact->effectiveConfigReference ?? '', $matches) !== 1) {
                throw $this->failure(
                    'effective_config_reference_missing',
                    422,
                    'Canonical lineage exists but does not contain a valid effective-config reference.',
                );
            }
            if (preg_match(self::HASH_PATTERN, $fact->configHash ?? '') !== 1) {
                throw $this->failure(
                    'effective_config_hash_conflict',
                    409,
                    'Canonical lineage does not contain a valid config hash.',
                );
            }

            $snapshotHash = $matches[1];
            if (!isset($groups[$snapshotHash])) {
                $groups[$snapshotHash] = [
                    'source_counts' => [
                        'trade_lineage' => 0,
                        'order_intent' => 0,
                        'trade_lifecycle_event' => 0,
                    ],
                    'config_hashes' => [],
                    'decision_ids' => [],
                    'trade_ids' => [],
                    'internal_trade_ids' => [],
                ];
            }
            if (!array_key_exists($fact->source, $groups[$snapshotHash]['source_counts'])) {
                throw new \LogicException('effective_config_usage_source_invalid');
            }
            ++$groups[$snapshotHash]['source_counts'][$fact->source];
            $groups[$snapshotHash]['config_hashes'][$fact->configHash] = true;
            $this->collect($groups[$snapshotHash]['decision_ids'], $fact->decisionId);
            $this->collect($groups[$snapshotHash]['trade_ids'], $fact->tradeId);
            $this->collect($groups[$snapshotHash]['internal_trade_ids'], $fact->internalTradeId);
        }
        if (!$found) {
            throw $this->failure(
                'effective_config_usage_not_found',
                404,
                'No canonical effective-config usage was found for the requested identifier.',
            );
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
        foreach ($groups as $snapshotHash => $group) {
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
            if (count($group['config_hashes']) !== 1 || !isset($group['config_hashes'][$documentConfigHash])) {
                throw $this->failure(
                    'effective_config_hash_conflict',
                    409,
                    'Canonical lineage config hash disagrees with the referenced snapshot.',
                );
            }

            $snapshots[] = [
                'snapshot' => ['document_kind' => 'historical_snapshot']
                    + array_diff_key($record->document, ['document_kind' => true]),
                'usage' => $this->usage($group),
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
     * @param array{
     *     source_counts:array{trade_lineage:int,order_intent:int,trade_lifecycle_event:int},
     *     config_hashes:array<string,true>,
     *     decision_ids:array<string,true>,
     *     trade_ids:array<string,true>,
     *     internal_trade_ids:array<string,true>
     * } $group
     *
     * @return array<string,int>
     */
    private function usage(array $group): array
    {
        return [
            'lineages' => $group['source_counts']['trade_lineage'],
            'order_intents' => $group['source_counts']['order_intent'],
            'lifecycle_events' => $group['source_counts']['trade_lifecycle_event'],
            'decision_ids' => count($group['decision_ids']),
            'trade_ids' => count($group['trade_ids']),
            'internal_trade_ids' => count($group['internal_trade_ids']),
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
