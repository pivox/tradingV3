<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Evaluation;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\TradingCore\Rules\Ast\AllOfNode;
use App\TradingCore\Rules\Ast\AnyOfNode;
use App\TradingCore\Rules\Ast\ConditionNode;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Evaluation\RuleEvaluationContext;
use App\TradingCore\Rules\Evaluation\RuleInputSnapshot;
use App\TradingCore\Rules\Evaluation\StrictConditionRegistry;
use App\TradingCore\Rules\Evaluation\StrictRuleEvaluator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrictRuleEvaluator::class)]
final class StrictRuleEvaluatorTest extends TestCase
{
    public function testAllOfAndAnyOfUseOneOrderedTruthTableAndTrace(): void
    {
        $evaluator = $this->evaluator([
            $this->condition('macd_hist_gt_eps', true, 0.1, 0.0),
            $this->condition('rsi_bullish', false, 48.0, 49.0),
        ]);
        $pass = new ConditionNode('macd_hist_gt_eps', '5m', 'long', ['eps' => 0.000001], 'fixture:1');
        $fail = new ConditionNode('rsi_bullish', '5m', 'long', [], 'fixture:2');
        $context = $this->context(['macd_hist' => 0.1, 'rsi' => 48.0]);

        $all = $evaluator->evaluate(new AllOfNode([$pass, $fail], 'fixture:all'), $context);
        $any = $evaluator->evaluate(new AnyOfNode([$fail, $pass], 'fixture:any'), $context);

        self::assertFalse($all->passed);
        self::assertSame('all_of_failed', $all->reasonCode);
        self::assertTrue($any->passed);
        self::assertSame('any_of_passed', $any->reasonCode);
        self::assertSame(['rsi_bullish', 'macd_hist_gt_eps'], array_column($any->trace['children'], 'condition_id'));
        self::assertSame('strict-rule-trace.v1', $any->traceSchemaVersion);
    }

