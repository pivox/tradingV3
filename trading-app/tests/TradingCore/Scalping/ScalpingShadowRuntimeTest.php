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
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilderInterface;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Scalping\ScalpingShadowRequest;
use App\TradingCore\Scalping\ScalpingShadowOutcome;
use App\TradingCore\Scalping\ScalpingShadowRuntime;
use App\TradingCore\Shadow\CanonicalShadowRuntime;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeRequest;
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
        self::assertSame(0.02, $outcome->orderPlan->riskRate);
        self::assertSame(3.0, $outcome->orderPlan->modeLeverageCap);
        self::assertLessThanOrEqual(3, $outcome->orderPlan->finalLeverage);
        self::assertSame(25.0, $outcome->orderPlan->exchangeMaxNotional);
        self::assertLessThanOrEqual(25.0, $outcome->orderPlan->positionNotional);
        self::assertSame('2026-08-10T12:00:45+00:00', $outcome->orderPlan->expiresAt->format(DATE_ATOM));
        self::assertSame('2026-08-10T12:01:15+00:00', $outcome->orderPlan->cancelAfterAt?->format(DATE_ATOM));
        self::assertSame('2026-08-10T14:00:00+00:00', $outcome->orderPlan->holdingExpiresAt?->format(DATE_ATOM));
        self::assertCount(1, $outcome->orderPlan->targets);
        self::assertSame(1.8, $outcome->orderPlan->targets[0]->riskMultiple);
        self::assertGreaterThanOrEqual(1.3, $outcome->orderPlan->targets[0]->netR);
        self::assertSame($outcome->orderPlan->expiresAt, $outcome->reservation->entryExpiresAt);
        self::assertSame($outcome->orderPlan->cancelAfterAt, $outcome->reservation->cancelAfterAt);
        self::assertSame($outcome->orderPlan->holdingExpiresAt, $outcome->reservation->holdingExpiresAt);
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
        $null = $request->indicatorsByTimeframe;
        $null['1m']['kline_time'] = null;
        $malformed = $request->indicatorsByTimeframe;
        $malformed['1m']['kline_time'] = 'not-an-instant';
        $stale = $request->indicatorsByTimeframe;
        $stale['1m']['kline_time'] = '2026-08-10T11:55:00Z';

        foreach ([
            [$request->withIndicators($missing), 'scalping_shadow_critical_timeframe_missing'],
            [$request->withIndicators($null), 'scalping_shadow_critical_timeframe_missing'],
            [$request->withIndicators($malformed), 'scalping_shadow_critical_timeframe_missing'],
            [$request->withIndicators($stale), 'scalping_shadow_critical_timeframe_stale'],
        ] as [$candidate, $reason]) {
            $outcome = self::fixtureRuntime()->run($candidate);
            self::assertSame('no_trade', $outcome->status);
            self::assertSame($reason, $outcome->reasonCode);
            self::assertNull($outcome->orderPlan);
            self::assertNull($outcome->reservation);
        }
    }

    public function testOutcomeRejectsEveryIncompleteOrContradictoryShape(): void
    {
        $planned = self::fixtureRuntime()->run(self::fixtureRequest('scalping.trend_continuation.long', 'long'));
        self::assertNotNull($planned->orderPlan);
        self::assertNotNull($planned->reservation);

        foreach ([
            ['invalid', null, null, 'scalping_shadow_status_invalid'],
            ['planned', null, null, 'scalping_shadow_outcome_shape_invalid'],
            ['planned', $planned->orderPlan, null, 'scalping_shadow_outcome_shape_invalid'],
            ['planned', null, $planned->reservation, 'scalping_shadow_outcome_shape_invalid'],
            ['no_trade', $planned->orderPlan, null, 'scalping_shadow_outcome_shape_invalid'],
            ['no_trade', null, $planned->reservation, 'scalping_shadow_outcome_shape_invalid'],
            ['no_trade', $planned->orderPlan, $planned->reservation, 'scalping_shadow_outcome_shape_invalid'],
        ] as [$status, $plan, $reservation, $message]) {
            try {
                new ScalpingShadowOutcome($status, 'reason', $planned->lineage, $plan, $reservation, []);
                self::fail('Invalid scalping outcome shape was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
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

    public function testPortfolioBoundariesRejectBeforeAReservationIsPublished(): void
    {
        $request = self::fixtureRequest('scalping.trend_continuation.long', 'long');
        $scope = $request->portfolioScope;
        $snapshot = static fn (
            float $realizedNetPnlQuote = 0.0,
            int $openPositions = 0,
            int $pendingEntries = 0,
            float $openNotionalQuote = 0.0,
            float $pendingNotionalQuote = 0.0,
        ): CanonicalPortfolioSnapshot => new CanonicalPortfolioSnapshot(
            $scope,
            'scalping_boundary_test',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            $realizedNetPnlQuote,
            0.0,
            $openPositions,
            $pendingEntries,
            $openNotionalQuote,
            $pendingNotionalQuote,
            0.0,
            [],
            1,
            'sha256:' . str_repeat('9', 64),
        );

        foreach ([
            'fourth position includes pending' => [
                $snapshot(openPositions: 2, pendingEntries: 1),
                'canonical_portfolio_concurrency_exceeded',
            ],
            'exposure above 75 percent' => [
                $snapshot(openNotionalQuote: 750.0),
                'canonical_portfolio_mode_exposure_exceeded',
            ],
            'absolute daily loss cap' => [
                $snapshot(realizedNetPnlQuote: -40.0),
                'canonical_portfolio_daily_loss_exceeded',
            ],
        ] as [$portfolio, $reason]) {
            $outcome = self::fixtureRuntime()->run(self::withPortfolioSnapshot($request, $portfolio));

            self::assertSame('no_trade', $outcome->status);
            self::assertSame($reason, $outcome->reasonCode);
            self::assertNull($outcome->orderPlan);
            self::assertNull($outcome->reservation);
        }
    }

    #[DataProvider('executionCapabilities')]
    public function testMarketPlanIsRejectedBeforePortfolioReservation(
        ShadowExecutionCapability $capability,
    ): void {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $request = self::fixtureRequest(
            'scalping.trend_continuation.long',
            'long',
            capability: $capability,
        );
        $limitPlan = (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build($request->orderPlanRequest);
        $serialized = serialize($limitPlan);
        $marketPlan = unserialize(
            str_replace('s:5:"limit";', 's:6:"market";', $serialized),
            ['allowed_classes' => true],
        );
        self::assertInstanceOf(CanonicalOrderPlan::class, $marketPlan);
        self::assertSame('market', $marketPlan->orderType);
        $builder = new class($marketPlan) implements CanonicalOrderPlanBuilderInterface {
            public function __construct(private readonly CanonicalOrderPlan $plan) {}
            public function build(CanonicalOrderPlanBuildRequest $request): CanonicalOrderPlan { return $this->plan; }
        };
        $stores = [
            'fake' => new InMemoryCanonicalPortfolioReservationStore(),
            'paper' => new InMemoryCanonicalPortfolioReservationStore(),
            'backtest' => new InMemoryCanonicalPortfolioReservationStore(),
        ];
        $selector = new CanonicalPortfolioAdapterSelector(
            new FakeCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), $stores['fake']),
            new PaperCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), $stores['paper']),
            new BacktestCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), $stores['backtest']),
        );
        $runtime = new CanonicalShadowRuntime(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime(self::passingConditions()),
            new CanonicalExecutionPolicyCompiler(),
            $builder,
            $selector,
            $clock,
        );
        $sharedRequest = new ShadowRuntimeRequest(
            $request->configRequest,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
        );
        $identity = new ShadowRuntimeIdentityPolicy('scalping_shadow', [[
            'mode_id' => 'scalping',
            'mode_version' => '1.1.0',
            'setup_id' => 'scalping.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
        ]]);

        $outcome = $runtime->run($sharedRequest, $identity);

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('scalping_shadow_non_limit_plan_forbidden', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
        $selectedStore = $stores[$capability->value];
        self::assertSame(1, $selectedStore->scopeVersion($request->portfolioScope));
        self::assertNull($selectedStore->plan($request->portfolioScope, $request->decisionKey));
    }

    /** @return iterable<string, array{string, string}> */
    public static function identities(): iterable
    {
        yield 'trend continuation long' => ['scalping.trend_continuation.long', 'long'];
        yield 'pullback long' => ['scalping.pullback.long', 'long'];
        yield 'trend momentum short' => ['scalping.trend_momentum.short', 'short'];
    }

    /** @return iterable<string, array{ShadowExecutionCapability}> */
    public static function executionCapabilities(): iterable
    {
        yield 'fake' => [ShadowExecutionCapability::Fake];
        yield 'paper' => [ShadowExecutionCapability::Paper];
        yield 'backtest' => [ShadowExecutionCapability::Backtest];
    }

    /** @param list<string> $failingConditionTimeframes */
    public static function fixtureRuntime(
        array $failingConditionTimeframes = [],
        ?CanonicalPortfolioAdapterSelector $adapters = null,
    ): ScalpingShadowRuntime
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return new ScalpingShadowRuntime(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime(self::passingConditions($failingConditionTimeframes)),
            new CanonicalExecutionPolicyCompiler(),
            new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)),
            $adapters ?? DayTradingShadowRuntimeTest::fixtureSelector(),
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

    private static function withPortfolioSnapshot(
        ScalpingShadowRequest $request,
        CanonicalPortfolioSnapshot $portfolioSnapshot,
    ): ScalpingShadowRequest {
        return new ScalpingShadowRequest(
            $request->configRequest,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $portfolioSnapshot,
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
