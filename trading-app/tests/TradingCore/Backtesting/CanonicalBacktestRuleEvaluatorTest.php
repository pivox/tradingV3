<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Backtesting;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluation;
use App\TradingCore\Backtesting\CanonicalBacktestRuleEvaluator;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalBacktestRuleEvaluation::class)]
#[CoversClass(CanonicalBacktestRuleEvaluator::class)]
final class CanonicalBacktestRuleEvaluatorTest extends TestCase
{
    public function testItResolvesTheExactFakeBacktestSnapshotAndReturnsDeterministicCanonicalPass(): void
    {
        $request = $this->validRequest();
        $resolved = $this->resolvedSnapshot();
        $resolver = new class($resolved) implements EffectiveTradingConfigResolverInterface {
            public int $calls = 0;
            public ?EffectiveTradingConfigRequest $request = null;

            public function __construct(private readonly EffectiveTradingConfigSnapshot $snapshot) {}

            public function resolve(EffectiveTradingConfigRequest|string $request): EffectiveTradingConfigSnapshot
            {
                if (!$request instanceof EffectiveTradingConfigRequest) {
                    throw new \LogicException('Expected a canonical request.');
                }
                ++$this->calls;
                $this->request = $request;

                return $this->snapshot;
            }
        };
        $evaluator = new CanonicalBacktestRuleEvaluator(
            $resolver,
            new CanonicalSetupRuleRuntime($this->passingConditions()),
        );

        $first = $evaluator->evaluate($request)->toArray();
        $second = $evaluator->evaluate($request)->toArray();

        self::assertSame(2, $resolver->calls);
        self::assertSame(ShadowExecutionCapability::Backtest, $resolver->request->capability);
        self::assertSame('fake', $resolver->request->exchange);
        self::assertSame('test', $resolver->request->environment);
        self::assertSame([
            'schema_version', 'request_id', 'mode_id', 'mode_version', 'setup_id', 'setup_version',
            'side', 'exchange', 'environment', 'market_type', 'symbol', 'config_hash',
            'condition_catalog_hash', 'snapshot_hash', 'evaluated_at', 'passed', 'reason_code',
            'trace', 'input_hash', 'result_hash',
        ], array_keys($first));
        self::assertSame('canonical-backtest-rule-result.v1', $first['schema_version']);
        self::assertTrue($first['passed']);
        self::assertSame('setup_rules_passed', $first['reason_code']);
        self::assertArrayNotHasKey('plan_cache_hit', $first['trace']);
        self::assertArrayHasKey('plan_cache_key', $first['trace']);
        self::assertSame($first, $second);
        self::assertSame('2026-08-10T12:00:00Z', $first['trace']['evaluated_at']);
        $withoutResultHash = $first;
        unset($withoutResultHash['result_hash']);
        self::assertSame(CanonicalBacktestRuleEvaluator::canonicalHash($request), $first['input_hash']);
        self::assertSame(CanonicalBacktestRuleEvaluator::canonicalHash($withoutResultHash), $first['result_hash']);
    }

    public function testResultValueObjectDoesNotExposeMutableInternalState(): void
    {
        $evaluation = $this->evaluatorWithConditions($this->passingConditions())->evaluate($this->validRequest());
        $first = $evaluation->toArray();
        $first['trace']['forged'] = true;

        self::assertArrayNotHasKey('forged', $evaluation->toArray()['trace']);
    }

