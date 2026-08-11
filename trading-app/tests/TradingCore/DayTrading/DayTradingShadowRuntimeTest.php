<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\DayTrading;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\DayTrading\DayTradingShadowRequest;
use App\TradingCore\DayTrading\DayTradingShadowRuntime;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DayTradingShadowRuntime::class)]
final class DayTradingShadowRuntimeTest extends TestCase
{
    public function testValidLongBuildsAndReservesCanonicalPlan(): void
    {
        $outcome = self::fixtureRuntime()->run(self::fixtureRequest());

        self::assertSame('planned', $outcome->status);
        self::assertSame('day_trading_shadow_planned', $outcome->reasonCode);
        self::assertNotNull($outcome->orderPlan);
        self::assertNotNull($outcome->reservation);
        self::assertSame('active', $outcome->reservation->status);
        self::assertSame($outcome->lineage->configHash, $outcome->orderPlan->configHash);
        self::assertSame($outcome->orderPlan->planHash, $outcome->reservation->planHash);
        self::assertSame('2026-08-10T12:01:30+00:00', $outcome->orderPlan->expiresAt->format(DATE_ATOM));
        self::assertSame('2026-08-10T12:02:00+00:00', $outcome->orderPlan->cancelAfterAt?->format(DATE_ATOM));
        self::assertSame($outcome->orderPlan->expiresAt, $outcome->reservation->entryExpiresAt);
        self::assertSame($outcome->orderPlan->cancelAfterAt, $outcome->reservation->cancelAfterAt);
        self::assertSame('2026-08-10T20:00:00+00:00', $outcome->orderPlan->holdingExpiresAt?->format(DATE_ATOM));
        self::assertSame($outcome->orderPlan->holdingExpiresAt, $outcome->reservation->holdingExpiresAt);
        self::assertSame('2026-08-10T12:01:30+00:00', $outcome->evidence['entry_expires_at']);
        self::assertSame('2026-08-10T12:02:00+00:00', $outcome->evidence['cancel_after_at']);
        self::assertSame('2026-08-10T20:00:00+00:00', $outcome->evidence['holding_expires_at']);
        self::assertSame([
            'config_hash',
            'plan_hash',
            'reservation_hash',
            'entry_expires_at',
            'cancel_after_at',
            'holding_expires_at',
            'rules',
        ], array_keys($outcome->evidence));
        self::assertSame($outcome->lineage->configHash, $outcome->evidence['config_hash']);
        self::assertSame($outcome->orderPlan->planHash, $outcome->evidence['plan_hash']);
        self::assertSame($outcome->reservation->stateHash, $outcome->evidence['reservation_hash']);
        self::assertSame('sha256:b3976b712e505ddc129e0139eeaa43817f0879aa8933dbae81e948e249a3bc68', $outcome->evidence['config_hash']);
        self::assertSame('sha256:d3c6fa5352473511a16b7dd7992a17705319286d828298c72588ce9159f1f6b0', $outcome->evidence['plan_hash']);
        self::assertSame('sha256:fbccd332bc743ea8f2d7ef0663f58211177b911df9475e23a181a1000eb60d35', $outcome->evidence['reservation_hash']);
        self::assertSame('sha256:351f0e9361725441f3e2cd1b0a3f36a3c492dbb19fe8ee2c2b2eebad785f8361', $outcome->reservation->admissionHash);
    }

    public function testUnsupportedIdentityAndForbiddenCapabilitiesKeepExactFacadeReasons(): void
    {
        $request = self::fixtureRequest();
        $unsupported = new DayTradingShadowRequest(
            new EffectiveTradingConfigRequest(
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'fake',
                'test',
                'short',
                ShadowExecutionCapability::Fake,
            ),
            $request->lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
        );
        $missingCapability = self::withCapability($request, null);
        $privateMainnet = self::withCapability($request, ShadowExecutionCapability::PrivateMainnet);

        self::assertSame('day_trading_shadow_identity_unsupported', self::fixtureRuntime()->run($unsupported)->reasonCode);
        self::assertSame('day_trading_shadow_capability_forbidden', self::fixtureRuntime()->run($missingCapability)->reasonCode);
        self::assertSame('day_trading_shadow_capability_forbidden', self::fixtureRuntime()->run($privateMainnet)->reasonCode);
    }

    public function testNoTradeEvidenceShapeRemainsStable(): void
    {
        $outcome = self::fixtureRuntime()->run(self::fixtureRequest(liveSpreadBps: 6.01));

        self::assertSame([
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'LONG',
            'config_hash' => $outcome->lineage->configHash,
        ], $outcome->evidence);
    }

    public function testHoldingDeadlineCreatesEnforceableCloseAction(): void
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $adapter = new FakeCanonicalPortfolioAdapter(
            new CanonicalPortfolioAdmissionEngine($clock),
            new InMemoryCanonicalPortfolioReservationStore(),
        );
        $outcome = self::fixtureRuntime(self::fixtureSelector($adapter))->run(self::fixtureRequest());
        self::assertNotNull($outcome->orderPlan);
        self::assertNotNull($outcome->reservation);
        self::assertTrue(method_exists($adapter, 'enforceHoldingDeadline'));
        $plan = $outcome->orderPlan;
        $reservation = $adapter->applyFill($outcome->reservation, new CanonicalPortfolioFill(
            $outcome->reservation->scope,
            $outcome->reservation->decisionKey,
            $outcome->reservation->planHash,
            $outcome->reservation->admissionHash,
            'entry-fill',
            $plan->quantity,
            $plan->entryPrice,
            $plan->entryFee,
            $plan->quantity,
            0.0,
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('7', 64),
        ));

