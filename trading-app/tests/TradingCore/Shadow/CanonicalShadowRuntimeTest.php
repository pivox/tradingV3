<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Shadow;

use App\Indicator\Condition\ConditionInterface;
use App\Indicator\Condition\ConditionResult;
use App\MtfValidator\Policy\CanonicalSetupRuleRuntime;
use App\Tests\TradingCore\DayTrading\DayTradingShadowRuntimeTest;
use App\Trading\Lineage\LineageContext;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\Shadow\CanonicalShadowRuntime;
use App\TradingCore\Shadow\ShadowRuntimeIdentityPolicy;
use App\TradingCore\Shadow\ShadowRuntimeOutcome;
use App\TradingCore\Shadow\ShadowRuntimeRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalShadowRuntime::class)]
#[CoversClass(ShadowRuntimeIdentityPolicy::class)]
#[CoversClass(ShadowRuntimeOutcome::class)]
#[CoversClass(ShadowRuntimeRequest::class)]
final class CanonicalShadowRuntimeTest extends TestCase
{
    public function testCanonicalRuntimeProducesTheSamePlannedProofForEveryShadowAdapter(): void
    {
        $normalized = [];
        foreach ([ShadowExecutionCapability::Fake, ShadowExecutionCapability::Paper, ShadowExecutionCapability::Backtest] as $capability) {
            $request = DayTradingShadowRuntimeTest::fixtureRequest(capability: $capability);
            $outcome = self::runtime()->run(self::shared($request), self::policy());

            self::assertSame('planned', $outcome->status);
            self::assertSame('day_trading_shadow_planned', $outcome->reasonCode);
            self::assertNotNull($outcome->orderPlan);
            self::assertNotNull($outcome->reservation);
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
            $normalized[] = [
                $outcome->status,
                $outcome->reasonCode,
                $outcome->evidence,
                $outcome->orderPlan->planHash,
                $outcome->reservation->admissionHash,
            ];
        }

        self::assertCount(1, array_unique(array_map(
            static fn (array $proof): string => json_encode($proof, JSON_THROW_ON_ERROR),
            $normalized,
        )));
    }

