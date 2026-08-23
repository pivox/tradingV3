<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicy;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicyCompiler;
use Brick\Math\BigInteger;

final readonly class CanonicalExecutionPolicy
{
    /**
     * @param non-empty-list<CanonicalTargetPolicy> $targets
     * @param list<string>                           $mandatoryConfirmations
     * @param array<string, mixed>                   $holdingHorizon
     * @param list<string>                           $allowedSymbols
     * @param list<string>                           $allowedMarkets
     */
    private function __construct(
        public CanonicalRiskPolicy $riskPolicy,
        public CanonicalEntryZonePolicy $entryZone,
        public CanonicalStopPolicy $stop,
        public array $targets,
        public float $minimumNetR,
        public int $holdingWindowSeconds,
        public CanonicalCostContract $costContract,
        public ?string $executionTimeframe,
        public array $mandatoryConfirmations,
        public ?CanonicalOrderPolicy $orderPolicy,
        public array $holdingHorizon,
        public array $allowedSymbols,
        public array $allowedMarkets,
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
        self::validateCanonicalSchema($payload, $snapshot);

        $riskPolicy = (new CanonicalRiskPolicyCompiler())->compile($snapshot);
        $setup = self::mapping($payload, 'setup', 'canonical_execution_policy_shape_invalid');
        $ast = self::mapping($setup, 'ast', 'canonical_execution_policy_shape_invalid');
        $execution = self::mapping($ast, 'execution', 'canonical_execution_policy_shape_invalid');
        $shadowIdentity = $snapshot->request->modeId . '@' . $snapshot->request->modeVersion;
        $scalpingShadow = $shadowIdentity === 'scalping@1.1.0'
            && $snapshot->request->setupVersion === '1.1.0'
            && \in_array($snapshot->request->setupId, [
                'scalping.trend_continuation.long',
                'scalping.pullback.long',
                'scalping.trend_momentum.short',
            ], true);
        $microScalpingShadow = $shadowIdentity === 'micro_scalping@1.1.0'
            && $snapshot->request->setupVersion === '1.1.0'
            && \in_array($snapshot->request->setupId, [
                'micro_scalping.momentum_ofi.long',
                'micro_scalping.momentum_ofi.short',
            ], true);
        $shadow = $shadowIdentity === 'day_trading@1.1.0' || $scalpingShadow || $microScalpingShadow;
        $executionKeys = [
            'side',
            'entry_zone',
            'stop',
            'targets',
            'minimum_net_r',
            'invalidation',
            'time_stop',
            'cost_contract',
        ];
        if ($shadow) {
            $executionKeys = ['side', 'execution_timeframe', 'mandatory_confirmations', ...array_slice($executionKeys, 1), 'order_policy'];
        }
        self::requireExactKeys($execution, $executionKeys, 'canonical_execution_policy_shape_invalid');
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
        $costContract = self::costContract(self::decision($execution, 'cost_contract', 'cost_policy'), $shadow);
        $costContract = self::withVenueFundingSchedule(
            $costContract,
            self::mapping($payload, 'exchange', 'canonical_cost_contract_venue_schedule_invalid'),
        );
        $executionTimeframe = null;
        $mandatoryConfirmations = [];
        $orderPolicy = null;
        if ($shadow) {
            $executionTimeframe = self::identifier(self::decision($execution, 'execution_timeframe', 'timeframe'), 'canonical_execution_timeframe_invalid');
            $mandatoryConfirmations = self::stringList(self::decision($execution, 'mandatory_confirmations', 'timeframes'), 'canonical_mandatory_confirmations_invalid');
            $expectedTimeframes = match ($shadowIdentity) {
                'day_trading@1.1.0' => ['15m', ['5m', '1m']],
                'scalping@1.1.0' => ['5m', ['1m']],
                'micro_scalping@1.1.0' => ['1m', ['1m']],
                default => null,
            };
            if ($expectedTimeframes === null
                || $executionTimeframe !== $expectedTimeframes[0]
                || $mandatoryConfirmations !== $expectedTimeframes[1]
            ) {
                throw new CanonicalOrderPlanException('canonical_execution_timeframe_invalid');
            }
            $orderPolicy = self::orderPolicy(self::decision($execution, 'order_policy', 'order_policy'));
        }
        $holdingHorizon = [];
        if ($shadow) {
            $mode = self::mapping($payload, 'mode', 'canonical_holding_boundary_invalid');
            $holdingHorizon = self::objectValue(self::decision($mode, 'horizon', 'holding_horizon_policy'), 'canonical_holding_boundary_invalid');
            CanonicalHoldingBoundary::expiresAt(new \DateTimeImmutable('2026-08-10T12:00:00Z'), $holdingWindowSeconds, $holdingHorizon);
            self::assertShadowIdentityPolicy(
                $snapshot,
                $orderPolicy,
                $holdingWindowSeconds,
                $holdingHorizon,
            );
        }
        $environment = self::mapping($payload, 'environment', 'canonical_execution_policy_environment_invalid');
        $allowedSymbols = self::stringList($environment['allowed_symbols'] ?? null, 'canonical_execution_policy_environment_invalid');
        $allowedMarkets = self::stringList($environment['allowed_markets'] ?? null, 'canonical_execution_policy_environment_invalid');
        if ($allowedSymbols === [] && $allowedMarkets === []) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_environment_invalid');
        }

        return new self(
            riskPolicy: $riskPolicy,
            entryZone: $entryZone,
            stop: $stop,
            targets: $targets,
            minimumNetR: $minimumNetR,
            holdingWindowSeconds: $holdingWindowSeconds,
            costContract: $costContract,
            executionTimeframe: $executionTimeframe,
            mandatoryConfirmations: $mandatoryConfirmations,
            orderPolicy: $orderPolicy,
            holdingHorizon: $holdingHorizon,
            allowedSymbols: $allowedSymbols,
            allowedMarkets: $allowedMarkets,
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

    private static function durationSeconds(mixed $value, string $reasonCode = 'canonical_time_stop_invalid'): int
    {
        if (!\is_string($value) || preg_match('/\APT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?\z/D', $value, $matches) !== 1) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
        $hours = BigInteger::of(isset($matches[1]) && $matches[1] !== '' ? $matches[1] : '0');
        $minutes = BigInteger::of(isset($matches[2]) && $matches[2] !== '' ? $matches[2] : '0');
        $seconds = BigInteger::of(isset($matches[3]) && $matches[3] !== '' ? $matches[3] : '0');
        $duration = $hours->multipliedBy(3600)->plus($minutes->multipliedBy(60))->plus($seconds);
        if ($duration->isZero() || $duration->isGreaterThan(PHP_INT_MAX)) {
            throw new CanonicalOrderPlanException($reasonCode);
        }

        return $duration->toInt();
    }

    private static function costContract(mixed $value, bool $withRoles): CanonicalCostContract
    {
        $contract = self::objectValue($value, 'canonical_cost_contract_shape_invalid');
        $keys = [
            'entry_spread_source',
            'entry_slippage_source',
            'stop_spread_source',
            'stop_slippage_source',
            'target_spread_source',
            'target_slippage_source',
            'funding_source',
            'funding_interval_seconds',
        ];
        if ($withRoles) {
            $keys = ['entry_liquidity_role', 'stop_liquidity_role', ...$keys];
        }
        self::requireExactKeys($contract, $keys, 'canonical_cost_contract_shape_invalid');

        $entryRole = $withRoles ? $contract['entry_liquidity_role'] ?? null : null;
        $stopRole = $withRoles ? $contract['stop_liquidity_role'] ?? null : null;
        if ($withRoles && ($entryRole !== 'maker' || $stopRole !== 'taker')) {
            throw new CanonicalOrderPlanException('canonical_cost_contract_invalid');
        }

        return new CanonicalCostContract(
            entrySpreadSource: self::identifier($contract['entry_spread_source'], 'canonical_cost_contract_invalid'),
            entrySlippageSource: self::identifier($contract['entry_slippage_source'], 'canonical_cost_contract_invalid'),
            stopSpreadSource: self::identifier($contract['stop_spread_source'], 'canonical_cost_contract_invalid'),
            stopSlippageSource: self::identifier($contract['stop_slippage_source'], 'canonical_cost_contract_invalid'),
            targetSpreadSource: self::identifier($contract['target_spread_source'], 'canonical_cost_contract_invalid'),
            targetSlippageSource: self::identifier($contract['target_slippage_source'], 'canonical_cost_contract_invalid'),
            fundingSource: self::identifier($contract['funding_source'], 'canonical_cost_contract_invalid'),
            fundingIntervalSeconds: self::positiveInteger($contract['funding_interval_seconds'], 'canonical_cost_contract_invalid'),
            entryLiquidityRole: $entryRole,
            stopLiquidityRole: $stopRole,
        );
    }

    /** @param array<string, mixed> $exchange */
    private static function withVenueFundingSchedule(CanonicalCostContract $contract, array $exchange): CanonicalCostContract
    {
        if ($contract->fundingSource !== 'venue_schedule') {
            throw new CanonicalOrderPlanException('canonical_cost_contract_venue_schedule_invalid');
        }
        $funding = self::mapping($exchange, 'funding', 'canonical_cost_contract_venue_schedule_invalid');
        self::requireExactKeys($funding, ['enabled', 'interval'], 'canonical_cost_contract_venue_schedule_invalid');
        if (($funding['enabled'] ?? null) !== true) {
            throw new CanonicalOrderPlanException('canonical_cost_contract_venue_schedule_invalid');
        }

        return new CanonicalCostContract(
            entrySpreadSource: $contract->entrySpreadSource,
            entrySlippageSource: $contract->entrySlippageSource,
            stopSpreadSource: $contract->stopSpreadSource,
            stopSlippageSource: $contract->stopSlippageSource,
            targetSpreadSource: $contract->targetSpreadSource,
            targetSlippageSource: $contract->targetSlippageSource,
            fundingSource: $contract->fundingSource,
            fundingIntervalSeconds: self::durationSeconds(
                $funding['interval'] ?? null,
                'canonical_cost_contract_venue_schedule_invalid',
            ),
            entryLiquidityRole: $contract->entryLiquidityRole,
            stopLiquidityRole: $contract->stopLiquidityRole,
        );
    }

    private static function orderPolicy(mixed $value): CanonicalOrderPolicy
    {
        $policy = self::objectValue($value, 'canonical_day_trading_order_policy_invalid');
        self::requireExactKeys($policy, ['type', 'liquidity_role', 'ttl_seconds', 'cancel_after_seconds', 'market_fallback', 'maximum_spread_bps', 'maximum_slippage_bps'], 'canonical_day_trading_order_policy_invalid');

        return new CanonicalOrderPolicy(
            self::identifier($policy['type'], 'canonical_day_trading_order_policy_invalid'),
            self::identifier($policy['liquidity_role'], 'canonical_day_trading_order_policy_invalid'),
            self::positiveInteger($policy['ttl_seconds'], 'canonical_day_trading_order_policy_invalid'),
            self::positiveInteger($policy['cancel_after_seconds'], 'canonical_day_trading_order_policy_invalid'),
            $policy['market_fallback'] ?? true,
            self::positiveNumber($policy['maximum_spread_bps'], 'canonical_day_trading_order_policy_invalid'),
            self::positiveNumber($policy['maximum_slippage_bps'], 'canonical_day_trading_order_policy_invalid'),
        );
    }

    /** @param array<string, mixed> $holdingHorizon */
    private static function assertShadowIdentityPolicy(
        EffectiveTradingConfigSnapshot $snapshot,
        ?CanonicalOrderPolicy $orderPolicy,
        int $holdingWindowSeconds,
        array $holdingHorizon,
    ): void {
        $request = $snapshot->request;
        $identity = implode('@', [
            $request->modeId,
            $request->modeVersion,
            $request->setupId,
            $request->setupVersion,
        ]);
        $scalpingIdentity = \in_array($identity, [
            'scalping@1.1.0@scalping.trend_continuation.long@1.1.0',
            'scalping@1.1.0@scalping.pullback.long@1.1.0',
            'scalping@1.1.0@scalping.trend_momentum.short@1.1.0',
        ], true);
        $microScalpingIdentity = \in_array($identity, [
            'micro_scalping@1.1.0@micro_scalping.momentum_ofi.long@1.1.0',
            'micro_scalping@1.1.0@micro_scalping.momentum_ofi.short@1.1.0',
        ], true);
        $expected = match (true) {
            $identity === 'day_trading@1.1.0@day_trading.trend_continuation.long@1.1.0' => [90, 120, 28_800, 'PT8H', 6.0],
            $scalpingIdentity => [45, 75, 7200, 'PT2H', 6.0],
            $microScalpingIdentity => [30, 60, 1800, 'PT30M', 8.0],
            default => null,
        };
        if ($orderPolicy === null || $expected === null) {
            throw new CanonicalOrderPlanException('canonical_shadow_identity_policy_mismatch');
        }
        [$ttlSeconds, $cancelAfterSeconds, $windowSeconds, $maximumDuration, $maximumSpreadBps] = $expected;
        if ($orderPolicy->ttlSeconds !== $ttlSeconds
            || $orderPolicy->cancelAfterSeconds !== $cancelAfterSeconds
            || $orderPolicy->maximumSpreadBps !== $maximumSpreadBps
            || $holdingWindowSeconds !== $windowSeconds
            || ($holdingHorizon['maximum_duration'] ?? null) !== $maximumDuration
            || ($holdingHorizon['daily_boundary_time'] ?? null) !== '00:00:00'
            || ($holdingHorizon['daily_boundary_timezone'] ?? null) !== 'UTC'
            || ($holdingHorizon['close_before_boundary'] ?? null) !== true
        ) {
            throw new CanonicalOrderPlanException('canonical_shadow_identity_policy_mismatch');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateCanonicalSchema(array $payload, EffectiveTradingConfigSnapshot $snapshot): void
    {
        self::requireExactKeys(
            $payload,
            ['schema_version', 'units', 'safety', 'mode', 'setup', 'exchange', 'environment'],
            'canonical_execution_policy_root_schema_invalid',
        );
        if (($payload['schema_version'] ?? null) !== 'effective-trading-config.v2') {
            throw new CanonicalOrderPlanException('canonical_execution_policy_root_schema_invalid');
        }
        $units = self::mapping($payload, 'units', 'canonical_execution_policy_root_schema_invalid');
        self::requireExactKeys($units, ['percent', 'duration', 'price', 'notional'], 'canonical_execution_policy_root_schema_invalid');
        if (
            ($units['percent'] ?? null) !== 'percentage_points'
            || ($units['duration'] ?? null) !== 'iso8601'
            || ($units['price'] ?? null) !== 'quote_price'
            || ($units['notional'] ?? null) !== 'quote_notional'
        ) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_root_schema_invalid');
        }
        $environment = self::mapping($payload, 'environment', 'canonical_execution_policy_environment_invalid');
        self::requireExactKeys($environment, [
            'id',
            'allowed_symbols',
            'allowed_markets',
            'max_notional',
            'dry_run',
            'write_enabled',
            'kill_switch_enabled',
            'require_stop_loss',
        ], 'canonical_execution_policy_environment_invalid');
        if (
            ($environment['id'] ?? null) !== $snapshot->request->environment
            || !\is_bool($environment['dry_run'] ?? null)
            || ($environment['write_enabled'] ?? null) !== false
            || ($environment['kill_switch_enabled'] ?? null) !== true
            || ($environment['require_stop_loss'] ?? null) !== true
        ) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_environment_invalid');
        }

        $setup = self::mapping($payload, 'setup', 'canonical_execution_policy_setup_schema_invalid');
        self::requireExactKeys($setup, [
            'schema_version',
            'setup_id',
            'setup_version',
            'status',
            'executable',
            'publishable',
            'family',
            'side',
            'thesis',
            'hypothesis',
            'mode_versions',
            'mode_compatibility',
            'ast',
            'missing_data_policy',
            'data_condition_contract',
            'validity_window',
            'governance',
            'known_defects',
            'ownership_model',
            'source_origins',
            'contract_provenance',
            'contract_hash',
            'condition_catalog_hash',
            'blockers',
            'payload_hash',
        ], 'canonical_execution_policy_setup_schema_invalid');
        $catalogHash = $snapshot->conditionCatalogHash;
        if (
            ($setup['schema_version'] ?? null) !== 'compiled-setup.v1'
            || ($setup['executable'] ?? null) !== true
            || ($setup['publishable'] ?? null) !== true
            || ($setup['blockers'] ?? null) !== []
            || !\is_string($catalogHash)
            || ($setup['condition_catalog_hash'] ?? null) !== substr($catalogHash, 7)
            || !\is_string($setup['contract_hash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $setup['contract_hash']) !== 1
            || !\is_string($setup['payload_hash'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $setup['payload_hash']) !== 1
        ) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_setup_schema_invalid');
        }
        $dataContract = self::mapping($setup, 'data_condition_contract', 'canonical_execution_policy_catalog_invalid');
        $dataContractKeys = [
            'required_data',
            'missing_conditions',
            'external_dependencies',
            'condition_catalog_hash',
            'unknown_condition_policy',
        ];
        $scalpingShadow = $snapshot->request->modeId === 'scalping'
            && $snapshot->request->modeVersion === '1.1.0'
            && $snapshot->request->setupVersion === '1.1.0'
            && \in_array($snapshot->request->setupId, [
                'scalping.trend_continuation.long',
                'scalping.pullback.long',
                'scalping.trend_momentum.short',
            ], true);
        $microScalpingShadow = $snapshot->request->modeId === 'micro_scalping'
            && $snapshot->request->modeVersion === '1.1.0'
            && $snapshot->request->setupVersion === '1.1.0'
            && \in_array($snapshot->request->setupId, [
                'micro_scalping.momentum_ofi.long',
                'micro_scalping.momentum_ofi.short',
            ], true);
        if ($scalpingShadow || $microScalpingShadow) {
            array_unshift($dataContractKeys, 'condition_catalog_version');
        }
        self::requireExactKeys($dataContract, $dataContractKeys, 'canonical_execution_policy_catalog_invalid');
        $expectedCatalogVersion = $microScalpingShadow ? '1.2.0' : ($scalpingShadow ? '1.1.0' : null);
        if ($expectedCatalogVersion !== null && ($dataContract['condition_catalog_version'] ?? null) !== $expectedCatalogVersion) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_catalog_invalid');
        }
        $catalogDecision = self::mapping($dataContract, 'condition_catalog_hash', 'canonical_execution_policy_catalog_invalid');
        self::requireExactKeys(
            $catalogDecision,
            ['state', 'value', 'unit', 'source', 'justification'],
            'canonical_execution_policy_catalog_invalid',
        );
        if (
            ($catalogDecision['state'] ?? null) !== 'defined'
            || ($catalogDecision['unit'] ?? null) !== 'sha256'
            || ($catalogDecision['value'] ?? null) !== $setup['condition_catalog_hash']
            || !\is_string($catalogDecision['source'] ?? null)
            || trim($catalogDecision['source']) === ''
            || !\is_string($catalogDecision['justification'] ?? null)
            || trim($catalogDecision['justification']) === ''
        ) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_catalog_invalid');
        }
        $modeVersions = self::mapping($setup, 'mode_versions', 'canonical_execution_policy_setup_schema_invalid');
        $provenance = self::mapping($setup, 'contract_provenance', 'canonical_execution_policy_setup_schema_invalid');
        if (
            ($modeVersions[$snapshot->request->modeId] ?? null) !== $snapshot->request->modeVersion
            || !\is_array($setup['source_origins'])
            || !array_is_list($setup['source_origins'])
            || $setup['source_origins'] === []
            || $provenance === []
        ) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_setup_schema_invalid');
        }
        $setupForHash = $setup;
        $claimedPayloadHash = $setupForHash['payload_hash'];
        unset($setupForHash['payload_hash']);
        if (!hash_equals($claimedPayloadHash, self::canonicalHash($setupForHash))) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_setup_integrity_invalid');
        }

        $ast = self::mapping($setup, 'ast', 'canonical_execution_policy_ast_schema_invalid');
        self::requireExactKeys(
            $ast,
            ['kind', 'side', 'regime', 'context', 'trigger', 'confirmations', 'filters', 'no_trade_rules', 'execution'],
            'canonical_execution_policy_ast_schema_invalid',
        );
        if (($ast['kind'] ?? null) !== 'setup' || ($ast['side'] ?? null) !== $snapshot->request->side) {
            throw new CanonicalOrderPlanException('canonical_execution_policy_ast_schema_invalid');
        }
    }

    /** @param array<string, mixed> $value */
    private static function canonicalHash(array $value): string
    {
        $canonicalize = static function (mixed $node) use (&$canonicalize): mixed {
            if (!\is_array($node)) {
                return $node;
            }
            if (!array_is_list($node)) {
                ksort($node, SORT_STRING);
            }
            foreach ($node as $key => $child) {
                $node[$key] = $canonicalize($child);
            }

            return $node;
        };

        return hash('sha256', json_encode($canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
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

    /** @return list<string> */
    private static function stringList(mixed $value, string $reasonCode): array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            throw new CanonicalOrderPlanException($reasonCode);
        }
        $result = [];
        foreach ($value as $item) {
            if (!\is_string($item) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D', $item) !== 1) {
                throw new CanonicalOrderPlanException($reasonCode);
            }
            $identifier = $item;
            if (\in_array($identifier, $result, true)) {
                throw new CanonicalOrderPlanException($reasonCode);
            }
            $result[] = $identifier;
        }

        return $result;
    }
}
