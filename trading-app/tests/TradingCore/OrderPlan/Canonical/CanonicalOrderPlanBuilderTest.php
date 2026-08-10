<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuilder;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanValidator;
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
            $long['risk'],
            $long['netR'],
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

    public function testPlanConstructorIsPrivate(): void
    {
        $constructor = (new \ReflectionClass(CanonicalOrderPlan::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }
}
