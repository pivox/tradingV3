<?php

declare(strict_types=1);

namespace App\MtfValidator\Policy;

use App\Indicator\Condition\ConditionInterface;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Rules\Catalog\ConditionCatalog;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Compiler\StrictSetupRuleCompiler;
use App\TradingCore\Rules\Evaluation\RuleEvaluationContext;
use App\TradingCore\Rules\Evaluation\RuleEvaluationResult;
use App\TradingCore\Rules\Evaluation\RuleInputSnapshot;
use App\TradingCore\Rules\Evaluation\StrictConditionRegistry;
use App\TradingCore\Rules\Evaluation\StrictRuleEvaluator;
use App\TradingCore\Setup\SetupContractLoader;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class CanonicalSetupRuleRuntime
{
    private ConditionCatalog $catalog;
    private SetupContractLoader $contracts;
    private StrictSetupRuleCompiler $compiler;
    private StrictRuleEvaluator $evaluator;
    /** @var array<string, \App\TradingCore\Rules\Compiler\CompiledSetupRulePlan> */
    private array $planCache = [];

    /** @param iterable<ConditionInterface> $conditions */
    public function __construct(
        #[AutowireIterator('app.indicator.condition', indexAttribute: 'key')]
        iterable $conditions,
        ?ConditionCatalog $catalog = null,
        ?SetupContractLoader $contracts = null,
    ) {
        $this->catalog = $catalog ?? (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        );
        $this->contracts = $contracts ?? new SetupContractLoader();
        $this->compiler = new StrictSetupRuleCompiler($this->catalog);
        $this->evaluator = new StrictRuleEvaluator($this->catalog, new StrictConditionRegistry($conditions));
    }

    /** @param array<string, array<string, mixed>> $indicatorsByTimeframe */
    public function evaluate(
        LineageContext $identity,
        array $indicatorsByTimeframe,
        \DateTimeImmutable $evaluatedAt,
    ): CanonicalSetupRuleRuntimeResult {
        if (!$identity->isModern()) {
            return new CanonicalSetupRuleRuntimeResult(false, 'canonical_identity_required', []);
        }
        $identityCatalogHash = (string) $identity->conditionCatalogHash;
        $identityCatalogHash = str_starts_with($identityCatalogHash, 'sha256:') ? substr($identityCatalogHash, 7) : $identityCatalogHash;
        if (!hash_equals($this->catalog->stableHash(), $identityCatalogHash)) {
            return new CanonicalSetupRuleRuntimeResult(false, 'canonical_condition_catalog_mismatch', []);
        }
        $contract = $this->contracts->load((string) $identity->setupId, (string) $identity->setupVersion);
        $setupHash = $contract->stableHash();
        $planCacheKey = hash('sha256', json_encode([
            'catalog_hash' => $this->catalog->stableHash(),
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'setup_hash' => $setupHash,
            'config_hash' => $identity->configHash,
        ], JSON_THROW_ON_ERROR));
        $planCacheHit = isset($this->planCache[$planCacheKey]);
        $plan = $this->planCache[$planCacheKey] ??= $this->compiler->compile($contract);
        $context = new RuleEvaluationContext(
            (string) $identity->configHash,
            $evaluatedAt,
            $this->snapshots($identity, $indicatorsByTimeframe, $evaluatedAt),
        );
        $sectionResults = [];
        foreach ($plan->sections as $name => $node) {
            $sectionResults[$name] = $this->evaluator->evaluate($node, $context);
        }
        $filterResults = array_map(fn ($node): RuleEvaluationResult => $this->evaluator->evaluate($node, $context), $plan->filters);
        $noTradeResults = array_map(fn ($node): RuleEvaluationResult => $this->evaluator->evaluate($node, $context), $plan->noTradeRules);
        $sectionsPassed = !in_array(false, array_map(static fn (RuleEvaluationResult $result): bool => $result->passed, $sectionResults), true);
        $filtersPassed = !in_array(false, array_map(static fn (RuleEvaluationResult $result): bool => $result->passed, $filterResults), true);
        $noTradeMatched = in_array(true, array_map(static fn (RuleEvaluationResult $result): bool => $result->passed, $noTradeResults), true);
        $passed = $plan->blockers === [] && $sectionsPassed && $filtersPassed && !$noTradeMatched;
        $reason = match (true) {
            $plan->blockers !== [] => 'compiled_plan_blocked',
            !$sectionsPassed => 'setup_section_failed',
            !$filtersPassed => 'setup_filter_failed',
            $noTradeMatched => 'no_trade_rule_matched',
            default => 'setup_rules_passed',
        };

        return new CanonicalSetupRuleRuntimeResult($passed, $reason, [
            'schema_version' => 'canonical-setup-rule-runtime.v1',
            'setup_id' => $plan->setupId,
            'setup_version' => $plan->setupVersion,
            'setup_hash' => $plan->setupHash,
            'side' => $plan->side,
            'catalog_version' => $plan->catalogVersion,
            'catalog_hash' => $plan->catalogHash,
            'config_hash' => $identity->configHash,
            'plan_cache_key' => $planCacheKey,
            'plan_cache_hit' => $planCacheHit,
            'blockers' => $plan->blockers,
            'sections' => array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $sectionResults),
            'filters' => array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $filterResults),
            'no_trade_rules' => array_map(static fn (RuleEvaluationResult $result): array => $result->trace, $noTradeResults),
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $indicatorsByTimeframe
     * @return list<RuleInputSnapshot>
     */
    private function snapshots(LineageContext $identity, array $indicatorsByTimeframe, \DateTimeImmutable $evaluatedAt): array
    {
        $snapshots = [];
        foreach ($indicatorsByTimeframe as $timeframe => $indicators) {
            $observedAt = $this->instant($indicators['kline_time'] ?? null);
            if ($observedAt === null) {
                continue;
            }
            $snapshots[] = new RuleInputSnapshot(
                $timeframe,
                'indicator_snapshot',
                $observedAt,
                $observedAt->modify('+' . $this->catalog->freshnessSeconds('indicator_snapshot', $timeframe) . ' seconds'),
                $indicators,
            );
        }
        $config = $identity->effectiveConfigSnapshot?->config();
        if (is_array($config)) {
            $snapshots[] = new RuleInputSnapshot(
                'global',
                'effective_config',
                $evaluatedAt,
                $evaluatedAt->modify('+' . $this->catalog->freshnessSeconds('effective_config', 'global') . ' seconds'),
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

}
