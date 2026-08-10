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
use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;
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
        self::assertSame($plan, $validator->validate($plan));
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
            $long['zone'],
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

    public function testBuilderRejectsInternallyConsistentButFabricatedNetRDecision(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $forged = $this->forgedNetR($components['netR']);
        $clock = new MockClock('2026-08-10T12:00:00+00:00');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_net_r_mismatch');
        (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))->build(new CanonicalOrderPlanBuildRequest(
            $components['policy'],
            $components['zone'],
            $components['protection'],
            $components['riskRequest'],
            $components['risk'],
            $forged,
            $components['costs'],
        ));
    }

    public function testDirectPlanFactoryRejectsFabricatedTargetCosts(): void
    {
        $components = CanonicalOrderPlanPipelineFixture::accepted();
        $request = new CanonicalOrderPlanBuildRequest(
            $components['policy'],
            $components['zone'],
            $components['protection'],
            $components['riskRequest'],
            $components['risk'],
            $this->forgedNetR($components['netR']),
            $components['costs'],
        );

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_target_cost_mismatch');
        CanonicalOrderPlan::fromAcceptedComponents($request, new \DateTimeImmutable('2026-08-10T12:00:00+00:00'));
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
            $components['zone'],
            $components['protection'],
            $components['riskRequest'],
            $forged,
            $components['netR'],
            $components['costs'],
        ));
    }

    public function testDirectPlanFactoryRejectsFabricatedRiskDecision(): void
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
            $components['zone'],
            $components['protection'],
            $components['riskRequest'],
            new CanonicalRiskDecision(...$arguments),
            $components['netR'],
            $components['costs'],
        );

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_order_plan_risk_components_mismatch');
        CanonicalOrderPlan::fromAcceptedComponents($request, new \DateTimeImmutable('2026-08-10T12:00:00+00:00'));
    }

    public function testPlanConstructorIsPrivate(): void
    {
        $constructor = (new \ReflectionClass(CanonicalOrderPlan::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testExactRevalidationIsIndependentFromSerializePrecision(): void
    {
        $previous = ini_get('serialize_precision');
        self::assertIsString($previous);
        self::assertNotFalse(ini_set('serialize_precision', '14'));
        try {
            $components = CanonicalOrderPlanPipelineFixture::accepted();
            $clock = new MockClock('2026-08-10T12:00:00+00:00');
            $plan = (new CanonicalOrderPlanBuilder($clock, new CanonicalOrderPlanValidator($clock)))
                ->build(new CanonicalOrderPlanBuildRequest(...$components));

            self::assertSame($components['risk']->totalStopLoss, $plan->totalStopLoss);
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
