<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

use App\Indicator\Condition\ConditionInterface;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\MarketData\CanonicalIndicatorSnapshotIdentity;
use App\TradingCore\Microstructure\CanonicalMicrostructureRuntimeInputResolver;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogException;
use App\TradingCore\Rules\Catalog\ConditionCatalogResolver;
use App\TradingCore\Rules\Compiler\StrictSetupRuleCompiler;
use App\TradingCore\Rules\Evaluation\RuleEvaluationContext;
use App\TradingCore\Rules\Evaluation\RuleEvaluationResult;
use App\TradingCore\Rules\Evaluation\RuleInputSnapshot;
use App\TradingCore\Rules\Evaluation\StrictConditionRegistry;
use App\TradingCore\Rules\Evaluation\StrictRuleEvaluator;
use App\TradingCore\Setup\SetupContract;
use App\TradingCore\Setup\SetupContractLoader;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class CanonicalSetupRuleRuntime
{
    private ?ConditionCatalog $suppliedCatalog;
    private SetupContractLoader $contracts;
    private ModeContractLoader $modes;
    private StrictConditionRegistry $registry;
    /** @var array<string, \App\TradingCore\Rules\Compiler\CompiledSetupRulePlan> */
    private array $planCache = [];

    /** @param iterable<ConditionInterface> $conditions */
    public function __construct(
        #[AutowireIterator('app.indicator.condition', indexAttribute: 'key')]
        iterable $conditions,
        ?ConditionCatalog $catalog = null,
        ?SetupContractLoader $contracts = null,
        ?ModeContractLoader $modes = null,
        private readonly ?CanonicalMicrostructureRuntimeInputResolver $microstructureInputs = null,
    ) {
        $this->suppliedCatalog = $catalog;
        $this->contracts = $contracts ?? new SetupContractLoader();
        $this->modes = $modes ?? new ModeContractLoader();
        $this->registry = new StrictConditionRegistry($conditions);
    }

    /** @param array<string, mixed> $indicatorsByTimeframe */
    public function evaluate(
        LineageContext $identity,
        array $indicatorsByTimeframe,
        \DateTimeImmutable $evaluatedAt,
    ): CanonicalSetupRuleRuntimeResult {
        if (!$identity->isModern()) {
            return new CanonicalSetupRuleRuntimeResult(false, 'canonical_identity_required', []);
        }
        $contract = $this->contracts->load((string) $identity->setupId, (string) $identity->setupVersion);
        try {
            $catalog = (new ConditionCatalogResolver())->forSetupDocument(
                $contract->toArray(),
                $this->suppliedCatalog,
            );
        } catch (ConditionCatalogException) {
            return new CanonicalSetupRuleRuntimeResult(false, 'canonical_condition_catalog_mismatch', []);
        }
        $identityCatalogHash = (string) $identity->conditionCatalogHash;
        $identityCatalogHash = str_starts_with($identityCatalogHash, 'sha256:') ? substr($identityCatalogHash, 7) : $identityCatalogHash;
        if (!hash_equals($catalog->stableHash(), $identityCatalogHash)) {
            return new CanonicalSetupRuleRuntimeResult(false, 'canonical_condition_catalog_mismatch', []);
        }
        $shadowTimeframes = $this->shadowTimeframes($identity, $contract);
        if ($shadowTimeframes !== null) {
            foreach ($shadowTimeframes['required'] as $timeframe) {
                if (!array_key_exists($timeframe, $indicatorsByTimeframe)
                    || !\is_array($indicatorsByTimeframe[$timeframe])
                ) {
                    return new CanonicalSetupRuleRuntimeResult(false, 'critical_timeframe_missing', [
                        ...$this->traceIdentity($identity, $evaluatedAt, $shadowTimeframes),
                        'rejection' => [
                            'timeframe' => $timeframe,
                            'cause' => 'timeframe_mapping_missing',
                        ],
                    ]);
                }
                $observedAt = $this->instant($indicatorsByTimeframe[$timeframe]['kline_time'] ?? null);
                if ($observedAt === null) {
                    return new CanonicalSetupRuleRuntimeResult(false, 'critical_timeframe_missing', [
                        ...$this->traceIdentity($identity, $evaluatedAt, $shadowTimeframes),
                        'rejection' => [
                            'timeframe' => $timeframe,
                            'cause' => 'kline_time_missing_or_invalid',
                        ],
                    ]);
                }
                $snapshotIdentityData = $indicatorsByTimeframe[$timeframe]['snapshot_identity'] ?? null;
                $snapshotIdentity = \is_array($snapshotIdentityData)
                    ? CanonicalIndicatorSnapshotIdentity::tryFromArray($snapshotIdentityData)
                    : null;
                $expectedMarket = $this->indicatorSnapshotMarket($identity);
                $expectedSnapshotIdentity = new CanonicalIndicatorSnapshotIdentity(
                    $timeframe,
                    (string) $identity->symbol,
                    $expectedMarket['exchange'],
                    $expectedMarket['environment'],
                    (string) $identity->marketType,
                );
                if ($snapshotIdentity === null || !$snapshotIdentity->matches(
                    $expectedSnapshotIdentity->timeframe,
                    $expectedSnapshotIdentity->symbol,
                    $expectedSnapshotIdentity->exchange,
                    $expectedSnapshotIdentity->environment,
                    $expectedSnapshotIdentity->marketType,
                )) {
                    return new CanonicalSetupRuleRuntimeResult(false, 'indicator_snapshot_identity_mismatch', [
                        ...$this->traceIdentity($identity, $evaluatedAt, $shadowTimeframes),
                        'rejection' => [
                            'timeframe' => $timeframe,
                            'cause' => $snapshotIdentity === null ? 'identity_missing_or_invalid' : 'identity_mismatch',
                            'expected_identity' => $expectedSnapshotIdentity->toArray(),
                            'observed_identity' => $snapshotIdentity?->toArray(),
                        ],
                    ]);
                }
                $validUntil = $observedAt->modify(
                    '+' . $catalog->freshnessSeconds('indicator_snapshot', $timeframe) . ' seconds',
                );
                if ($observedAt > $evaluatedAt || $validUntil < $evaluatedAt) {
                    return new CanonicalSetupRuleRuntimeResult(false, 'critical_timeframe_stale', [
                        ...$this->traceIdentity($identity, $evaluatedAt, $shadowTimeframes),
                        'rejection' => [
                            'timeframe' => $timeframe,
                            'cause' => 'outside_freshness_window',
                        ],
                    ]);
                }
            }
        }
        $setupHash = $contract->stableHash();
        $planCacheKey = hash('sha256', json_encode([
            'catalog_hash' => $catalog->stableHash(),
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'setup_hash' => $setupHash,
            'config_hash' => $identity->configHash,
        ], JSON_THROW_ON_ERROR));
        $planCacheHit = isset($this->planCache[$planCacheKey]);
        $plan = $this->planCache[$planCacheKey] ??= (new StrictSetupRuleCompiler($catalog))->compile($contract);
        $evaluator = new StrictRuleEvaluator($catalog, $this->registry);
        $microstructureInput = ($this->microstructureInputs ?? new CanonicalMicrostructureRuntimeInputResolver())
            ->resolve($identity, $evaluatedAt);
        $snapshots = $this->snapshots($identity, $indicatorsByTimeframe, $evaluatedAt, $catalog);
        if ($microstructureInput->ruleInput !== null) {
            $snapshots[] = $microstructureInput->ruleInput;
        }
        $context = new RuleEvaluationContext(
            (string) $identity->configHash,
            $evaluatedAt,
            $snapshots,
            $microstructureInput->marketIdentity,
        );
        $sectionResults = [];
        foreach ($plan->sections as $name => $node) {
            $sectionResults[$name] = $evaluator->evaluate($node, $context);
        }
        $filterResults = array_map(fn ($node): RuleEvaluationResult => $evaluator->evaluate($node, $context), $plan->filters);
        $noTradeResults = array_map(fn ($node): RuleEvaluationResult => $evaluator->evaluate($node, $context), $plan->noTradeRules);
        $sectionsPassed = !in_array(false, array_map(static fn (RuleEvaluationResult $result): bool => $result->passed, $sectionResults), true);
        $filtersPassed = !in_array(false, array_map(static fn (RuleEvaluationResult $result): bool => $result->passed, $filterResults), true);
        $noTradeMatched = in_array(true, array_map(static fn (RuleEvaluationResult $result): bool => $result->passed, $noTradeResults), true);
        $passed = $plan->blockers === [] && $sectionsPassed && $filtersPassed && !$noTradeMatched;
        $staleInput = $shadowTimeframes !== null && $this->containsReasonCode([
            ...array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $sectionResults),
            ...array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $filterResults),
            ...array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $noTradeResults),
        ], 'stale_input');
        $reason = match (true) {
            $plan->blockers !== [] => 'compiled_plan_blocked',
            $staleInput => 'critical_timeframe_stale',
            !$sectionsPassed => 'setup_section_failed',
            !$filtersPassed => 'setup_filter_failed',
            $noTradeMatched => 'no_trade_rule_matched',
            default => 'setup_rules_passed',
        };

        $microstructureTrace = $identity->modeId === 'micro_scalping'
            ? ['microstructure_input' => $microstructureInput->trace]
            : [];

        return new CanonicalSetupRuleRuntimeResult($passed, $reason, [
            'schema_version' => 'canonical-setup-rule-runtime.v1',
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $plan->setupId,
            'setup_version' => $plan->setupVersion,
            'setup_hash' => $plan->setupHash,
            'side' => $plan->side,
            'catalog_version' => $plan->catalogVersion,
            'catalog_hash' => $plan->catalogHash,
            'config_hash' => $identity->configHash,
            'evaluated_at' => $evaluatedAt->format(DATE_ATOM),
            'execution_timeframe' => $shadowTimeframes['execution'] ?? null,
            'mandatory_confirmations' => $shadowTimeframes['confirmations'] ?? [],
            'plan_cache_key' => $planCacheKey,
            'plan_cache_hit' => $planCacheHit,
            'blockers' => $plan->blockers,
            ...$microstructureTrace,
            'sections' => array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $sectionResults),
            'filters' => array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $filterResults),
            'no_trade_rules' => array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $noTradeResults),
        ]);
    }

    /** @return array{required:list<string>,execution:string,confirmations:list<string>}|null */
    private function shadowTimeframes(LineageContext $identity, SetupContract $setup): ?array
    {
        if (!$setup->isExecutable() || $setup->status !== 'shadow') {
            return null;
        }
        $mode = $this->modes->load((string) $identity->modeId, (string) $identity->modeVersion);
        if (!$mode->isExecutable()
            || $mode->lifecycleStatus !== 'shadow'
            || !\in_array($setup->setupId, $mode->compatibleSetupIds(), true)
        ) {
            return null;
        }

        $document = $setup->toArray();
        $execution = $document['execution'] ?? null;
        $executionTimeframe = $execution['execution_timeframe'] ?? null;
        $confirmations = $execution['mandatory_confirmations'] ?? null;
        if (!\is_array($execution)
            || !\is_array($executionTimeframe)
            || ($executionTimeframe['state'] ?? null) !== 'defined'
            || !\is_string($executionTimeframe['value'] ?? null)
            || !\is_array($confirmations)
            || ($confirmations['state'] ?? null) !== 'defined'
            || !\is_array($confirmations['value'] ?? null)
            || !array_is_list($confirmations['value'])
        ) {
            return null;
        }

        $required = [];
        foreach ($mode->timeframeRoles() as $timeframes) {
            foreach ($timeframes as $timeframe) {
                if (!\in_array($timeframe, $required, true)) {
                    $required[] = $timeframe;
                }
            }
        }

        return [
            'required' => $required,
            'execution' => $executionTimeframe['value'],
            'confirmations' => $confirmations['value'],
        ];
    }

    /**
     * @param array{required:list<string>,execution:string,confirmations:list<string>} $timeframes
     * @return array<string, mixed>
     */
    private function traceIdentity(LineageContext $identity, \DateTimeImmutable $evaluatedAt, array $timeframes): array
    {
        return [
            'schema_version' => 'canonical-setup-rule-runtime.v1',
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'side' => strtolower((string) $identity->side),
            'config_hash' => $identity->configHash,
            'catalog_hash' => $identity->conditionCatalogHash,
            'evaluated_at' => $evaluatedAt->format(DATE_ATOM),
            'execution_timeframe' => $timeframes['execution'],
            'mandatory_confirmations' => $timeframes['confirmations'],
        ];
    }

    private function containsReasonCode(mixed $value, string $reasonCode): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if (($value['reason_code'] ?? null) === $reasonCode) {
            return true;
        }
        foreach ($value as $child) {
            if ($this->containsReasonCode($child, $reasonCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $indicatorsByTimeframe
     * @return list<RuleInputSnapshot>
     */
    private function snapshots(
        LineageContext $identity,
        array $indicatorsByTimeframe,
        \DateTimeImmutable $evaluatedAt,
        ConditionCatalog $catalog,
    ): array
    {
        $snapshots = [];
        foreach ($indicatorsByTimeframe as $timeframe => $indicators) {
            if (!is_array($indicators)) {
                continue;
            }
            $observedAt = $this->instant($indicators['kline_time'] ?? null);
            if ($observedAt === null) {
                continue;
            }
            $snapshots[] = new RuleInputSnapshot(
                $timeframe,
                'indicator_snapshot',
                $observedAt,
                $observedAt->modify('+' . $catalog->freshnessSeconds('indicator_snapshot', $timeframe) . ' seconds'),
                $indicators,
            );
        }
        $config = $identity->effectiveConfigSnapshot?->config();
        if (is_array($config)) {
            $snapshots[] = new RuleInputSnapshot(
                'global',
                'effective_config',
                $evaluatedAt,
                $evaluatedAt->modify('+' . $catalog->freshnessSeconds('effective_config', 'global') . ' seconds'),
                $config,
            );
        }

        return $snapshots;
    }

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || is_float($value)) {
            $seconds = (float) $value > 10_000_000_000 ? (float) $value / 1000.0 : (float) $value;
            return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds), new \DateTimeZone('UTC')) ?: null;
        }
        if (is_string($value) && trim($value) !== '') {
            try {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /** @return array{exchange:string,environment:string} */
    private function indicatorSnapshotMarket(LineageContext $identity): array
    {
        $request = $identity->effectiveConfigSnapshot?->toArray()['request'] ?? null;
        $capability = is_array($request) ? ($request['execution_capability'] ?? null) : null;
        if ($capability === ShadowExecutionCapability::Paper->value) {
            return ['exchange' => 'fake', 'environment' => 'test'];
        }

        return [
            'exchange' => (string) $identity->exchange,
            'environment' => (string) $identity->environment,
        ];
    }

}
