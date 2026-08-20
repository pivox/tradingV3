<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\MicroScalping;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\Indicator\Condition\OrderFlowImbalanceGteCondition;
use App\Indicator\Condition\OrderFlowImbalanceLteCondition;
use App\Indicator\Condition\SpreadBpsLteCondition;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Tests\TradingCore\DayTrading\DayTradingShadowRuntimeTest;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\MicroScalping\MicroScalpingShadowOutcome;
use App\TradingCore\MicroScalping\MicroScalpingShadowRequest;
use App\TradingCore\MicroScalping\MicroScalpingShadowRuntime;
use App\TradingCore\Microstructure\CanonicalMicrostructureEngine;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\CanonicalMicrostructureRuntimeInputResolver;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshot;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshotProviderInterface;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MicroScalpingShadowRuntime::class)]
final class MicroScalpingShadowRuntimeTest extends TestCase
{
    #[DataProvider('identities')]
    public function testEachExactIdentityPlansOnlyFromTheSameAuthenticatedBook(
        string $setupId,
        string $side,
    ): void {
        $request = self::request($setupId, $side);
        $snapshot = self::microstructure($request->orderBook, $side);

        $outcome = self::runtime($snapshot)->run($request);

        self::assertSame('planned', $outcome->status, $outcome->reasonCode);
        self::assertSame('micro_scalping_shadow_planned', $outcome->reasonCode);
        self::assertNotNull($outcome->orderPlan);
        self::assertNotNull($outcome->reservation);
        self::assertSame($setupId, $outcome->orderPlan->setupId);
        self::assertSame($side, $outcome->orderPlan->side);
        self::assertSame(0.004, $outcome->orderPlan->riskRate);
        self::assertSame(2.0, $outcome->orderPlan->modeLeverageCap);
        self::assertSame(10.0, $outcome->orderPlan->exchangeMaxNotional);
        self::assertSame('2026-08-10T12:00:30+00:00', $outcome->orderPlan->expiresAt->format(DATE_ATOM));
        self::assertSame('2026-08-10T12:00:30+00:00', $outcome->orderPlan->cancelAfterAt?->format(DATE_ATOM));
        self::assertSame('2026-08-10T12:30:00+00:00', $outcome->orderPlan->holdingExpiresAt?->format(DATE_ATOM));
        self::assertSame($snapshot->inputHash, $outcome->evidence['rules']['microstructure_input']['input_hash']);
        self::assertSame('1.2.0', $outcome->evidence['rules']['catalog_version']);
    }

