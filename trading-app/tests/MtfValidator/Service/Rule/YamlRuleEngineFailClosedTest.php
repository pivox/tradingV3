<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service\Rule;

use App\MtfValidator\Service\Rule\YamlRuleEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Yaml\Yaml;

/**
 * The YAML engine is the fallback used when the compiled ConditionRegistry engine throws. It cannot evaluate
 * compiled conditions (and in particular cannot check a canonical microstructure proof), so any block it does not
 * understand must fail closed. Before 2026-10-21 it returned true for such blocks.
 */
#[CoversClass(YamlRuleEngine::class)]
final class YamlRuleEngineFailClosedTest extends TestCase
{
    private const RULES = [
        'close_below_vwap' => ['lt_fields' => ['close', 'vwap']],
        'close_below_ma_9' => ['lt_fields' => ['close', 'ma_9']],
        'close_below_vwap_or_ma9' => ['any_of' => ['close_below_vwap', 'close_below_ma_9']],
    ];

    public function testProofBoundMicrostructureConditionFailsClosedWithoutAnyData(): void
    {
        $engine = new YamlRuleEngine();

        self::assertFalse($engine->evaluate(['order_flow_imbalance_lte' => ['max_ofi' => -0.15]], self::RULES, [], '1m'));
        self::assertFalse($engine->evaluate(['spread_bps_lte' => ['max_spread_bps' => 8]], self::RULES, [], '1m'));
    }

    public function testScenarioWithUnsupportedBlocksNoLongerPassesWhenOnlyTheDefinedRulesHold(): void
    {
        $engine = new YamlRuleEngine();
        // Same shape as the scalper_micro 1m short scenario: the defined YAML rule holds, the microstructure
        // guards are compiled conditions the fallback cannot check and for which no data is present at all.
        $scenario = ['all_of' => [
            'close_below_vwap_or_ma9',
            ['spread_bps_lte' => ['max_spread_bps' => 8]],
            ['order_flow_imbalance_lte' => ['max_ofi' => -0.15]],
            ['macd_hist_decreasing_n' => ['n' => 1, 'eps' => 0.001]],
        ]];
        $indicators = ['close' => 99.0, 'vwap' => 100.0, 'ma_9' => 100.0];

        self::assertTrue($engine->evaluate('close_below_vwap_or_ma9', self::RULES, $indicators, '1m'));
        self::assertFalse($engine->evaluate($scenario, self::RULES, $indicators, '1m'));
    }

    public function testMalformedFieldComparisonFailsClosed(): void
    {
        $engine = new YamlRuleEngine();

        self::assertFalse($engine->evaluate(['gt_fields' => ['close']], [], ['close' => 1.0], '1m'));
        self::assertFalse($engine->evaluate(['lt_fields' => ['close', 'vwap', 'ma_9']], [], ['close' => 1.0, 'vwap' => 2.0, 'ma_9' => 2.0], '1m'));
    }

    public function testSupportedPrimitivesAreUnchanged(): void
    {
        $engine = new YamlRuleEngine();
        $indicators = ['close' => 99.0, 'vwap' => 100.0, 'ma_9' => 100.0, 'rsi' => 40.0, 'macd_hist' => -0.5];

        self::assertTrue($engine->evaluate(['lt_fields' => ['close', 'vwap']], [], $indicators, '1m'));
        self::assertFalse($engine->evaluate(['gt_fields' => ['close', 'vwap']], [], $indicators, '1m'));
        self::assertTrue($engine->evaluate(['op' => '<', 'left' => 'macd_hist', 'right' => 0.0], [], $indicators, '1m'));
        self::assertTrue($engine->evaluate(['any_of' => ['close_below_vwap', ['gt_fields' => ['close', 'vwap']]]], self::RULES, $indicators, '1m'));
    }

    public function testUnsupportedBlockIsLoggedWithItsName(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $engine = new YamlRuleEngine($logger);

        $engine->evaluate(['order_flow_imbalance_gte' => ['min_ofi' => 0.15]], [], [], '1m');

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(['order_flow_imbalance_gte'], $logger->records[0]['context']['block_keys']);
    }

    public function testShippedScalperMicroScenariosCannotBeValidatedByTheFallback(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 4) . '/src/MtfValidator/config/validations.scalper_micro.yaml');
        $mtf = $config['mtf_validation'];
        $engine = new YamlRuleEngine();
        // Indicators that satisfy every rule the YAML engine can evaluate for the short side; nothing else.
        $indicators = ['close' => 99.0, 'vwap' => 100.0, 'ma_9' => 100.0];

        foreach (['5m', '1m'] as $timeframe) {
            foreach (['long', 'short'] as $side) {
                foreach ($mtf['validation']['timeframe'][$timeframe][$side] as $scenario) {
                    try {
                        $passed = $engine->evaluate($scenario, $mtf['rules'], $indicators, $timeframe);
                    } catch (\RuntimeException $exception) {
                        // Named rules such as `rsi_bearish` exist only as compiled conditions: the fallback refuses them.
                        self::assertStringContainsString('Unknown rule name', $exception->getMessage());
                        continue;
                    }
                    self::assertFalse($passed, sprintf('%s %s scenario passed in the fallback without its conditions', $timeframe, $side));
                }
            }
        }
    }
}
