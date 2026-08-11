<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Rules\Compiler;

use App\TradingCore\Rules\Ast\AllOfNode;
use App\TradingCore\Rules\Ast\ConditionNode;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Compiler\RuleCompilationException;
use App\TradingCore\Rules\Compiler\RuleExpressionCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleExpressionCompiler::class)]
final class RuleExpressionCompilerTest extends TestCase
{
    private RuleExpressionCompiler $compiler;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 4);
        $catalog = (new ConditionCatalogLoader())->loadFile($root . '/config/trading/condition_catalog/1.0.0.yaml');
        $this->compiler = new RuleExpressionCompiler($catalog);
    }

    public function testCompilesImmutableTreeAndAppliesTypedDefaults(): void
    {
        $node = $this->compiler->compile([
            'op' => 'all_of',
            'nodes' => [
                ['condition' => 'macd_hist_gt_eps', 'timeframe' => '5m', 'parameters' => ['eps' => 0.001], 'provenance' => 'fixture:1'],
                ['condition' => 'rsi_bullish', 'timeframe' => '5m', 'provenance' => 'fixture:2'],
            ],
            'provenance' => 'fixture:group',
        ], 'long');

        self::assertInstanceOf(AllOfNode::class, $node);
        self::assertCount(2, $node->children);
        self::assertInstanceOf(ConditionNode::class, $node->children[0]);
        self::assertSame(['eps' => 0.001], $node->children[0]->parameters);
        self::assertSame('fixture:group', $node->provenance);
    }

    public function testRejectsUnknownOperatorConditionAndFieldsAndEmptyGroups(): void
    {
        $this->assertRejected(['op' => 'none_of', 'nodes' => [['condition' => 'rsi_bullish', 'timeframe' => '5m', 'provenance' => 'x']], 'provenance' => 'x'], 'Unknown operator');
        $this->assertRejected(['condition' => 'invented', 'timeframe' => '5m', 'provenance' => 'x'], 'Unknown condition');
        $this->assertRejected(['condition' => 'rsi_bullish', 'timeframe' => '5m', 'provenance' => 'x', 'fallback' => true], 'Unknown field');
        $this->assertRejected(['op' => 'all_of', 'nodes' => [], 'provenance' => 'x'], 'must not be empty');
    }

    public function testRejectsSideTimeframeAndParameterContractViolations(): void
    {
        $this->assertRejected(['condition' => 'rsi_bullish', 'timeframe' => '5m', 'provenance' => 'x'], 'not compatible with side short', 'short');
        $this->assertRejected(['condition' => 'spread_bps_lte', 'timeframe' => '5m', 'parameters' => ['max_spread_bps' => 8], 'provenance' => 'x'], 'not compatible with timeframe 5m');
        $this->assertRejected(['condition' => 'rsi_lt_70', 'timeframe' => '15m', 'parameters' => ['typo' => 70], 'provenance' => 'x'], 'Unknown parameter');
        $this->assertRejected(['condition' => 'rsi_lt_70', 'timeframe' => '15m', 'parameters' => ['rsi_lt_70_threshold' => INF], 'provenance' => 'x'], 'finite number');
        $this->assertRejected(['condition' => 'rsi_lt_70', 'timeframe' => '15m', 'parameters' => ['rsi_lt_70_threshold' => 101], 'provenance' => 'x'], 'above maximum');
    }

    public function testExplicitNodeParametersOverrideDefaultsAndPreservePerParameterLineage(): void
    {
        $node = $this->compiler->compile([
            'condition' => 'atr_rel_in_range_15m',
            'timeframe' => '15m',
            'parameters' => ['min_atr_pct' => 0.002],
            'provenance' => 'test:explicit',
        ], 'long');

        self::assertInstanceOf(ConditionNode::class, $node);
        self::assertSame(['max_atr_pct' => 0.045, 'min_atr_pct' => 0.002], $node->parameters);
        self::assertSame([
            'max_atr_pct' => 'condition_catalog_default',
            'min_atr_pct' => 'setup_contract',
        ], $node->parameterSources);
        self::assertSame($node->parameterSources, $node->toArray()['parameter_sources']);
    }

    /** @param array<string, mixed> $expression */
    private function assertRejected(array $expression, string $message, string $side = 'long'): void
    {
        try {
            $this->compiler->compile($expression, $side);
            self::fail('Expected strict compilation failure.');
        } catch (RuleCompilationException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}
