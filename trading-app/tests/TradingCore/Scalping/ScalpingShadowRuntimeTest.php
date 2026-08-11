<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Scalping;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Tests\TradingCore\DayTrading\DayTradingShadowRuntimeTest;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Scalping\ScalpingShadowRequest;
use App\TradingCore\Scalping\ScalpingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ScalpingShadowRuntime::class)]
final class ScalpingShadowRuntimeTest extends TestCase
{
    #[DataProvider('identities')]
    public function testEachExactIdentityPlansWithCompleteLineage(string $setupId, string $side): void
    {
        $outcome = self::fixtureRuntime()->run(self::fixtureRequest($setupId, $side));

        self::assertSame('planned', $outcome->status);
        self::assertSame('scalping_shadow_planned', $outcome->reasonCode);
        self::assertNotNull($outcome->orderPlan);
        self::assertNotNull($outcome->reservation);
        self::assertSame('scalping', $outcome->lineage->modeId);
        self::assertSame('1.1.0', $outcome->lineage->modeVersion);
        self::assertSame($setupId, $outcome->lineage->setupId);
        self::assertSame('1.1.0', $outcome->lineage->setupVersion);
        self::assertSame(strtoupper($side), $outcome->lineage->side);
        self::assertSame('fake', $outcome->lineage->exchange);
        self::assertSame('test', $outcome->lineage->environment);
        self::assertSame('perpetual', $outcome->lineage->marketType);
        self::assertSame('BTCUSDT', $outcome->lineage->symbol);
        self::assertSame('decision-scalping-shadow-' . str_replace('.', '-', $setupId), $outcome->lineage->decisionKey);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', (string) $outcome->lineage->conditionCatalogHash);
        self::assertSame($setupId, $outcome->orderPlan->setupId);
        self::assertSame($side, $outcome->orderPlan->side);
        self::assertSame($outcome->lineage->configHash, $outcome->orderPlan->configHash);
        self::assertSame($outcome->orderPlan->planHash, $outcome->reservation->planHash);
        self::assertSame('5m', $outcome->evidence['rules']['execution_timeframe']);
        self::assertSame(['1m'], $outcome->evidence['rules']['mandatory_confirmations']);
    }