    public function testIdentityCapabilityAndCompleteLineageRejectBeforeReservation(): void
    {
        $day = DayTradingShadowRuntimeTest::fixtureRequest();
        $unsupported = self::withConfig($day, new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'short', ShadowExecutionCapability::Fake,
        ));
        $noCapability = self::withConfig($day, new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', null,
        ));
        $privateMainnet = self::withConfig($day, new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::PrivateMainnet,
        ));
        $lineageMismatch = self::withLineage($day, LineageContext::fromArray(array_replace(
            $day->lineage->toArray(),
            ['symbol' => 'ETHUSDT'],
        )));

        foreach ([
            [$unsupported, 'day_trading_shadow_identity_unsupported'],
            [$noCapability, 'day_trading_shadow_capability_forbidden'],
            [$privateMainnet, 'day_trading_shadow_capability_forbidden'],
            [$lineageMismatch, 'day_trading_shadow_lineage_mismatch'],
        ] as [$request, $reason]) {
            $outcome = self::runtime()->run(self::shared($request), self::policy());
            self::assertSame('no_trade', $outcome->status);
            self::assertSame($reason, $outcome->reasonCode);
            self::assertNull($outcome->orderPlan);
            self::assertNull($outcome->reservation);
        }
    }

    public function testRulesConfigCostsAndAdmissionFailClosedWithOwnedReasons(): void
    {
        $day = DayTradingShadowRuntimeTest::fixtureRequest();
        $missingIndicators = $day->indicatorsByTimeframe;
        unset($missingIndicators['1m']);
        $missingRuleInput = new ShadowRuntimeRequest(
            $day->configRequest,
            $day->lineage,
            $missingIndicators,
            $day->orderPlanRequest,
            $day->portfolioScope,
            $day->portfolioSnapshot,
            $day->decisionKey,
            $day->liveSpreadBps,
            $day->estimatedSlippageBps,
        );

        $foreignConfig = new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'okx', 'demo', 'long', ShadowExecutionCapability::Paper,
        );
        $foreignPolicy = (new CanonicalExecutionPolicyCompiler())->compile(
            (new EffectiveTradingConfigResolver())->resolve($foreignConfig),
        );
        $plan = $day->orderPlanRequest;
        $configMismatch = new ShadowRuntimeRequest(
            $day->configRequest,
            $day->lineage,
            $day->indicatorsByTimeframe,
            new CanonicalOrderPlanBuildRequest(
                $foreignPolicy,
                $plan->zoneRequest,
                $plan->zone,
                $plan->protectionRequest,
                $plan->protection,
                $plan->riskRequest,
                $plan->risk,
                $plan->netR,
                $plan->costs,
            ),
            $day->portfolioScope,
            $day->portfolioSnapshot,
            $day->decisionKey,
            $day->liveSpreadBps,
            $day->estimatedSlippageBps,
        );
        $costMismatchDay = DayTradingShadowRuntimeTest::fixtureRequest(liveSpreadBps: 1.0001);
        $admissionDay = DayTradingShadowRuntimeTest::fixtureRequest(realizedNetPnlQuote: -30.0);

        foreach ([
            [$missingRuleInput, 'day_trading_shadow_critical_timeframe_missing', 'rules'],
            [$configMismatch, 'day_trading_shadow_plan_config_mismatch', null],
            [self::shared($costMismatchDay), 'day_trading_shadow_live_cost_snapshot_mismatch', null],
            [self::shared($admissionDay), 'canonical_portfolio_daily_loss_exceeded', 'domain_evidence'],
        ] as [$request, $reason, $evidenceKey]) {
            $outcome = self::runtime()->run($request, self::policy());
            self::assertSame('no_trade', $outcome->status);
            self::assertSame($reason, $outcome->reasonCode);
            self::assertNull($outcome->reservation);
            if ($evidenceKey !== null) {
                self::assertArrayHasKey($evidenceKey, $outcome->evidence);
            }
        }
    }

    public function testIdentityPolicyAcceptsOnlyAnExactFrozenIdentity(): void
    {
        $policy = new ShadowRuntimeIdentityPolicy('day_trading_shadow', [[
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
        ]]);
        $request = DayTradingShadowRuntimeTest::fixtureRequest()->configRequest;

        self::assertTrue($policy->accepts($request));
        self::assertSame('day_trading_shadow_planned', $policy->reason('planned'));

        foreach (['mode_id', 'mode_version', 'setup_id', 'setup_version', 'side'] as $field) {
            $identity = $request->toArray();
            $identity[$field] = $field === 'side' ? 'short' : 'invalid';
            self::assertFalse($policy->accepts(new \App\TradingCore\Config\EffectiveTradingConfigRequest(
                $field === 'mode_id' ? 'scalping' : $request->modeId,
                $field === 'mode_version' ? '9.9.9' : $request->modeVersion,
                $field === 'setup_id' ? 'scalping.pullback.long' : $request->setupId,
                $field === 'setup_version' ? '9.9.9' : $request->setupVersion,
                $request->exchange,
                $request->environment,
                $field === 'side' ? 'short' : $request->side,
                $request->capability,
            )));
        }
    }

    public function testIdentityPolicyRejectsMalformedOrAmbiguousDefinitions(): void
    {
        $identity = [
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
        ];

        foreach ([
            ['', [$identity], 'shadow_runtime_reason_prefix_invalid'],
            ['day-trading', [$identity], 'shadow_runtime_reason_prefix_invalid'],
            ['day_trading_shadow', [], 'shadow_runtime_identities_empty'],
            ['day_trading_shadow', [array_merge($identity, ['alias' => 'regular'])], 'shadow_runtime_identity_shape_invalid'],
            ['day_trading_shadow', [array_replace($identity, ['side' => 'both'])], 'shadow_runtime_identity_side_invalid'],
            ['day_trading_shadow', [$identity, $identity], 'shadow_runtime_identity_duplicate'],
        ] as [$prefix, $identities, $message]) {
            try {
                new ShadowRuntimeIdentityPolicy($prefix, $identities);
                self::fail('Malformed identity policy was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testSharedRequestCarriesTheNineCanonicalInputsWithoutMutation(): void
    {
        $day = DayTradingShadowRuntimeTest::fixtureRequest();
        $shared = new ShadowRuntimeRequest(
            $day->configRequest,
            $day->lineage,
            $day->indicatorsByTimeframe,
            $day->orderPlanRequest,
            $day->portfolioScope,
            $day->portfolioSnapshot,
            $day->decisionKey,
            $day->liveSpreadBps,
            $day->estimatedSlippageBps,
        );

        self::assertSame($day->configRequest, $shared->configRequest);
        self::assertSame($day->lineage, $shared->lineage);
        self::assertSame($day->indicatorsByTimeframe, $shared->indicatorsByTimeframe);
        self::assertSame($day->orderPlanRequest, $shared->orderPlanRequest);
        self::assertSame($day->portfolioScope, $shared->portfolioScope);
        self::assertSame($day->portfolioSnapshot, $shared->portfolioSnapshot);
        self::assertSame($day->decisionKey, $shared->decisionKey);
        self::assertSame($day->liveSpreadBps, $shared->liveSpreadBps);
        self::assertSame($day->estimatedSlippageBps, $shared->estimatedSlippageBps);
    }

    public function testSharedOutcomePermitsOnlyPlannedAndNoTradeShapes(): void
    {
        $day = DayTradingShadowRuntimeTest::fixtureRuntime()->run(DayTradingShadowRuntimeTest::fixtureRequest());
        $planned = new ShadowRuntimeOutcome(
            'planned',
            'day_trading_shadow_planned',
            $day->lineage,
            $day->orderPlan,
            $day->reservation,
            $day->evidence,
        );
        self::assertSame('planned', $planned->status);

        $noTrade = new ShadowRuntimeOutcome('no_trade', 'rejected', $day->lineage, null, null, []);
        self::assertSame('no_trade', $noTrade->status);

        foreach ([
            ['invalid', null, null, 'shadow_runtime_status_invalid'],
            ['planned', null, null, 'shadow_runtime_outcome_shape_invalid'],
            ['no_trade', $day->orderPlan, $day->reservation, 'shadow_runtime_outcome_shape_invalid'],
            ['no_trade', $day->orderPlan, null, 'shadow_runtime_outcome_shape_invalid'],
        ] as [$status, $plan, $reservation, $message]) {
            try {
                new ShadowRuntimeOutcome($status, 'reason', $day->lineage, $plan, $reservation, []);
                self::fail('Invalid shared outcome was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    private static function runtime(): CanonicalShadowRuntime
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        return new CanonicalShadowRuntime(
            new EffectiveTradingConfigResolver(),
            new CanonicalSetupRuleRuntime(self::passingConditions()),
            new CanonicalExecutionPolicyCompiler(),
            new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)),
            DayTradingShadowRuntimeTest::fixtureSelector(),
            $clock,
        );
    }

    private static function policy(): ShadowRuntimeIdentityPolicy
    {
        return new ShadowRuntimeIdentityPolicy('day_trading_shadow', [[
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
        ]]);
    }

    private static function shared(\App\TradingCore\DayTrading\DayTradingShadowRequest $request): ShadowRuntimeRequest
    {
        return new ShadowRuntimeRequest(
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
    }

    private static function withConfig(
        \App\TradingCore\DayTrading\DayTradingShadowRequest $request,
        EffectiveTradingConfigRequest $config,
    ): \App\TradingCore\DayTrading\DayTradingShadowRequest {
        return new \App\TradingCore\DayTrading\DayTradingShadowRequest(
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

    private static function withLineage(
        \App\TradingCore\DayTrading\DayTradingShadowRequest $request,
        LineageContext $lineage,
    ): \App\TradingCore\DayTrading\DayTradingShadowRequest {
        return new \App\TradingCore\DayTrading\DayTradingShadowRequest(
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