        $expired = $adapter->enforceHoldingDeadline(
            $reservation,
            new \DateTimeImmutable('2026-08-10T20:00:00+00:00'),
            'sha256:' . str_repeat('6', 64),
        );

        self::assertSame('holding_expired', $expired->status);
        self::assertSame('close_position', $expired->requiredAction);
    }

    public function testReservationRejectsFillAfterOrderTtl(): void
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $adapter = new FakeCanonicalPortfolioAdapter(
            new CanonicalPortfolioAdmissionEngine($clock),
            new InMemoryCanonicalPortfolioReservationStore(),
        );
        $outcome = self::fixtureRuntime(self::fixtureSelector($adapter))->run(self::fixtureRequest());
        self::assertNotNull($outcome->orderPlan);
        self::assertNotNull($outcome->reservation);
        $plan = $outcome->orderPlan;
        $reservation = $outcome->reservation;

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_entry_expired');
        $adapter->applyFill($reservation, new CanonicalPortfolioFill(
            $reservation->scope,
            $reservation->decisionKey,
            $reservation->planHash,
            $reservation->admissionHash,
            'late-fill',
            $plan->quantity,
            $plan->entryPrice,
            $plan->entryFee,
            $plan->quantity,
            0.0,
            new \DateTimeImmutable('2026-08-10T12:01:31+00:00'),
            'sha256:' . str_repeat('9', 64),
        ));
    }

    public function testLineageMustMatchPlanMarketAndDecisionIdentity(): void
    {
        foreach ([
            ['symbol' => 'ETHUSDT'],
            ['market_type' => 'spot'],
            ['decision_key' => 'different-decision'],
        ] as $mutation) {
            $request = self::fixtureRequest();
            $lineage = LineageContext::fromArray(array_replace($request->lineage->toArray(), $mutation));
            $mismatched = new DayTradingShadowRequest(
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

            $outcome = self::fixtureRuntime()->run($mismatched);
            self::assertSame('no_trade', $outcome->status);
            self::assertSame('day_trading_shadow_lineage_mismatch', $outcome->reasonCode);
            self::assertNull($outcome->reservation);
        }
    }

    public function testRuleRejectionNeverCreatesAReservation(): void
    {
        $request = self::fixtureRequest();
        $inputs = $request->indicatorsByTimeframe;
        unset($inputs['1m']);

        $outcome = self::fixtureRuntime()->run($request->withIndicators($inputs));

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('critical_timeframe_missing', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    public function testExcessiveLiveSpreadIsFailClosedBeforePlanCreation(): void
    {
        $outcome = self::fixtureRuntime()->run(self::fixtureRequest(liveSpreadBps: 6.01));

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('day_trading_live_spread_exceeded', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    public function testUnavailableOrExcessiveSlippageIsFailClosed(): void
    {
        $spreadUnavailable = self::fixtureRuntime()->run(self::fixtureRequest(liveSpreadBps: null));
        $unavailable = self::fixtureRuntime()->run(self::fixtureRequest(estimatedSlippageBps: null));
        $excessive = self::fixtureRuntime()->run(self::fixtureRequest(estimatedSlippageBps: 8.01));

        self::assertSame('day_trading_live_spread_unavailable', $spreadUnavailable->reasonCode);
        self::assertSame('day_trading_slippage_unavailable', $unavailable->reasonCode);
        self::assertSame('day_trading_slippage_exceeded', $excessive->reasonCode);
        self::assertNull($spreadUnavailable->reservation);
        self::assertNull($unavailable->reservation);
        self::assertNull($excessive->reservation);
    }

    public function testLiveGuardAndNetCostSnapshotMustDescribeTheSameCosts(): void
    {
        $outcome = self::fixtureRuntime()->run(self::fixtureRequest(liveSpreadBps: 1.0001));

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('day_trading_live_cost_snapshot_mismatch', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    public function testDailyLossConcurrencyAndExposureRejectionsNeverReserve(): void
    {
        $dailyLoss = self::fixtureRuntime()->run(self::fixtureRequest(realizedNetPnlQuote: -30.0));
        $concurrency = self::fixtureRuntime()->run(self::fixtureRequest(openPositions: 4));
        $exposure = self::fixtureRuntime()->run(self::fixtureRequest(openNotionalQuote: 1000.0));

        self::assertSame('canonical_portfolio_daily_loss_exceeded', $dailyLoss->reasonCode);
        self::assertSame('canonical_portfolio_concurrency_exceeded', $concurrency->reasonCode);
        self::assertSame('canonical_portfolio_mode_exposure_exceeded', $exposure->reasonCode);
        self::assertNull($dailyLoss->reservation);
        self::assertNull($concurrency->reservation);
        self::assertNull($exposure->reservation);
    }

    public static function fixtureRuntime(?CanonicalPortfolioAdapterSelector $adapters = null): DayTradingShadowRuntime
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return new DayTradingShadowRuntime(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime(self::passingConditions()),
            new CanonicalExecutionPolicyCompiler(),
            new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)),
            $adapters ?? self::fixtureSelector(),
            $clock,
        );
    }

    public static function fixtureSelector(?FakeCanonicalPortfolioAdapter $fake = null): CanonicalPortfolioAdapterSelector
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return new CanonicalPortfolioAdapterSelector(
            $fake ?? new FakeCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), new InMemoryCanonicalPortfolioReservationStore()),
            new PaperCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), new InMemoryCanonicalPortfolioReservationStore()),
            new BacktestCanonicalPortfolioAdapter(new CanonicalPortfolioAdmissionEngine($clock), new InMemoryCanonicalPortfolioReservationStore()),
        );
    }

    public static function fixtureRequest(
        ?float $liveSpreadBps = 1.0,
        ?float $estimatedSlippageBps = 1.0,
        float $realizedNetPnlQuote = 0.0,
        int $openPositions = 0,
        float $openNotionalQuote = 0.0,
        ShadowExecutionCapability $capability = ShadowExecutionCapability::Fake,
    ): DayTradingShadowRequest
    {
        $configRequest = new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', $capability,
        );
        $snapshot = (new EffectiveTradingConfigResolver())->resolve($configRequest);
        $lineage = LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-day-trading-shadow',
            'orchestration_set_id' => 'set-day-trading-shadow',
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => 'LONG',
            'exchange' => 'fake',
            'environment' => 'test',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => 'decision-day-trading-shadow',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:day-trading-shadow',
            'effective_config_snapshot' => $snapshot->toArray(),
        ]);
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
        $components = CanonicalOrderPlanPipelineFixture::accepted(executionPolicy: $policy);
        $scope = new CanonicalPortfolioScope('shadow', 'fake', 'test', 'account-1', 'day_trading', 'USDT');
        $portfolio = new CanonicalPortfolioSnapshot(
            $scope,
            'day_trading_test',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            $realizedNetPnlQuote,
            0.0,
            $openPositions,
            0,
            $openNotionalQuote,
            0.0,
            0.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );

        return new DayTradingShadowRequest(
            $configRequest,
            $lineage,
            [
                '4h' => self::indicatorInput('4h', '2026-08-10T08:00:00Z'),
                '1h' => self::indicatorInput('1h', '2026-08-10T11:00:00Z', ['adx' => 25.0]),
                '15m' => self::indicatorInput('15m', '2026-08-10T11:45:00Z'),
                '5m' => self::indicatorInput('5m', '2026-08-10T11:55:00Z'),
                '1m' => self::indicatorInput('1m', '2026-08-10T11:59:00Z'),
            ],
            new CanonicalOrderPlanBuildRequest(...$components),
            $scope,
            $portfolio,
            'decision-day-trading-shadow',
            $liveSpreadBps,
            $estimatedSlippageBps,
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function indicatorInput(string $timeframe, string $klineTime, array $extra = []): array
    {
        $step = match ($timeframe) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            '4h' => 14400,
            default => throw new \InvalidArgumentException('Unsupported test timeframe.'),
        };
        $current = (new \DateTimeImmutable($klineTime))->getTimestamp();
        $timestamps = [$current - $step, $current];

        return array_replace([
            'kline_time' => $klineTime,
            'series_order' => 'oldest_to_newest',
            'ema_200_series' => [100.0, 101.0],
            'ema_200_series_timestamps' => $timestamps,
            'macd_hist_series' => [0.1, 0.2],
            'macd_hist_series_timestamps' => $timestamps,
            'macd_line_signal_series' => [-0.1, 0.1],
            'macd_line_signal_series_timestamps' => $timestamps,
        ], $extra);
    }

    private static function withCapability(
        DayTradingShadowRequest $request,
        ?ShadowExecutionCapability $capability,
    ): DayTradingShadowRequest {
        $config = $request->configRequest;

        return new DayTradingShadowRequest(
            new EffectiveTradingConfigRequest(
                $config->modeId,
                $config->modeVersion,
                $config->setupId,
                $config->setupVersion,
                $config->exchange,
                $config->environment,
                $config->side,
                $capability,
            ),
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

    /** @return list<ConditionInterface> */
    private static function passingConditions(): array
    {
        $ids = (new \App\TradingCore\Rules\Catalog\ConditionCatalogLoader())->loadFile(
            dirname(__DIR__, 3) . '/config/trading/condition_catalog/1.0.0.yaml',
        )->conditionIds();

        return array_map(static fn (string $id): ConditionInterface => new class($id) implements ConditionInterface {
            public function __construct(private readonly string $id) {}
            public function getName(): string { return $this->id; }
            /** @param array<string, mixed> $context */
            public function evaluate(array $context): ConditionResult { return new ConditionResult($this->id, true); }
        }, $ids);
    }
}