    public function testAuthenticatedSpreadAndExecutionBookCannotDiverge(): void
    {
        $request = self::request('micro_scalping.momentum_ofi.long', 'long');
        $snapshot = self::microstructure($request->orderBook, 'long');
        $mismatch = new MicroScalpingShadowRequest(
            $request->configRequest,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            2.0,
            $request->estimatedSlippageBps,
            $request->orderBook,
        );

        $outcome = self::runtime($snapshot)->run($mismatch);

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('micro_scalping_shadow_microstructure_spread_mismatch', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    public function testExecutionBookMustReferenceTheAuthenticatedSourceRecord(): void
    {
        $request = self::request('micro_scalping.momentum_ofi.long', 'long');
        $snapshot = self::microstructure($request->orderBook, 'long');
        $book = $request->orderBook;
        self::assertNotNull($book);
        $foreignRecord = new CanonicalOrderBookSnapshot(
            $book->exchange,
            $book->environment,
            $book->symbol,
            $book->marketType,
            $book->source,
            $book->bestBid,
            $book->bestAsk,
            $book->spreadBps,
            $book->observedAt,
            'sha256:' . str_repeat('b', 64),
        );
        $mismatch = new MicroScalpingShadowRequest(
            $request->configRequest,
            $request->lineage,
            $request->indicatorsByTimeframe,
            $request->orderPlanRequest,
            $request->portfolioScope,
            $request->portfolioSnapshot,
            $request->decisionKey,
            $request->liveSpreadBps,
            $request->estimatedSlippageBps,
            $foreignRecord,
        );

        $outcome = self::runtime($snapshot)->run($mismatch);

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('micro_scalping_shadow_microstructure_book_record_mismatch', $outcome->reasonCode);
        self::assertNull($outcome->orderPlan);
        self::assertNull($outcome->reservation);
    }

    public function testOutcomeRejectsEveryIncompleteOrContradictoryShape(): void
    {
        $request = self::request('micro_scalping.momentum_ofi.long', 'long');
        $planned = self::runtime(self::microstructure($request->orderBook, 'long'))->run($request);
        self::assertNotNull($planned->orderPlan);
        self::assertNotNull($planned->reservation);

        foreach ([
            ['invalid', null, null, 'micro_scalping_shadow_status_invalid'],
            ['planned', null, null, 'micro_scalping_shadow_outcome_shape_invalid'],
            ['planned', $planned->orderPlan, null, 'micro_scalping_shadow_outcome_shape_invalid'],
            ['planned', null, $planned->reservation, 'micro_scalping_shadow_outcome_shape_invalid'],
            ['no_trade', $planned->orderPlan, null, 'micro_scalping_shadow_outcome_shape_invalid'],
            ['no_trade', null, $planned->reservation, 'micro_scalping_shadow_outcome_shape_invalid'],
            ['no_trade', $planned->orderPlan, $planned->reservation, 'micro_scalping_shadow_outcome_shape_invalid'],
        ] as [$status, $plan, $reservation, $message]) {
            try {
                new MicroScalpingShadowOutcome($status, 'reason', $planned->lineage, $plan, $reservation, []);
                self::fail('Invalid micro-scalping outcome shape was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function identities(): iterable
    {
        yield 'long' => ['micro_scalping.momentum_ofi.long', 'long'];
        yield 'short' => ['micro_scalping.momentum_ofi.short', 'short'];
    }

    private static function runtime(CanonicalMicrostructureSnapshot $snapshot): MicroScalpingShadowRuntime
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $provider = new class($snapshot) implements CanonicalMicrostructureSnapshotProviderInterface {
            public function __construct(private readonly CanonicalMicrostructureSnapshot $snapshot) {}
            public function snapshotFor(LineageContext $identity, \DateTimeImmutable $evaluatedAt): ?CanonicalMicrostructureSnapshot
            {
                return $this->snapshot;
            }
        };

        return new MicroScalpingShadowRuntime(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime(
                self::conditions(),
                microstructureInputs: new CanonicalMicrostructureRuntimeInputResolver($provider),
            ),
            new CanonicalExecutionPolicyCompiler(),
            new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)),
            DayTradingShadowRuntimeTest::fixtureSelector(),
            $clock,
        );
    }

    private static function request(string $setupId, string $side): MicroScalpingShadowRequest
    {
        $config = new EffectiveTradingConfigRequest(
            'micro_scalping', '1.1.0', $setupId, '1.1.0',
            'okx', 'mainnet', $side, ShadowExecutionCapability::Paper,
        );
        $snapshot = (new EffectiveTradingConfigResolver())->resolve($config);
        $snapshotData = $snapshot->toArray();
        $decisionKey = 'decision-micro-' . $side;
        $lineage = LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-micro-shadow',
            'orchestration_set_id' => 'set-micro-shadow',
            'mode_id' => 'micro_scalping',
            'mode_version' => '1.1.0',
            'setup_id' => $setupId,
            'setup_version' => '1.1.0',
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => strtoupper($side),
            'exchange' => 'okx',
            'environment' => 'mainnet',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => $decisionKey,
            'dry_run' => true,
            'effective_config_reference' => 'effective-config-snapshot:' . $snapshotData['snapshot_hash'],
            'effective_config_snapshot' => $snapshotData,
        ]);
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
        $components = CanonicalOrderPlanPipelineFixture::accepted(
            side: $side,
            costObservedAt: '2026-08-10T11:59:58+00:00',
            instrumentObservedAt: '2026-08-10T11:59:58+00:00',
            executionPolicy: $policy,
            exchange: 'okx',
            environment: 'mainnet',
            marketObservedAt: '2026-08-10T11:59:58+00:00',
        );
        $mid = $components['zone']->entryPrice;
        $halfSpread = $mid / 20_000.0;
        $book = new CanonicalOrderBookSnapshot(
            'okx', 'mainnet', 'BTCUSDT', 'perpetual', 'order_book',
            $mid - $halfSpread, $mid + $halfSpread, 1.0,
            new \DateTimeImmutable('2026-08-10T11:59:59.000000Z'),
            'sha256:' . str_repeat('a', 64),
        );
        $scope = new CanonicalPortfolioScope('shadow', 'okx', 'mainnet', 'account-1', 'micro_scalping', 'USDT');
        $portfolio = new CanonicalPortfolioSnapshot(
            $scope,
            'micro_scalping_test',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00Z'),
            new \DateTimeImmutable('2026-08-11T00:00:00Z'),
            new \DateTimeImmutable('2026-08-10T11:59:59Z'),
            1000.0, 0.0, 0.0, 0, 0, 0.0, 0.0, 0.0, [], 1,
            'sha256:' . str_repeat('8', 64),
        );

        return new MicroScalpingShadowRequest(
            $config,
            $lineage,
            [
                '5m' => self::indicator('5m', '2026-08-10T11:55:00Z', $side),
                '1m' => self::indicator('1m', '2026-08-10T11:59:00Z', $side),
            ],
            new CanonicalOrderPlanBuildRequest(...$components),
            $scope,
            $portfolio,
            $decisionKey,
            1.0,
            1.0,
            $book,
        );
    }

    private static function microstructure(CanonicalOrderBookSnapshot $book, string $side): CanonicalMicrostructureSnapshot
    {
        $checksum = 'sha256:' . str_repeat('f', 64);
        $aggressiveSide = $side === 'long' ? 'buy' : 'sell';
        $oppositeSide = $side === 'long' ? 'sell' : 'buy';

        return (new CanonicalMicrostructureEngine())->build(
            new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3),
            new \DateTimeImmutable('2026-08-10T12:00:00.000000Z'),
            [new NormalizedBacktestPublicBook(
                str_repeat('a', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT',
                '2026-08-10T11:59:59.000000Z', '2026-08-10T11:59:59.000000Z',
                (string) $book->bestBid, '10', (string) $book->bestAsk, '12', 'contracts', '2', '3', 'ws_books',
            )],
            [
                self::trade('1', '2026-08-10T11:59:10.000000Z', $aggressiveSide, '3', $checksum),
                self::trade('2', '2026-08-10T11:59:30.000000Z', $oppositeSide, '1', $checksum),
                self::trade('3', '2026-08-10T11:59:55.000000Z', $aggressiveSide, '2', $checksum),
            ],
        );
    }

    private static function trade(string $id, string $time, string $side, string $quantity, string $checksum): NormalizedBacktestPublicTrade
    {
        return new NormalizedBacktestPublicTrade(
            str_repeat($id, 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', $id,
            $time, $time, $side, '100', $quantity, 'contracts',
        );
    }

    /** @return array<string, mixed> */
    private static function indicator(string $timeframe, string $klineTime, string $side): array
    {
        $step = $timeframe === '5m' ? 300 : 60;
        $current = (new \DateTimeImmutable($klineTime))->getTimestamp();

        return [
            'snapshot_identity' => [
                'timeframe' => $timeframe,
                'symbol' => 'BTCUSDT',
                'exchange' => 'okx',
                'environment' => 'mainnet',
                'market_type' => 'perpetual',
            ],
            'kline_time' => $klineTime,
            'series_order' => 'oldest_to_newest',
            'macd_hist_series' => $side === 'long' ? [0.1, 0.2] : [-0.1, -0.2],
            'macd_hist_series_timestamps' => [$current - $step, $current],
        ];
    }

    /** @return list<ConditionInterface> */
    private static function conditions(): array
    {
        $ids = (new ConditionCatalogLoader())->loadVersion('1.2.0')->conditionIds();

        return array_map(static fn (string $id): ConditionInterface => match ($id) {
            SpreadBpsLteCondition::NAME => new SpreadBpsLteCondition(),
            OrderFlowImbalanceGteCondition::NAME => new OrderFlowImbalanceGteCondition(),
            OrderFlowImbalanceLteCondition::NAME => new OrderFlowImbalanceLteCondition(),
            default => new class($id) implements ConditionInterface {
                public function __construct(private readonly string $id) {}
                public function getName(): string { return $this->id; }
                /** @param array<string, mixed> $context */
                public function evaluate(array $context): ConditionResult { return new ConditionResult($this->id, true); }
            },
        }, $ids);
    }
}
