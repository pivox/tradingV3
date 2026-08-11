<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetRDecision;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetRTargetDecision;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZone;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionDecision;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionTarget;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalInstrumentSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalOrderPlan::class)]
#[CoversClass(CanonicalOrderPlanBuilder::class)]
#[CoversClass(CanonicalOrderPlanValidator::class)]
final class CanonicalOrderPlanBuilderTest extends TestCase
{
    public function testBuildsAndRevalidatesImmutableCanonicalLimitPlan(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $validator = new CanonicalOrderPlanValidator($clock);
        $plan = (new CanonicalOrderPlanBuilder($clock, $validator))->build(new CanonicalOrderPlanBuildRequest(...$components));

        self::assertSame('limit', $plan->orderType);
        self::assertSame('day_trading', $plan->modeId);
        self::assertSame('BTCUSDT', $plan->symbol);
        self::assertSame('USDT', $plan->quoteCurrency);
        self::assertSame('long', $plan->side);
        self::assertSame($components['risk']->quantity, $plan->quantity);
        self::assertSame($components['zone']->entryPrice, $plan->entryPrice);
        self::assertSame($components['protection']->stopPrice, $plan->stopPrice);
        self::assertSame($components['risk']->totalStopLoss, $plan->totalStopLoss);
        self::assertSame($components['risk']->contractSize, $plan->contractSize);
        self::assertSame($components['risk']->grossStopLoss, $plan->grossStopLoss);
        self::assertSame($components['risk']->effectiveLeverageCap, $plan->effectiveLeverageCap);
        self::assertSame($components['netR']->fundingIntervals, $plan->fundingIntervals);
        self::assertCount(2, $plan->targets);
        self::assertGreaterThanOrEqual($plan->minimumNetR, $plan->targets[0]->netR);
        self::assertSame('sha256:', substr($plan->planHash, 0, 7));
        self::assertContains($components['riskRequest']->instrument->inputHash, $plan->inputHashes);
        self::assertSame($plan, $validator->validate($plan));
    }