    public function testNoTradeIsARegularDeterministicResult(): void
    {
        $evaluation = $this->evaluatorWithConditions($this->failingConditions())->evaluate($this->validRequest())->toArray();

        self::assertFalse($evaluation['passed']);
        self::assertContains($evaluation['reason_code'], [
            'setup_section_failed',
            'setup_filter_failed',
            'no_trade_rule_matched',
        ]);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $evaluation['result_hash']);
    }

    public function testMissingStaleAndMismatchedIndicatorInputsFailClosedAsNormalResults(): void
    {
        $missing = $this->validRequest();
        unset($missing['indicators_by_timeframe']['1m']);
        $stale = $this->validRequest();
        $stale['indicators_by_timeframe']['1m']['kline_time'] = '2026-08-10T11:50:00Z';
        foreach ([
            'missing' => [$missing, 'critical_timeframe_missing'],
            'stale' => [$stale, 'critical_timeframe_stale'],
        ] as $label => [$request, $reason]) {
            $result = $this->evaluatorWithConditions($this->passingConditions())->evaluate($request)->toArray();

            self::assertFalse($result['passed'], $label);
            self::assertSame($reason, $result['reason_code'], $label);
        }
    }

    public function testItStrictlyRejectsAWellShapedCrossIdentityIndicatorSnapshot(): void
    {
        $request = $this->validRequest();
        $request['indicators_by_timeframe']['1m']['snapshot_identity']['symbol'] = 'ETHUSDT';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_backtest_rule_indicator_identity_mismatch');
        $this->evaluatorWithConditions($this->passingConditions())->evaluate($request);
    }

    public function testObjectMemberOrderDoesNotChangeIndicatorIdentitySemanticsOrHash(): void
    {
        $request = $this->validRequest();
        $identity = $request['indicators_by_timeframe']['1m']['snapshot_identity'];
        $request['indicators_by_timeframe']['1m']['snapshot_identity'] = array_reverse($identity, true);

        $result = $this->evaluatorWithConditions($this->passingConditions())->evaluate($request)->toArray();

        self::assertTrue($result['passed']);
        self::assertSame(CanonicalBacktestRuleEvaluator::canonicalHash($request), $result['input_hash']);
    }

    public function testCanonicalHashesNormalizeIntegralFloatsAcrossTheSharedPhpPythonDomain(): void
    {
        $floatRequest = $this->validRequest();
        $integerRequest = $floatRequest;
        $integerRequest['indicators_by_timeframe']['1m']['ema_200_series'][0] = 100;
        $evaluator = $this->evaluatorWithConditions($this->passingConditions());

        $floatResult = $evaluator->evaluate($floatRequest)->toArray();
        $integerResult = $evaluator->evaluate($integerRequest)->toArray();

        self::assertSame($floatResult['input_hash'], $integerResult['input_hash']);
        self::assertSame($floatResult['result_hash'], $integerResult['result_hash']);
    }

    public function testCanonicalHashMatchesThePythonModernEncoderGolden(): void
    {
        self::assertSame(
            'sha256:8368f26027e303ebb06572df014215255316634e28c69300df4b84c535cce0ba',
            CanonicalBacktestRuleEvaluator::canonicalHash([
                'unicode' => "line\u{2028}separator",
                'small' => 1.25e-7,
                'integer_float' => 42.0,
                'nested' => ['z' => true, 'a' => [null, '/']],
            ]),
        );
    }

    public function testCanonicalJsonSortsNestedObjectKeysAndNormalizesIntegralFloats(): void
    {
        $payload = [
            'z' => 1.0,
            'nested' => ['z' => 2.0, 'a' => 'value'],
            'a' => true,
        ];
        $plain = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $canonical = CanonicalBacktestRuleEvaluator::canonicalJson($payload);

        self::assertSame('{"a":true,"nested":{"a":"value","z":2},"z":1}', $canonical);
        self::assertNotSame($plain, $canonical);
    }

    public function testCanonicalHashUsesTheExactSharedPhpInt64FloatBoundary(): void
    {
        self::assertSame(
            'sha256:fcef4235cdc956a7cecf9de9fea0896ff0f1fb291e6ec6144caf784e9870bf0d',
            CanonicalBacktestRuleEvaluator::canonicalHash(['boundary' => -9_223_372_036_854_775_808.0]),
        );

        foreach ([
            'positive 2^63' => 9_223_372_036_854_775_808.0,
            'below negative 2^63' => -9_223_372_036_854_777_856.0,
        ] as $label => $value) {
            try {
                CanonicalBacktestRuleEvaluator::canonicalHash(['boundary' => $value]);
                self::fail('Expected int64 boundary rejection: ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('canonical_backtest_rule_input_invalid', $exception->getMessage(), $label);
            }
        }
    }

    public function testCanonicalInputUsesTheProtocolEightMebibyteBudgetRatherThanPaperEventLimits(): void
    {
        $request = $this->validRequest();
        $request['indicators_by_timeframe']['1m']['bounded_fixture'] = str_repeat('x', 1_100_000);

        $result = $this->evaluatorWithConditions($this->passingConditions())->evaluate($request)->toArray();

        self::assertTrue($result['passed']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $result['input_hash']);
    }

    public function testRuntimeEvaluatesTheSameIntegralFloatNormalizationThatIsHashed(): void
    {
        $floatRequest = $this->validRequest();
        $floatRequest['indicators_by_timeframe']['1h']['type_probe'] = 42.0;
        $integerRequest = $floatRequest;
        $integerRequest['indicators_by_timeframe']['1h']['type_probe'] = 42;
        $evaluator = $this->evaluatorWithConditions($this->typeSensitiveConditions());

        $floatResult = $evaluator->evaluate($floatRequest)->toArray();
        $integerResult = $evaluator->evaluate($integerRequest)->toArray();

        self::assertTrue($floatResult['passed']);
        self::assertSame($integerResult, $floatResult);
        self::assertSame($integerResult['input_hash'], $floatResult['input_hash']);
    }

    public function testNormalizationStopsAtTheProtocolDepthBeforeInspectingDeeperValues(): void
    {
        $nested = new \stdClass();
        for ($depth = 0; $depth < 129; ++$depth) {
            $nested = [$nested];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_backtest_rule_input_depth_exceeded');
        CanonicalBacktestRuleEvaluator::canonicalHash(['nested' => $nested]);
    }

    #[DataProvider('invalidRequestProvider')]
    public function testItRejectsNonExactLegacyAndIllTypedRequests(string $label, callable $mutate, string $reason): void
    {
        $request = $this->validRequest();
        $mutate($request);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        $this->evaluatorWithConditions($this->passingConditions())->evaluate($request);
    }

    /** @return iterable<string, array{string, callable(array<string,mixed>&):void, string}> */
    public static function invalidRequestProvider(): iterable
    {
        yield 'extra root field' => ['extra root field', static function (array &$request): void {
            $request['profile'] = 'scalper';
        }, 'canonical_backtest_rule_request_shape_invalid'];
        yield 'legacy schema' => ['legacy schema', static function (array &$request): void {
            $request['schema_version'] = 'backtest-rule-request.v0';
        }, 'canonical_backtest_rule_schema_invalid'];
        yield 'non string id' => ['non string id', static function (array &$request): void {
            $request['request_id'] = 123;
        }, 'canonical_backtest_rule_request_id_invalid'];
        yield 'non utc instant' => ['non utc instant', static function (array &$request): void {
            $request['evaluated_at'] = '2026-08-10T14:00:00+02:00';
        }, 'canonical_backtest_rule_evaluated_at_invalid'];
        yield 'epoch kline time' => ['epoch kline time', static function (array &$request): void {
            $request['indicators_by_timeframe']['1m']['kline_time'] = 1_786_365_540;
        }, 'canonical_backtest_rule_kline_time_invalid'];
        yield 'extra identity field' => ['extra identity field', static function (array &$request): void {
            $request['indicators_by_timeframe']['1m']['snapshot_identity']['venue'] = 'fake';
        }, 'canonical_backtest_rule_indicator_identity_invalid'];
        yield 'non finite indicator' => ['non finite indicator', static function (array &$request): void {
            $request['indicators_by_timeframe']['1m']['rsi'] = INF;
        }, 'canonical_backtest_rule_input_invalid'];
        yield 'private exchange' => ['private exchange', static function (array &$request): void {
            $request['effective_config_snapshot']['request']['exchange'] = 'okx';
        }, 'canonical_backtest_rule_fake_backtest_required'];
        yield 'wrong capability' => ['wrong capability', static function (array &$request): void {
            $request['effective_config_snapshot']['request']['execution_capability'] = 'paper';
        }, 'canonical_backtest_rule_fake_backtest_required'];
        yield 'extra snapshot field' => ['extra snapshot field', static function (array &$request): void {
            $request['effective_config_snapshot']['profile'] = 'scalper';
        }, 'canonical_backtest_rule_snapshot_invalid'];
    }

    public function testItRejectsForgedHashesAndAnyDifferenceFromTheFreshlyResolvedSnapshot(): void
    {
        $forged = $this->validRequest();
        $forged['effective_config_snapshot']['config_hash'] = 'sha256:' . str_repeat('a', 64);
        $different = $this->validRequest();
        $different['effective_config_snapshot']['request']['environment'] = 'local';
        $different['effective_config_snapshot']['snapshot_hash'] = CanonicalEffectiveConfigSnapshot::calculateSnapshotHash(
            $different['effective_config_snapshot'],
        );

        foreach ([
            'forged' => [$forged, 'canonical_backtest_rule_snapshot_invalid'],
            'resolved mismatch' => [$different, 'canonical_backtest_rule_snapshot_mismatch'],
        ] as $label => [$request, $reason]) {
            try {
                $this->evaluatorWithConditions($this->passingConditions())->evaluate($request);
                self::fail('Expected rejection: ' . $label);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($reason, $exception->getMessage(), $label);
            }
        }
    }

    /** @param list<ConditionInterface> $conditions */
    private function evaluatorWithConditions(array $conditions): CanonicalBacktestRuleEvaluator
    {
        return new CanonicalBacktestRuleEvaluator(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime($conditions),
        );
    }

    /** @return array<string,mixed> */
    private function validRequest(): array
    {
        $snapshot = $this->resolvedSnapshot()->toArray();

        return [
            'schema_version' => 'canonical-backtest-rule-request.v1',
            'request_id' => 'bt-rule-0001',
            'effective_config_snapshot' => $snapshot,
            'symbol' => 'BTCUSDT',
            'market_type' => 'perpetual',
            'evaluated_at' => '2026-08-10T12:00:00Z',
            'indicators_by_timeframe' => [
                '1h' => self::indicator('1h', '2026-08-10T11:00:00Z'),
                '15m' => self::indicator('15m', '2026-08-10T11:45:00Z', ['pullback_age_bars' => 1]),
                '5m' => self::indicator('5m', '2026-08-10T11:55:00Z'),
                '1m' => self::indicator('1m', '2026-08-10T11:59:00Z'),
            ],
        ];
    }

    private function resolvedSnapshot(): EffectiveTradingConfigSnapshot
    {
        return (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.pullback.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Backtest,
        ));
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private static function indicator(string $timeframe, string $klineTime, array $extra = []): array
    {
        $step = match ($timeframe) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            default => throw new \LogicException('Unsupported fixture timeframe.'),
        };
        $current = (new \DateTimeImmutable($klineTime))->getTimestamp();

        return array_replace([
            'snapshot_identity' => [
                'timeframe' => $timeframe,
                'symbol' => 'BTCUSDT',
                'exchange' => 'fake',
                'environment' => 'test',
                'market_type' => 'perpetual',
            ],
            'kline_time' => $klineTime,
            'series_order' => 'oldest_to_newest',
            'ema_200_series' => [100.0, 101.0],
            'ema_200_series_timestamps' => [$current - $step, $current],
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => [$current - $step, $current],
            'macd_line_signal_series' => [-0.1, 0.1],
            'macd_line_signal_series_timestamps' => [$current - $step, $current],
        ], $extra);
    }

    /** @return list<ConditionInterface> */
    private function passingConditions(): array
    {
        return $this->conditions(true);
    }

    /** @return list<ConditionInterface> */
    private function failingConditions(): array
    {
        return $this->conditions(false);
    }

    /** @return list<ConditionInterface> */
    private function conditions(bool $passes): array
    {
        $ids = (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        )->conditionIds();

        return array_map(static fn (string $id): ConditionInterface => new class($id, $passes) implements ConditionInterface {
            public function __construct(private readonly string $id, private readonly bool $passes) {}

            public function getName(): string
            {
                return $this->id;
            }

            /** @param array<string,mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                return new ConditionResult($this->id, $this->passes);
            }
        }, $ids);
    }

    /** @return list<ConditionInterface> */
    private function typeSensitiveConditions(): array
    {
        $ids = (new ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        )->conditionIds();

        return array_map(static fn (string $id): ConditionInterface => new class($id) implements ConditionInterface {
            public function __construct(private readonly string $id) {}

            public function getName(): string
            {
                return $this->id;
            }

            /** @param array<string,mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                $passed = $this->id !== 'adx_min_for_trend'
                    || \is_int($context['type_probe'] ?? null);

                return new ConditionResult($this->id, $passed);
            }
        }, $ids);
    }
}