    public function testMissingStaleBlockedUnknownAndThrowingConditionsFailClosed(): void
    {
        $throwing = new class implements ConditionInterface {
            public function getName(): string { return 'rsi_bullish'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult { throw new \RuntimeException('boom'); }
        };
        $node = new ConditionNode('rsi_bullish', '5m', 'long', [], 'fixture:1');

        self::assertSame('missing_timeframe_snapshot', $this->evaluator([$throwing])->evaluate($node, $this->context([], false))->reasonCode);
        self::assertSame('stale_input', $this->evaluator([$throwing])->evaluate($node, $this->context([], true, true))->reasonCode);
        self::assertSame('condition_error', $this->evaluator([$throwing])->evaluate($node, $this->context(['rsi' => 50.0]))->reasonCode);
        self::assertSame('condition_implementation_missing', $this->evaluator([])->evaluate($node, $this->context(['rsi' => 50.0]))->reasonCode);

        $blocked = new ConditionNode('spread_bps_lte', '1m', 'long', ['max_spread_bps' => 8.0], 'fixture:2');
        self::assertSame('condition_blocked', $this->evaluator([])->evaluate($blocked, $this->context(['spread_bps' => 1.0], true, false, '1m'))->reasonCode);
    }

    public function testNonFiniteResultAndDeclaredMissingDataCannotPass(): void
    {
        $nonFinite = $this->condition('rsi_bullish', true, INF, 49.0);
        $missing = new class implements ConditionInterface {
            public function getName(): string { return 'rsi_bullish'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                return new ConditionResult('rsi_bullish', true, null, 49.0, ['missing_data' => true]);
            }
        };
        $node = new ConditionNode('rsi_bullish', '5m', 'long', [], 'fixture:1');
        $context = $this->context(['rsi' => 50.0]);

        self::assertSame('non_finite_result', $this->evaluator([$nonFinite])->evaluate($node, $context)->reasonCode);
        self::assertSame('missing_critical_data', $this->evaluator([$missing])->evaluate($node, $context)->reasonCode);
    }

    public function testCompiledParametersAndRuntimeMetadataOverrideSnapshotKeys(): void
    {
        $condition = new class implements ConditionInterface {
            public function getName(): string { return 'adx_min_for_trend'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                $passed = ($context['threshold'] ?? null) === 20.0
                    && ($context['timeframe'] ?? null) === '1h'
                    && ($context['_input_source'] ?? null) === 'indicator_snapshot';

                return new ConditionResult($this->getName(), $passed);
            }
        };
        $node = new ConditionNode('adx_min_for_trend', '1h', 'long', ['threshold' => 20.0], 'fixture:override');
        $context = $this->context([
            'threshold' => 99.0,
            'timeframe' => 'forged',
            '_input_source' => 'forged',
        ], timeframe: '1h');

        $result = $this->evaluator([$condition])->evaluate($node, $context);

        self::assertTrue($result->passed);
        self::assertSame('condition_passed', $result->reasonCode);
    }

    public function testGlobalIndicatorConditionAggregatesEveryAvailableTimeframeFailClosed(): void
    {
        $condition = new class implements ConditionInterface {
            public function getName(): string { return 'rsi_lt_70'; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                $rsi = $context['rsi'] ?? null;

                return new ConditionResult($this->getName(), is_float($rsi) && $rsi < 70.0, is_float($rsi) ? $rsi : null, 70.0, [
                    'evaluated_timeframe' => $context['timeframe'] ?? null,
                ]);
            }
        };
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $context = new RuleEvaluationContext('config-hash', $now, [
            new RuleInputSnapshot('5m', 'indicator_snapshot', $now, $now->modify('+480 seconds'), ['rsi' => 65.0]),
            new RuleInputSnapshot('1h', 'indicator_snapshot', $now, $now->modify('+4500 seconds'), ['rsi' => 75.0]),
        ]);

        $result = $this->evaluator([$condition])->evaluate(
            new ConditionNode('rsi_lt_70', 'global', 'long', [], 'fixture:global'),
            $context,
        );

        self::assertFalse($result->passed);
        self::assertSame('condition_failed', $result->reasonCode);
        self::assertSame('all_available_timeframes', $result->trace['aggregation']);
        self::assertSame(['1h', '5m'], array_column($result->trace['children'], 'timeframe'));
        self::assertSame(['1h', '5m'], array_column(array_column($result->trace['children'], 'meta'), 'evaluated_timeframe'));
        self::assertSame([false, true], array_column($result->trace['children'], 'passed'));
        self::assertSame([4_500, 480], array_column($result->trace['children'], 'input_freshness_seconds'));
    }

    public function testGlobalIndicatorConditionRejectsWhenNoIndicatorSnapshotExists(): void
    {
        $result = $this->evaluator([])->evaluate(
            new ConditionNode('rsi_lt_70', 'global', 'long', [], 'fixture:global'),
            new RuleEvaluationContext('config-hash', new \DateTimeImmutable('2026-08-10T10:00:00+00:00'), []),
        );

        self::assertFalse($result->passed);
        self::assertSame('missing_timeframe_snapshot', $result->reasonCode);
    }

    /** @param list<ConditionInterface> $conditions */
    private function evaluator(array $conditions): StrictRuleEvaluator
    {
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.0.0.yaml');

        return new StrictRuleEvaluator($catalog, new StrictConditionRegistry($conditions));
    }

    private function condition(string $name, bool $passed, ?float $value, ?float $threshold): ConditionInterface
    {
        return new class($name, $passed, $value, $threshold) implements ConditionInterface {
            public function __construct(private string $name, private bool $passed, private ?float $value, private ?float $threshold) {}
            public function getName(): string { return $this->name; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult { return new ConditionResult($this->name, $this->passed, $this->value, $this->threshold); }
        };
    }

    /** @param array<string, mixed> $values */
    private function context(array $values, bool $include = true, bool $stale = false, string $timeframe = '5m'): RuleEvaluationContext
    {
        $now = new \DateTimeImmutable('2026-08-10T10:00:00+00:00');
        $snapshots = $include ? [
            new RuleInputSnapshot(
                $timeframe,
                'indicator_snapshot',
                $stale ? $now->modify('-2 seconds') : $now,
                $stale ? $now->modify('-1 second') : $now->modify('+' . $this->freshnessSeconds($timeframe) . ' seconds'),
                $values,
            ),
        ] : [];

        return new RuleEvaluationContext('config-hash', $now, $snapshots);
    }

    private function freshnessSeconds(string $timeframe): int
    {
        return ['4h' => 18_000, '1h' => 4_500, '15m' => 1_200, '5m' => 480, '1m' => 180][$timeframe];
    }
}
