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
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterInterface;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
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
        self::assertSame('2026-08-10T20:00:00+00:00', $outcome->evidence['holding_expires_at']);
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
        $unavailable = self::fixtureRuntime()->run(self::fixtureRequest(estimatedSlippageBps: null));
        $excessive = self::fixtureRuntime()->run(self::fixtureRequest(estimatedSlippageBps: 8.01));

        self::assertSame('day_trading_slippage_unavailable', $unavailable->reasonCode);
        self::assertSame('day_trading_slippage_exceeded', $excessive->reasonCode);
        self::assertNull($unavailable->reservation);
        self::assertNull($excessive->reservation);
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

    public static function fixtureRuntime(?CanonicalPortfolioAdapterInterface $adapter = null): DayTradingShadowRuntime
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return new DayTradingShadowRuntime(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime(self::passingConditions()),
            new CanonicalExecutionPolicyCompiler(),
            new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)),
            $adapter ?? new FakeCanonicalPortfolioAdapter(
                new CanonicalPortfolioAdmissionEngine($clock),
                new InMemoryCanonicalPortfolioReservationStore(),
            ),
            $clock,
        );
    }

    public static function fixtureRequest(
        float $liveSpreadBps = 1.0,
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
                '4h' => ['kline_time' => '2026-08-10T08:00:00Z'],
                '1h' => ['kline_time' => '2026-08-10T11:00:00Z', 'adx' => 25.0],
                '15m' => ['kline_time' => '2026-08-10T11:45:00Z'],
                '5m' => ['kline_time' => '2026-08-10T11:55:00Z'],
                '1m' => ['kline_time' => '2026-08-10T11:59:00Z'],
            ],
            new CanonicalOrderPlanBuildRequest(...$components),
            $scope,
            $portfolio,
            'decision-day-trading-shadow',
            $liveSpreadBps,
            $estimatedSlippageBps,
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
