<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Evaluation;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Evaluation\StrictCompiledExpressionEvaluator;
use App\TradingCore\Rules\Evaluation\StrictConditionRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StrictCompiledExpressionEvaluator::class)]
final class StrictCompiledExpressionEvaluatorTest extends TestCase
{
    public function testSupportsEveryCompiledExpressionDeclaredByCatalog(): void
    {
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.0.0.yaml');
        $declared = array_values(array_filter(
            $catalog->conditionIds(),
            static fn (string $id): bool => str_starts_with($catalog->definition($id)->implementation, 'compiled_expression:'),
        ));
        $supported = StrictCompiledExpressionEvaluator::supportedIds();
        sort($supported, SORT_STRING);

        self::assertSame($declared, $supported);
    }

    public function testThresholdBoundariesAndMissingValuesFailClosed(): void
    {
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry([]));

        self::assertFalse($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => 19.999, 'gt' => 20.0])->passed);
        self::assertFalse($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => 20.0, 'gt' => 20.0])->passed);
        self::assertTrue($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => 20.001, 'gt' => 20.0])->passed);
        self::assertFalse($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => INF])->passed);
        self::assertTrue($evaluator->evaluate('rsi_5m_gt_floor', ['rsi' => INF])->meta['missing_data']);
    }

    public function testCompositePriceAndCrashConditionsUseStrictChildTruth(): void
    {
        $passingChildren = [];
        foreach (['price_regime_ok_short', 'ema200_slope_neg', 'macd_hist_decreasing_n', 'adx_min_for_trend'] as $id) {
            $passingChildren[] = $this->condition($id, true);
        }
        $evaluator = new StrictCompiledExpressionEvaluator(new StrictConditionRegistry($passingChildren));

        self::assertTrue($evaluator->evaluate('close_above_vwap_and_ma9', ['close' => 101.0, 'vwap' => 100.0, 'ma_9' => 100.5])->passed);
        self::assertFalse($evaluator->evaluate('close_above_vwap_and_ma9', ['close' => 100.0, 'vwap' => 100.0, 'ma_9' => 99.0])->passed);
        self::assertFalse($evaluator->evaluate('close_above_vwap_and_ma9', ['close' => 101.0, 'vwap' => 100.0])->passed);
        self::assertTrue($evaluator->evaluate('crash_context_ok', [])->passed);

        $failing = $passingChildren;
        $failing[2] = $this->condition('macd_hist_decreasing_n', false);
        self::assertFalse((new StrictCompiledExpressionEvaluator(new StrictConditionRegistry($failing)))->evaluate('crash_context_ok', [])->passed);
    }

    private function condition(string $name, bool $passed): ConditionInterface
    {
        return new class($name, $passed) implements ConditionInterface {
            public function __construct(private string $name, private bool $passed) {}
            public function getName(): string { return $this->name; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult { return new ConditionResult($this->name, $this->passed); }
        };
    }
}