    public function testBuildsScalpingPlanWithExactModernDeadlines(): void
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
        $components = CanonicalOrderPlanPipelineFixture::accepted(executionPolicy: $policy);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $plan = (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));

        self::assertSame('scalping', $plan->modeId);
        self::assertSame('2026-08-10T12:00:45+00:00', $plan->expiresAt->format(DATE_ATOM));
        self::assertSame('2026-08-10T12:01:15+00:00', $plan->cancelAfterAt?->format(DATE_ATOM));
        self::assertSame('2026-08-10T14:00:00+00:00', $plan->holdingExpiresAt?->format(DATE_ATOM));
    }

    public function testRejectsComponentsFromDifferentCanonicalIdentity(): void
    {
        $long = CanonicalOrderPlanPipelineFixture::accepted('long');
        $short = CanonicalOrderPlanPipelineFixture::accepted('short');
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_identity_mismatch');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))->build(new CanonicalOrderPlanBuildRequest(
            $long['policy'],
            $long['zoneRequest'],
            $long['zone'],
            $long['protectionRequest'],
            $short['protection'],
            $long['riskRequest'],
            $long['risk'],
            $long['netR'],
            $long['costs'],
        ));
    }

    public function testFinalValidatorRejectsExpiredZone(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $buildClock = new MockClock('2026-08-10T12:00:00+00:00');
        $plan = (new CanonicalOrderPlanBuilder($buildClock, new CanonicalOrderPlanValidator($buildClock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_expired');
        (new CanonicalOrderPlanValidator(new MockClock('2026-08-10T12:03:01+00:00')))->validate($plan);
    }

    public function testBuildRejectsCostsStaleAtExecutionTime(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $clock = new MockClock('2026-08-10T12:01:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_cost_stale');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    public function testBuildRejectsCostsStaleBySubsecondPrecision(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted(
            costObservedAt: '2026-08-10T11:59:59.000001+00:00',
        );
        $clock = new MockClock('2026-08-10T12:00:59.999999+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_cost_stale');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    public function testBuildRejectsEntryAndProtectionInputsStaleAtExecutionTime(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $clock = new MockClock('2026-08-10T12:00:31+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_input_stale');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    public function testBuildRejectsInstrumentMetadataStaleAtExecutionTime(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted(
            instrumentObservedAt: '2026-08-10T11:58:59+00:00',
        );
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_input_stale');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    public function testAuthorityRejectsRiskSizingFromDifferentMarketType(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $riskRequestArguments = get_object_vars($components['riskRequest']);
        $riskRequestArguments['marketType'] = 'spot';
        $instrumentArguments = get_object_vars($components['riskRequest']->instrument);
        $instrumentArguments['marketType'] = 'spot';
        $riskRequestArguments['instrument'] = new CanonicalInstrumentSnapshot(...$instrumentArguments);
        $riskArguments = get_object_vars($components['risk']);
        $riskArguments['marketType'] = 'spot';
        $components['riskRequest'] = new CanonicalRiskCalculationRequest(...$riskRequestArguments);
        $components['risk'] = new CanonicalRiskDecision(...$riskArguments);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_identity_mismatch');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    public function testFinalValidatorRejectsPlanWhenRetainedCostsBecomeStale(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $buildClock = new MockClock('2026-08-10T12:00:00+00:00');
        $plan = (new CanonicalOrderPlanBuilder($buildClock, new CanonicalOrderPlanValidator($buildClock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_cost_stale');
        (new CanonicalOrderPlanValidator(new MockClock('2026-08-10T12:01:00+00:00')))->validate($plan);
    }

    public function testFinalValidatorRejectsPlanWhenRetainedInputsBecomeStale(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $buildClock = new MockClock('2026-08-10T12:00:00+00:00');
        $plan = (new CanonicalOrderPlanBuilder($buildClock, new CanonicalOrderPlanValidator($buildClock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_input_stale');
        (new CanonicalOrderPlanValidator(new MockClock('2026-08-10T12:00:31+00:00')))->validate($plan);
    }

    public function testBuilderRejectsFabricatedEntryZoneBounds(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $zoneArguments = get_object_vars($components['zone']);
        $zoneArguments['lowerPrice'] = 90.0;
        $components['zone'] = new CanonicalEntryZone(...$zoneArguments);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_entry_zone_mismatch');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    public function testBuilderRejectsFabricatedProtectionTarget(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $first = $components['protection']->targets[0];
        $targetArguments = get_object_vars($first);
        $targetArguments['price'] = $first->price + 1.0;
        $protectionArguments = get_object_vars($components['protection']);
        $protectionArguments['targets'][0] = new CanonicalProtectionTarget(...$targetArguments);
        $components['protection'] = new CanonicalProtectionDecision(...$protectionArguments);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_protection_mismatch');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
            ->build(new CanonicalOrderPlanBuildRequest(...$components));
    }

    public function testBuilderRejectsInternallyConsistentButFabricatedNetRDecision(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $forged = $this->forgedNetR($components['netR']);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_net_r_mismatch');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))->build(new CanonicalOrderPlanBuildRequest(
            $components['policy'],
            $components['zoneRequest'],
            $components['zone'],
            $components['protectionRequest'],
            $components['protection'],
            $components['riskRequest'],
            $components['risk'],
            $forged,
            $components['costs'],
        ));
    }

    public function testPublicBuildPathRejectsFabricatedTargetCosts(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $request = new CanonicalOrderPlanBuildRequest(
            $components['policy'],
            $components['zoneRequest'],
            $components['zone'],
            $components['protectionRequest'],
            $components['protection'],
            $components['riskRequest'],
            $components['risk'],
            $this->forgedNetR($components['netR']),
            $components['costs'],
        );

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_net_r_mismatch');
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        CanonicalOrderPlan::build($request, $clock, new CanonicalOrderPlanValidator($clock));
    }

    public function testPublicBuildPathRejectsEmptyProtectionTargets(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $protectionArguments = get_object_vars($components['protection']);
        $protectionArguments['targets'] = [];
        $components['protection'] = new CanonicalProtectionDecision(...$protectionArguments);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_protection_mismatch');
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        CanonicalOrderPlan::build(
            new CanonicalOrderPlanBuildRequest(...$components),
            $clock,
            new CanonicalOrderPlanValidator($clock),
        );
    }

    public function testBuilderRejectsFabricatedRiskDecision(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $arguments = get_object_vars($components['risk']);
        $arguments['riskBudgetQuote'] = 999.0;
        $forged = new CanonicalRiskDecision(...$arguments);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_risk_mismatch');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))->build(new CanonicalOrderPlanBuildRequest(
            $components['policy'],
            $components['zoneRequest'],
            $components['zone'],
            $components['protectionRequest'],
            $components['protection'],
            $components['riskRequest'],
            $forged,
            $components['netR'],
            $components['costs'],
        ));
    }

    public function testPublicBuildPathRejectsFabricatedRiskDecision(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $arguments = get_object_vars($components['risk']);
        $arguments['grossStopLoss'] = 0.01;
        $arguments['totalStopLoss'] = 0.01
            + $components['risk']->entryFee
            + $components['risk']->stopExitFee
            + $components['risk']->entrySpreadCost
            + $components['risk']->stopSpreadCost
            + $components['risk']->entrySlippageCost
            + $components['risk']->stopSlippageCost
            + $components['risk']->fundingCost;
        $request = new CanonicalOrderPlanBuildRequest(
            $components['policy'],
            $components['zoneRequest'],
            $components['zone'],
            $components['protectionRequest'],
            $components['protection'],
            $components['riskRequest'],
            new CanonicalRiskDecision(...$arguments),
            $components['netR'],
            $components['costs'],
        );

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_risk_mismatch');
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        CanonicalOrderPlan::build($request, $clock, new CanonicalOrderPlanValidator($clock));
    }

    public function testPlanConstructorIsPrivate(): void
    {
        $constructor = (new \ReflectionClass(CanonicalOrderPlan::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testAcceptedComponentsFactoryIsPrivate(): void
    {
        $factory = new \ReflectionMethod(CanonicalOrderPlan::class, 'fromAcceptedComponents');

        self::assertTrue($factory->isPrivate());
    }

    public function testExactRevalidationIsIndependentFromSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        self::assertIsString($previous);
        self::assertNotFalse(ini_set('serialize_precision', '-1'));
        try {
            $components = CanonicalOrderPlanPipelineFixture::accepted();
            $clock = new MockClock('2026-08-10T12:00:00+00:00');
            $plan = (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
                ->build(new CanonicalOrderPlanBuildRequest(...$components));

            self::assertNotFalse(ini_set('serialize_precision', '14'));
            self::assertSame($components['risk']->totalStopLoss, $plan->totalStopLoss);
            self::assertSame($plan, (new CanonicalOrderPlanValidator($clock))->validate($plan));
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    private function forgedNetR(CanonicalNetRDecision $decision): CanonicalNetRDecision
    {
        $targets = array_map(
            static fn (CanonicalNetRTargetDecision $target): CanonicalNetRTargetDecision => new CanonicalNetRTargetDecision(
                id: $target->id,
                price: $target->price,
                grossReward: $target->grossReward,
                entryFee: 0.0,
                targetFee: 0.0,
                entrySpreadCost: 0.0,
                entrySlippageCost: 0.0,
                targetSpreadCost: 0.0,
                targetSlippageCost: 0.0,
                fundingCost: 0.0,
                netReward: $target->grossReward,
                netRisk: $target->netRisk,
                netR: $target->grossReward / $target->netRisk,
            ),
            $decision->targets,
        );

        return new CanonicalNetRDecision(
            $targets,
            $decision->minimumNetR,
            $decision->fundingIntervals,
            $decision->configHash,
            $decision->costInputHash,
        );
    }
}