    public function testCrossSetupIdentityWrongSideAndWrongVersionAreRejectedBeforeReservation(): void
    {
        $trend = self::fixtureRequest('scalping.trend_continuation.long', 'long');
        $pullback = self::fixtureRequest('scalping.pullback.long', 'long');
        $crossSetup = self::withLineage($trend, $pullback->lineage);
        $wrongSide = self::withConfig($trend, new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            'fake', 'test', 'short', ShadowExecutionCapability::Fake,
        ));
        $wrongVersion = self::withConfig($trend, new EffectiveTradingConfigRequest(
            'scalping', '1.0.0', 'scalping.trend_continuation.long', '1.0.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));

        foreach ([
            [$crossSetup, 'scalping_shadow_lineage_mismatch'],
            [$wrongSide, 'scalping_shadow_identity_unsupported'],
            [$wrongVersion, 'scalping_shadow_identity_unsupported'],
        ] as [$request, $reason]) {
            $outcome = self::fixtureRuntime()->run($request);
            self::assertSame('no_trade', $outcome->status);
            self::assertSame($reason, $outcome->reasonCode);
            self::assertNull($outcome->orderPlan);
            self::assertNull($outcome->reservation);
        }
    }

    public function testContinuationConditionsCannotRescueExpiredPullback(): void
    {
        $request = self::fixtureRequest('scalping.pullback.long', 'long');
        $inputs = $request->indicatorsByTimeframe;
        $inputs['15m']['pullback_age_bars'] = 4;

        $outcome = self::fixtureRuntime()->run($request->withIndicators($inputs));

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('scalping_shadow_setup_section_failed', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    public function testPullbackConditionsCannotRescueFailedContinuationTrigger(): void
    {
        $outcome = self::fixtureRuntime([
            'macd_hist_gt_eps@15m',
            'macd_hist_slope_pos@15m',
            'macd_line_above_signal@15m',
        ])->run(self::fixtureRequest('scalping.trend_continuation.long', 'long'));

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('scalping_shadow_setup_section_failed', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    public function testMissingOrStaleOneMinuteConfirmationNeverReserves(): void
    {
        $request = self::fixtureRequest('scalping.trend_continuation.long', 'long');
        $missing = $request->indicatorsByTimeframe;
        unset($missing['1m']);
        $stale = $request->indicatorsByTimeframe;
        $stale['1m']['kline_time'] = '2026-08-10T11:55:00Z';

        foreach ([
            [$request->withIndicators($missing), 'scalping_shadow_critical_timeframe_missing'],
            [$request->withIndicators($stale), 'scalping_shadow_critical_timeframe_stale'],
        ] as [$candidate, $reason]) {
            $outcome = self::fixtureRuntime()->run($candidate);
            self::assertSame('no_trade', $outcome->status);
            self::assertSame($reason, $outcome->reasonCode);
            self::assertNull($outcome->orderPlan);
            self::assertNull($outcome->reservation);
        }
    }

    public function testConfigHashPlanConfigAndExcessiveCostsFailClosed(): void
    {
        $request = self::fixtureRequest('scalping.trend_continuation.long', 'long');
        $foreignConfig = new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            'okx', 'demo', 'long', ShadowExecutionCapability::Paper,
        );
        $foreign = self::fixtureRequest('scalping.pullback.long', 'long')->orderPlanRequest;
        $plan = $request->orderPlanRequest;
        $badPlan = new CanonicalOrderPlanBuildRequest(
            $foreign->policy,
            $plan->zoneRequest,
            $plan->zone,
            $plan->protectionRequest,
            $plan->protection,
            $plan->riskRequest,
            $plan->risk,
            $plan->netR,
            $plan->costs,
        );

        foreach ([
            [self::withConfig($request, $foreignConfig), 'scalping_shadow_lineage_mismatch'],
            [self::withPlanRequest($request, $badPlan), 'scalping_shadow_plan_config_mismatch'],
            [self::fixtureRequest('scalping.trend_continuation.long', 'long', liveSpreadBps: 6.01), 'scalping_shadow_live_spread_exceeded'],
            [self::fixtureRequest('scalping.trend_continuation.long', 'long', estimatedSlippageBps: 8.01), 'scalping_shadow_slippage_exceeded'],
        ] as [$candidate, $reason]) {
            $outcome = self::fixtureRuntime()->run($candidate);
            self::assertSame('no_trade', $outcome->status);
            self::assertSame($reason, $outcome->reasonCode);
            self::assertNull($outcome->orderPlan);
            self::assertNull($outcome->reservation);
        }
    }

    public function testPrivateMainnetIsForbiddenWithoutReservation(): void
    {
        $request = self::fixtureRequest('scalping.trend_continuation.long', 'long');
        $private = self::withConfig($request, new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::PrivateMainnet,
        ));

        $outcome = self::fixtureRuntime()->run($private);

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('scalping_shadow_capability_forbidden', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    /** @return iterable<string, array{string, string}> */
    public static function identities(): iterable
    {
        yield 'trend continuation long' => ['scalping.trend_continuation.long', 'long'];
        yield 'pullback long' => ['scalping.pullback.long', 'long'];
        yield 'trend momentum short' => ['scalping.trend_momentum.short', 'short'];
    }

    /** @param list<string> $failingConditionTimeframes */
    public static function fixtureRuntime(array $failingConditionTimeframes = []): ScalpingShadowRuntime
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return new ScalpingShadowRuntime(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime(self::passingConditions($failingConditionTimeframes)),
            new CanonicalExecutionPolicyCompiler(),
            new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)),
            DayTradingShadowRuntimeTest::fixtureSelector(),
            $clock,
        );
    }

    public static function fixtureRequest(
        string $setupId,
        string $side,
        ?float $liveSpreadBps = 1.0,
        ?float $estimatedSlippageBps = 1.0,
        ShadowExecutionCapability $capability = ShadowExecutionCapability::Fake,
    ): ScalpingShadowRequest {
        $configRequest = new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', $setupId, '1.1.0', 'fake', 'test', $side, $capability,
        );
        $snapshot = (new EffectiveTradingConfigResolver())->resolve($configRequest);
        $decisionKey = 'decision-scalping-shadow-' . str_replace('.', '-', $setupId);
        $lineage = LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-scalping-shadow',
            'orchestration_set_id' => 'set-scalping-shadow',
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => $setupId,
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => strtoupper($side),
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => $decisionKey,
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:scalping-shadow',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
        $components = CanonicalOrderPlanPipelineFixture::accepted(side: $side, executionPolicy: $policy);
        $scope = new CanonicalPortfolioScope('shadow', 'fake', 'test', 'account-1', 'scalping', 'USDT');
        $portfolio = new CanonicalPortfolioSnapshot(
            $scope,
            'scalping_test',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            0.0,
            0.0,
            0,
            0,
            0.0,
            0.0,
            0.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );

        return new ScalpingShadowRequest(
            $configRequest,
            $lineage,
            [
                '1h' => ['kline_time' => '2026-08-10T11:00:00Z', 'series_order' => 'oldest_to_newest'],
                '15m' => ['kline_time' => '2026-08-10T11:45:00Z', 'series_order' => 'oldest_to_newest', 'pullback_age_bars' => 1],
                '5m' => ['kline_time' => '2026-08-10T11:55:00Z', 'series_order' => 'oldest_to_newest'],
                '1m' => ['kline_time' => '2026-08-10T11:59:00Z', 'series_order' => 'oldest_to_newest'],
            ],
            new CanonicalOrderPlanBuildRequest(...$components),
            $scope,
            $portfolio,
            $decisionKey,
            $liveSpreadBps,
            $estimatedSlippageBps,
        );
    }

    private static function withConfig(ScalpingShadowRequest $request, EffectiveTradingConfigRequest $config): ScalpingShadowRequest
    {
        return new ScalpingShadowRequest(
            $config,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
        );
    }

    private static function withLineage(ScalpingShadowRequest $request, LineageContext $lineage): ScalpingShadowRequest
    {
        return new ScalpingShadowRequest(
            $request->configRequest,
            $lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
        );
    }

    private static function withPlanRequest(ScalpingShadowRequest $request, CanonicalOrderPlanBuildRequest $plan): ScalpingShadowRequest
    {
        return new ScalpingShadowRequest(
            $request->configRequest,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $plan,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
        );
    }

    /**
     * @param list<string> $failingConditionTimeframes
     * @return list<ConditionInterface>
     */
    private static function passingConditions(array $failingConditionTimeframes = []): array
    {
        $ids = (new \App\TradingCore\Rules\Catalog\ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        )->conditionIds();

        return array_map(static fn (string $id): ConditionInterface => new class($id, $failingConditionTimeframes) implements ConditionInterface {
            /** @param list<string> $failingConditionTimeframes */
            public function __construct(
                private readonly string $id,
                private readonly array $failingConditionTimeframes,
            ) {}
            public function getName(): string { return $this->id; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult
            {
                $identity = $this->id . '@' . ($context['timeframe'] ?? 'global');

                return new ConditionResult($this->id, !\in_array($identity, $this->failingConditionTimeframes, true));
            }
        }, $ids);
    }
}
