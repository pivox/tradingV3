<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Microstructure;

use App\Indicator\Condition\OrderFlowImbalanceGteCondition;
use App\Indicator\Condition\OrderFlowImbalanceLteCondition;
use App\Indicator\Condition\SpreadBpsLteCondition;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\TradingCore\Microstructure\CanonicalMicrostructureEngine;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\CanonicalMicrostructureRuleInputAdapter;
use App\TradingCore\Rules\Ast\ConditionNode;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\TradingCore\Rules\Evaluation\RuleEvaluationContext;
use App\TradingCore\Rules\Evaluation\StrictConditionRegistry;
use App\TradingCore\Rules\Evaluation\StrictRuleEvaluator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CanonicalMicrostructureRuleEvaluationTest extends TestCase
{
    public function testCanonicalSnapshotPassesAllThreeTypedConditions(): void
    {
        [$evaluator, $input] = $this->fixture();
        $context = new RuleEvaluationContext('sha256:' . str_repeat('c', 64), $input->observedAt, [$input]);

        foreach ([
            new ConditionNode('spread_bps_lte', '1m', 'long', ['max_spread_bps' => 200.0], 'fixture:spread'),
            new ConditionNode('order_flow_imbalance_gte', '1m', 'long', ['min_ofi' => 0.66], 'fixture:long-ofi'),
            new ConditionNode('order_flow_imbalance_lte', '1m', 'short', ['max_ofi' => 0.67], 'fixture:short-ofi'),
        ] as $node) {
            $result = $evaluator->evaluate($node, $context);
            self::assertTrue($result->passed, $node->conditionId);
            self::assertSame('condition_passed', $result->reasonCode);
            self::assertSame('timestamped_order_book', $result->trace['input_source']);
        }
    }

    public function testMissingAndExpiredCanonicalInputsFailClosedBeforeConditionExecution(): void
    {
        [$evaluator, $input] = $this->fixture();
        $node = new ConditionNode('spread_bps_lte', '1m', 'long', ['max_spread_bps' => 200.0], 'fixture:spread');

        $missing = $evaluator->evaluate(
            $node,
            new RuleEvaluationContext('config', $input->observedAt, []),
        );
        $stale = $evaluator->evaluate(
            $node,
            new RuleEvaluationContext('config', $input->validUntil->modify('+1 microsecond'), [$input]),
        );

        self::assertSame('missing_timeframe_snapshot', $missing->reasonCode);
        self::assertSame('stale_input', $stale->reasonCode);
    }

    /** @return array{StrictRuleEvaluator, \App\TradingCore\Rules\Evaluation\RuleInputSnapshot} */
    private function fixture(): array
    {
        $evaluatedAt = new \DateTimeImmutable('2026-08-14T12:01:00.000000+00:00');
        $checksum = 'sha256:' . str_repeat('f', 64);
        $snapshot = (new CanonicalMicrostructureEngine())->build(
            new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3),
            $evaluatedAt,
            [new NormalizedBacktestPublicBook(
                str_repeat('a', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT',
                '2026-08-14T12:00:59.000000Z', '2026-08-14T12:00:59.000000Z',
                '99', '10', '101', '12', 'contracts', '2', '3', 'ws_books',
            )],
            [
                new NormalizedBacktestPublicTrade(str_repeat('1', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '1', '2026-08-14T12:00:10.000000Z', '2026-08-14T12:00:10.000000Z', 'buy', '100', '3', 'contracts'),
                new NormalizedBacktestPublicTrade(str_repeat('2', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '2', '2026-08-14T12:00:30.000000Z', '2026-08-14T12:00:30.000000Z', 'sell', '100', '1', 'contracts'),
                new NormalizedBacktestPublicTrade(str_repeat('3', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '3', '2026-08-14T12:00:55.000000Z', '2026-08-14T12:00:55.000000Z', 'buy', '100', '2', 'contracts'),
            ],
        );
        $input = (new CanonicalMicrostructureRuleInputAdapter())->adapt($snapshot);
        $evaluator = new StrictRuleEvaluator(
            (new ConditionCatalogLoader())->loadVersion('1.2.0'),
            new StrictConditionRegistry([
                new SpreadBpsLteCondition(),
                new OrderFlowImbalanceGteCondition(),
                new OrderFlowImbalanceLteCondition(),
            ]),
        );

        return [$evaluator, $input];
    }
}
