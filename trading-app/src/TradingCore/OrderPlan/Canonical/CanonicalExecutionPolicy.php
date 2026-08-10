<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicy;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicyCompiler;

final readonly class CanonicalExecutionPolicy
{
    /**
     * @param non-empty-list<CanonicalTargetPolicy> $targets
     */
    private function __construct(
        public CanonicalRiskPolicy $riskPolicy,
        public CanonicalEntryZonePolicy $entryZone,
        public CanonicalStopPolicy $stop,
        public array $targets,
        public float $minimumNetR,
        public int $holdingWindowSeconds,
        public CanonicalCostContract $costContract,
        public string $configHash,
    ) {
    }

    public static function fromSnapshot(EffectiveTradingConfigSnapshot $snapshot): self
    {
        $payload = $snapshot->payload();
        if (!\is_string($snapshot->conditionCatalogHash)) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_hash_invalid');
        }
        $expectedHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $snapshot->conditionCatalogHash);
        if (!hash_equals($expectedHash, $snapshot->configHash)) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_hash_mismatch');
        }

        $riskPolicy = (new CanonicalRiskPolicyCompiler())->compile($snapshot);
        $setup = self::mapping($payload, 'setup', 'canonical_execution_policy_shape_invalid');
        $ast = self::mapping($setup, 'ast', 'canonical_execution_policy_shape_invalid');
        $execution = self::mapping($ast, 'execution', 'canonical_execution_policy_shape_invalid');
        self::requireExactKeys($execution, [
            'side',
            'entry_zone',
            'stop',
            'targets',
            'minimum_net_r',
            'invalidation',
            'time_stop',
            'cost_contract',
        ], 'canonical_execution_policy_shape_invalid');
        if (!\in_array($riskPolicy->side, ['long', 'short'], true) || ($execution['side'] ?? null) !== $riskPolicy->side) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_side_mismatch');
        }

        $entryZone = self::entryZone(self::decision($execution, 'entry_zone', 'price_zone_policy'));
        $stop = self::stop(self::decision($execution, 'stop', 'stop_policy'));
        $targets = self::targets(self::decision($execution, 'targets', 'target_policy'));
        $minimumNetR = self::positiveNumber(
            self::decision($execution, 'minimum_net_r', 'net_r_multiple'),
            'canonical_minimum_net_r_invalid',
        );
        self::invalidation(self::decision($execution, 'invalidation', 'invalidation_policy'));
        $holdingWindowSeconds = self::durationSeconds(self::decision($execution, 'time_stop', 'duration'));
        $costContract = self::costContract(self::decision($execution, 'cost_contract', 'cost_policy'));

        return new self(
            riskPolicy: $riskPolicy,
            entryZone: $entryZone,
            stop: $stop,
            targets: $targets,
            minimumNetR: $minimumNetR,
            holdingWindowSeconds: $holdingWindowSeconds,
            costContract: $costContract,
            configHash: $snapshot->configHash,
        );
    }

    /** @param array<string, mixed> $execution */
    private static function decision(array $execution, string $key, string $unit): mixed
    {
        $decision = self::mapping($execution, $key, 'canonical_execution_policy_unresolved');
        self::requireExactKeys($decision, ['state', 'value', 'unit', 'source', 'justification'], 'canonical_execution_policy_shape_invalid');
        if (($decision['state'] ?? null) !== 'defined' || !array_key_exists('value', $decision) || $decision['value'] === null) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_unresolved', ['decision' => $key]);
        }
        if (($decision['unit'] ?? null) !== $unit) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_unit_invalid', ['decision' => $key]);
        }
        foreach (['source', 'justification'] as $metadata) {
            if (!\is_string($decision[$metadata] ?? null) || trim($decision[$metadata]) === '') {
                throw new CanonicalOrderPlanException('canonical_execution_policy_shape_invalid', ['decision' => $key]);
            }
        }

        return $decision['value'];
    }

    private static function entryZone(mixed $value): CanonicalEntryZonePolicy
    {
        $policy = self::objectValue($value, 'canonical_entry_zone_policy_shape_invalid');
        self::requireExactKeys($policy, [
            'anchor_source',
            'anchor_timeframe',
            'atr_timeframe',
            'atr_multiplier',
            'minimum_half_width_rate',
            'maximum_half_width_rate',
            'asymmetry_rate',
            'ttl_seconds',
            'maximum_input_age_seconds',
            'quantize_outward',
        ], 'canonical_entry_zone_policy_shape_invalid');

        $minimum = self::positiveRate($policy['minimum_half_width_rate'], 'canonical_entry_zone_policy_invalid');
        $maximum = self::positiveRate($policy['maximum_half_width_rate'], 'canonical_entry_zone_policy_invalid');
        if ($minimum > $maximum) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_policy_invalid');
        }
        $asymmetry = self::finiteNumber($policy['asymmetry_rate'], 'canonical_entry_zone_policy_invalid');
        if ($asymmetry < -0.95 || $asymmetry > 0.95) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_policy_invalid');
        }
        if (($policy['quantize_outward'] ?? null) !== true) {
            throw new CanonicalOrderPlanException('canonical_entry_zone_policy_invalid');
        }

        return new CanonicalEntryZonePolicy(
            anchorSource: self::identifier($policy['anchor_source'], 'canonical_entry_zone_policy_invalid'),
            anchorTimeframe: self::identifier($policy['anchor_timeframe'], 'canonical_entry_zone_policy_invalid'),
            atrTimeframe: self::identifier($policy['atr_timeframe'], 'canonical_entry_zone_policy_invalid'),
            atrMultiplier: self::positiveNumber($policy['atr_multiplier'], 'canonical_entry_zone_policy_invalid'),
            minimumHalfWidthRate: $minimum,
            maximumHalfWidthRate: $maximum,
            asymmetryRate: $asymmetry,
            ttlSeconds: self::positiveInteger($policy['ttl_seconds'], 'canonical_entry_zone_policy_invalid'),
            maximumInputAgeSeconds: self::positiveInteger($policy['maximum_input_age_seconds'], 'canonical_entry_zone_policy_invalid'),
            quantizeOutward: true,
        );
    }

    private static function stop(mixed $value): CanonicalStopPolicy
    {
        $policy = self::objectValue($value, 'canonical_stop_policy_shape_invalid');
        self::requireExactKeys($policy, ['kind', 'timeframe', 'atr_multiplier', 'pivot_id', 'buffer_rate'], 'canonical_stop_policy_shape_invalid');
        $kind = $policy['kind'] ?? null;
        $atrMultiplier = null;
        $pivotId = null;
        if ($kind === 'atr') {
            $atrMultiplier = self::positiveNumber($policy['atr_multiplier'], 'canonical_stop_policy_invalid');
            if ($policy['pivot_id'] !== null) {
                throw new CanonicalOrderPlanException('canonical_stop_policy_invalid');
            }
        } elseif ($kind === 'pivot') {
            if ($policy['atr_multiplier'] !== null) {
                throw new CanonicalOrderPlanException('canonical_stop_policy_invalid');
            }
            $pivotId = self::identifier($policy['pivot_id'], 'canonical_stop_policy_invalid');
        } else {
            throw new CanonicalOrderPlanException('canonical_stop_policy_invalid');
        }

        return new CanonicalStopPolicy(
            kind: $kind,
            timeframe: self::identifier($policy['timeframe'], 'canonical_stop_policy_invalid'),
            atrMultiplier: $atrMultiplier,
            pivotId: $pivotId,
            bufferRate: self::nonNegativeRate($policy['buffer_rate'], 'canonical_stop_policy_invalid'),
        );
    }

    /** @return non-empty-list<CanonicalTargetPolicy> */
    private static function targets(mixed $value): array
    {
        if (!\is_array($value) || !array_is_list($value) || $value === []) {
            throw new CanonicalOrderPlanException('canonical_target_policy_invalid');
        }
        $targets = [];
        $ids = [];
        $previousMultiple = 0.0;
        foreach ($value as $item) {
            $target = self::objectValue($item, 'canonical_target_policy_shape_invalid');
            self::requireExactKeys($target, ['id', 'risk_multiple', 'liquidity_role'], 'canonical_target_policy_shape_invalid');
            $id = self::identifier($target['id'], 'canonical_target_policy_invalid');
            $multiple = self::positiveNumber($target['risk_multiple'], 'canonical_target_policy_invalid');
            $role = $target['liquidity_role'] ?? null;
            if (isset($ids[$id]) || $multiple <= $previousMultiple || !\in_array($role, ['maker', 'taker'], true)) {
                throw new CanonicalOrderPlanException('canonical_target_policy_invalid');
            }
            $ids[$id] = true;
            $previousMultiple = $multiple;
            $targets[] = new CanonicalTargetPolicy($id, $multiple, $role);
        }

        return $targets;
    }

    private static function invalidation(mixed $value): void
    {
        $policy = self::objectValue($value, 'canonical_invalidation_policy_invalid');
        self::requireExactKeys($policy, ['kind'], 'canonical_invalidation_policy_invalid');
        if (($policy['kind'] ?? null) !== 'close_beyond_stop') {
            throw new CanonicalOrderPlanException('canonical_invalidation_policy_invalid');
        }
    }

    private static function durationSeconds(mixed $value): int
    {
        if (!\is_string($value) || preg_match('/\APT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?\z/D', $value, $matches) !== 1) {
            throw new CanonicalOrderPlanException('canonical_time_stop_invalid');
        }
        $hours = isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
        $seconds = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : 0;
        if ($hours === 0 && $minutes === 0 && $seconds === 0) {
            throw new CanonicalOrderPlanException('canonical_time_stop_invalid');
        }

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }

    private static function costContract(mixed $value): CanonicalCostContract
    {
        $contract = self::objectValue($value, 'canonical_cost_contract_shape_invalid');
        self::requireExactKeys($contract, [
            'entry_spread_source',
            'entry_slippage_source',
            'stop_spread_source',
            'stop_slippage_source',
            'target_spread_source',
            'target_slippage_source',
            'funding_source',
            'funding_interval_seconds',
        ], 'canonical_cost_contract_shape_invalid');

        return new CanonicalCostContract(
            entrySpreadSource: self::identifier($contract['entry_spread_source'], 'canonical_cost_contract_invalid'),
            entrySlippageSource: self::identifier($contract['entry_slippage_source'], 'canonical_cost_contract_invalid'),
            stopSpreadSource: self::identifier($contract['stop_spread_source'], 'canonical_cost_contract_invalid'),
            stopSlippageSource: self::identifier($contract['stop_slippage_source'], 'canonical_cost_contract_invalid'),
            targetSpreadSource: self::identifier($contract['target_spread_source'], 'canonical_cost_contract_invalid'),
            targetSlippageSource: self::identifier($contract['target_slippage_source'], 'canonical_cost_contract_invalid'),
            fundingSource: self::identifier($contract['funding_source'], 'canonical_cost_contract_invalid'),
            fundingIntervalSeconds: self::positiveInteger($contract['funding_interval_seconds'], 'canonical_cost_contract_invalid'),
        );
    }

    /**
     * @param array<string, mixed> $owner
     * @return array<string, mixed>
     */
    private static function mapping(array $owner, string $key, string $reasonCode): array
    {
        return self::objectValue($owner[$key] ?? null, $reasonCode);
    }

    /** @return array<string, mixed> */
    private static function objectValue(mixed $value, string $reasonCode): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $expected
     */
    private static function requireExactKeys(array $value, array $expected, string $reasonCode): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
    }

    private static function identifier(mixed $value, string $reasonCode): string
    {
        if (!\is_string($value) || preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $value) !== 1) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $value;
    }

    private static function finiteNumber(mixed $value, string $reasonCode): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !\is_finite((float) $value)) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return (float) $value;
    }

    private static function positiveNumber(mixed $value, string $reasonCode): float
    {
        $number = self::finiteNumber($value, $reasonCode);
        if ($number <= 0.0) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $number;
    }

    private static function positiveRate(mixed $value, string $reasonCode): float
    {
        $rate = self::positiveNumber($value, $reasonCode);
        if ($rate >= 1.0) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $rate;
    }

    private static function nonNegativeRate(mixed $value, string $reasonCode): float
    {
        $rate = self::finiteNumber($value, $reasonCode);
        if ($rate < 0.0 || $rate >= 1.0) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $rate;
    }

    private static function positiveInteger(mixed $value, string $reasonCode): int
    {
        if (!\is_int($value) || $value <= 0) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $value;
    }
}
