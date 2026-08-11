<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

use App\Common\Enum\Timeframe;
use App\Indicator\Condition\ConditionResult;
use App\TradingCore\Rules\Ast\AllOfNode;
use App\TradingCore\Rules\Ast\AnyOfNode;
use App\TradingCore\Rules\Ast\ConditionNode;
use App\TradingCore\Rules\Ast\RuleNode;
use App\TradingCore\Rules\Catalog\ConditionCatalog;

final readonly class StrictRuleEvaluator
{
    private const TRACE_SCHEMA = 'strict-rule-trace.v1';

    public function __construct(
        private ConditionCatalog $catalog,
        private StrictConditionRegistry $registry,
        private ?StrictCompiledExpressionEvaluator $compiledExpressions = null,
    ) {
    }

    public function evaluate(RuleNode $node, RuleEvaluationContext $context): RuleEvaluationResult
    {
        [$passed, $reasonCode, $trace] = $this->evaluateNode($node, $context);
        $trace = [
            'schema_version' => self::TRACE_SCHEMA,
            'catalog_version' => $this->catalog->catalogVersion,
            'catalog_hash' => $this->catalog->stableHash(),
            'config_hash' => $context->configHash,
            'evaluated_at' => $context->evaluatedAt->format(DATE_ATOM),
        ] + $trace;

        return new RuleEvaluationResult($passed, $reasonCode, self::TRACE_SCHEMA, $trace);
    }

    /** @return array{bool, string, array<string, mixed>} */
    private function evaluateNode(RuleNode $node, RuleEvaluationContext $context): array
    {
        if ($node instanceof ConditionNode) {
            return $this->evaluateCondition($node, $context);
        }
        if ($node instanceof AllOfNode || $node instanceof AnyOfNode) {
            if ($node->children === []) {
                return [false, 'empty_group', ['kind' => $node instanceof AllOfNode ? 'all_of' : 'any_of', 'children' => []]];
            }
            $children = [];
            $passes = [];
            foreach ($node->children as $child) {
                [$passed, $reason, $trace] = $this->evaluateNode($child, $context);
                $passes[] = $passed;
                $children[] = $trace + ['reason_code' => $reason, 'passed' => $passed];
            }
            $all = $node instanceof AllOfNode;
            $passed = $all ? !in_array(false, $passes, true) : in_array(true, $passes, true);
            $reason = $all
                ? ($passed ? 'all_of_passed' : 'all_of_failed')
                : ($passed ? 'any_of_passed' : 'any_of_failed');

            return [$passed, $reason, [
                'kind' => $all ? 'all_of' : 'any_of',
                'provenance' => $node->provenance,
                'children' => $children,
            ]];
        }

        return [false, 'unknown_ast_node', ['kind' => get_debug_type($node)]];
    }

    /** @return array{bool, string, array<string, mixed>} */
    private function evaluateCondition(ConditionNode $node, RuleEvaluationContext $context): array
    {
        $definition = $this->catalog->definition($node->conditionId);
        $base = [
            'kind' => 'condition',
            'condition_id' => $node->conditionId,
            'timeframe' => $node->timeframe,
            'side' => $node->side,
            'parameters' => $node->parameters,
            'provenance' => $node->provenance,
            'implementation' => $definition->implementation,
        ];
        if ($definition->status === 'blocked') {
            return [false, 'condition_blocked', $base];
        }
        if ($node->timeframe === 'global' && $definition->contextSource === 'indicator_snapshot') {
            $snapshots = $context->snapshotsForSource($definition->contextSource);
            if ($snapshots === []) {
                return [false, 'missing_timeframe_snapshot', $base + [
                    'aggregation' => 'all_available_timeframes',
                    'children' => [],
                ]];
            }
            $children = [];
            $passed = true;
            foreach ($snapshots as $snapshot) {
                [$childPassed, $childReason, $childTrace] = $this->evaluateConditionAgainstSnapshot($node, $context, $snapshot);
                $passed = $passed && $childPassed;
                $children[] = $childTrace + ['reason_code' => $childReason, 'passed' => $childPassed];
            }

            return [$passed, $passed ? 'condition_passed' : 'condition_failed', $base + [
                'aggregation' => 'all_available_timeframes',
                'children' => $children,
            ]];
        }
        $snapshot = $context->snapshot($node->timeframe, $definition->contextSource);
        if ($snapshot === null) {
            return [false, 'missing_timeframe_snapshot', $base];
        }

        return $this->evaluateConditionAgainstSnapshot($node, $context, $snapshot);
    }

    /** @return array{bool, string, array<string, mixed>} */
    private function evaluateConditionAgainstSnapshot(
        ConditionNode $node,
        RuleEvaluationContext $context,
        RuleInputSnapshot $snapshot,
    ): array {
        $definition = $this->catalog->definition($node->conditionId);
        $base = [
            'kind' => 'condition',
            'condition_id' => $node->conditionId,
            'timeframe' => $snapshot->timeframe,
            'requested_timeframe' => $node->timeframe,
            'side' => $node->side,
            'parameters' => $node->parameters,
            'provenance' => $node->provenance,
            'implementation' => $definition->implementation,
        ];
        $base['input_source'] = $snapshot->source;
        $base['input_freshness_seconds'] = $this->catalog->freshnessSeconds($snapshot->source, $snapshot->timeframe);
        $base['input_observed_at'] = $snapshot->observedAt->format(DATE_ATOM);
        $base['input_valid_until'] = $snapshot->validUntil->format(DATE_ATOM);
        $base['parameter_source'] = $node->parameterSources;
        $base['series_order'] = $definition->seriesOrder;
        $base['reported_series_order'] = $snapshot->values['series_order'] ?? null;
        $seriesTimestampKey = $definition->metric . '_timestamps';
        $base['reported_series_timestamps'] = $snapshot->values[$seriesTimestampKey] ?? null;
        if (!$snapshot->isValidAt($context->evaluatedAt)) {
            return [false, 'stale_input', $base];
        }
        if (str_starts_with($definition->valueType, 'series<')
            && ($snapshot->values['series_order'] ?? null) !== 'oldest_to_newest'
        ) {
            return [false, 'invalid_series_order', $base];
        }
        if (str_starts_with($definition->valueType, 'series<')
            && array_key_exists($definition->metric, $snapshot->values)
            && !$this->hasCanonicalSeriesChronology(
                $snapshot->values[$definition->metric] ?? null,
                $snapshot->values[$seriesTimestampKey] ?? null,
                $snapshot->timeframe,
            )
        ) {
            return [false, 'invalid_series_chronology', $base];
        }
        $condition = $this->registry->get($node->conditionId);
        $isCompiledExpression = str_starts_with($definition->implementation, 'compiled_expression:');
        if ($condition === null && !$isCompiledExpression) {
            return [false, 'condition_implementation_missing', $base];
        }
        $conditionContext = array_replace($snapshot->values, $node->parameters, [
            'timeframe' => $snapshot->timeframe,
            'side' => $node->side,
            'series_order' => $definition->seriesOrder,
            '_input_source' => $snapshot->source,
            '_input_observed_at' => $snapshot->observedAt->format(DATE_ATOM),
        ]);
        try {
            $result = $isCompiledExpression
                ? ($this->compiledExpressions ?? new StrictCompiledExpressionEvaluator($this->registry, $this->catalog))->evaluate($node->conditionId, $conditionContext)
                : $condition->evaluate($conditionContext);
        } catch (\Throwable $exception) {
            return [false, 'condition_error', $base + ['exception' => $exception::class]];
        }
        $base += $this->resultTrace($result);
        if ($result->name !== $node->conditionId) {
            return [false, 'condition_identity_mismatch', $base];
        }
        if (($result->value !== null && !is_finite($result->value))
            || ($result->threshold !== null && !is_finite($result->threshold))) {
            return [false, 'non_finite_result', $base];
        }
        foreach (['missing_data', 'invalid_numeric', 'non_numeric', 'insufficient_points'] as $missingFlag) {
            if (($result->meta[$missingFlag] ?? false) === true) {
                return [false, 'missing_critical_data', $base];
            }
        }

        return [$result->passed, $result->passed ? 'condition_passed' : 'condition_failed', $base];
    }

    /** @return array<string, mixed> */
    private function resultTrace(ConditionResult $result): array
    {
        return [
            'reported_condition_id' => $result->name,
            'observed_value' => $result->value,
            'threshold' => $result->threshold,
            'meta' => $result->meta,
        ];
    }

    private function hasCanonicalSeriesChronology(mixed $series, mixed $timestamps, string $timeframe): bool
    {
        if (!is_array($series)
            || !array_is_list($series)
            || count($series) < 2
            || !is_array($timestamps)
            || !array_is_list($timestamps)
            || count($timestamps) !== count($series)
        ) {
            return false;
        }
        $timeframeValue = Timeframe::tryFrom($timeframe);
        if ($timeframeValue === null) {
            return false;
        }
        $step = $timeframeValue->getStepInSeconds();
        for ($index = 0, $count = count($timestamps); $index < $count; ++$index) {
            if (!is_int($timestamps[$index])) {
                return false;
            }
            if ($index > 0 && $timestamps[$index] - $timestamps[$index - 1] !== $step) {
                return false;
            }
        }

        return true;
    }
}
