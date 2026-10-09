<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZone;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionCostSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalMarketSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetREngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetRRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalPriceObservation;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionDecision;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalTargetCostSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use App\TradingCore\OrderPlan\Canonical\NetRCostMath;
use App\TradingCore\Risk\Canonical\CanonicalCostSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalInstrumentSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;
use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalNetREngine::class)]
#[CoversClass(CanonicalNetRRequest::class)]
final class CanonicalNetREngineTest extends TestCase
{
    public function testCalculatesCostInclusiveNetRForEveryTarget(): void
    {
        [$policy, $protection] = $this->protection();
        $costs = $this->executionCosts($policy, 0.0001);
        $risk = $this->riskDecision($policy, $protection, $costs);

        $decision = (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));

        self::assertCount(2, $decision->targets);
        self::assertSame('tp1', $decision->targets[0]->id);
        self::assertGreaterThanOrEqual(1.2, $decision->targets[0]->netR);
        self::assertGreaterThan($decision->targets[0]->netR, $decision->targets[1]->netR);
        self::assertSame($risk->totalStopLoss, $decision->targets[0]->netRisk);
        self::assertSame($policy->configHash, $decision->configHash);
    }

    public function testValueCostMathMatchesEveryAuthenticatedComponent(): void
    {
        self::assertTrue(class_exists(NetRCostMath::class), 'Value-only Net-R cost math is missing.');
        [$policy, $protection] = $this->protection();
        $costs = $this->executionCosts($policy, 0.0001);
        $risk = $this->riskDecision($policy, $protection, $costs);
        $decision = (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));
        $intervals = intdiv($policy->holdingWindowSeconds - 1, $policy->costContract->fundingIntervalSeconds) + 1;

        $riskAmounts = NetRCostMath::risk(
            $protection->side, $protection->entryPrice, $protection->stopPrice,
            $risk->quantity, $risk->contractSize,
            $policy->riskPolicy->takerFeeRate, $policy->riskPolicy->takerFeeRate,
            $costs->entrySpreadRate, $costs->stopSpreadRate,
            $costs->entrySlippageRate, $costs->stopSlippageRate,
            $costs->fundingRate, $intervals,
        );
        self::assertSame($risk->grossStopLoss, $riskAmounts['gross_risk']->toFloat());
        self::assertSame($risk->entryFee, $riskAmounts['entry_fee']->toFloat());
        self::assertSame($risk->stopExitFee, $riskAmounts['stop_fee']->toFloat());
        self::assertSame($risk->entrySpreadCost, $riskAmounts['entry_spread']->toFloat());
        self::assertSame($risk->stopSpreadCost, $riskAmounts['stop_spread']->toFloat());
        self::assertSame($risk->entrySlippageCost, $riskAmounts['entry_slippage']->toFloat());
        self::assertSame($risk->stopSlippageCost, $riskAmounts['stop_slippage']->toFloat());
        self::assertSame($risk->fundingCost, $riskAmounts['funding']->toFloat());
        self::assertSame($risk->totalStopLoss, $riskAmounts['net_risk']->toFloat());

        foreach ($protection->targets as $index => $target) {
            $targetCost = $costs->targets[$index];
            $amounts = NetRCostMath::target(
                $protection->entryPrice, $target->price, $risk->quantity, $risk->contractSize,
                $policy->riskPolicy->takerFeeRate, $targetCost->spreadRate, $targetCost->slippageRate,
                $riskAmounts,
            );
            $expected = $decision->targets[$index];
            self::assertSame($expected->grossReward, $amounts['gross_reward']->toFloat());
            self::assertSame($expected->targetFee, $amounts['target_fee']->toFloat());
            self::assertSame($expected->targetSpreadCost, $amounts['target_spread']->toFloat());
            self::assertSame($expected->targetSlippageCost, $amounts['target_slippage']->toFloat());
            self::assertSame($expected->netReward, $amounts['net_reward']->toFloat());
            self::assertSame($expected->netR, $amounts['net_r']->toFloat());
        }
    }

    public function testValueCostMathRejectsZeroRiskDenominator(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_net_r_risk_invalid');
        NetRCostMath::target(100.0, 102.0, 1.0, 1.0, 0.0005, 0.0001, 0.0001, [
            'entry_fee' => BigDecimal::of('0.05'),
            'entry_spread' => BigDecimal::of('0.01'),
            'entry_slippage' => BigDecimal::of('0.01'),
            'funding' => BigDecimal::zero(),
            'net_risk' => BigDecimal::zero(),
        ]);
    }

    public function testValueCostMathKeepsPerLegFeesAndShortAdverseFunding(): void
    {
        $amounts = NetRCostMath::risk(
            'short', 100.0, 101.0, 1.0, 1.0,
            0.0002, 0.0005,
            0.0, 0.0, 0.0, 0.0,
            -0.0001, 2,
        );
        self::assertSame(0.02, $amounts['entry_fee']->toFloat());
        self::assertSame(0.0505, $amounts['stop_fee']->toFloat());
        self::assertSame(0.02, $amounts['funding']->toFloat());

        $favorable = NetRCostMath::risk(
            'short', 100.0, 101.0, 1.0, 1.0,
            0.0002, 0.0005,
            0.0, 0.0, 0.0, 0.0,
            0.0001, 2,
        );
        self::assertSame(0.0, $favorable['funding']->toFloat());
    }

    public function testRejectsWhenGrossRPassesButCostInclusiveNetRFails(): void
    {
        [$policy, $protection] = $this->protection();
        $costs = $this->executionCosts($policy, 0.005);
        $risk = $this->riskDecision($policy, $protection, $costs);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_minimum_net_r_not_met');
        (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));
    }

    public function testRejectsCostSourceMismatch(): void
    {
        [$policy, $protection] = $this->protection();
        $costs = $this->executionCosts($policy, 0.0001, entrySpreadSource: 'reference_price');
        $risk = $this->riskDecision($policy, $protection, $costs);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_net_r_cost_source_mismatch');
        (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));
    }

    public function testRejectsRiskDecisionBuiltForDifferentEntry(): void
    {
        [$policy, $protection] = $this->protection();
        $costs = $this->executionCosts($policy, 0.0001);
        $risk = $this->riskDecision($policy, $protection, $costs, entryPrice: 100.0);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_net_r_risk_identity_mismatch');
        (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));
    }

    public function testRejectsUnknownTargetCostAtSnapshotBoundary(): void
    {
        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_net_r_cost_unknown');
        new CanonicalTargetCostSnapshot('tp1', null, 0.0001, 'execution_model', 0.0001);
    }

    public function testRejectsCostsObservedForAnotherSymbol(): void
    {
        [$policy, $protection] = $this->protection();
        $costs = $this->executionCosts($policy, 0.0001, symbol: 'ETHUSDT');
        $risk = $this->riskDecision($policy, $protection, $costs);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_net_r_cost_identity_mismatch');
        (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));
    }

    public function testRejectsCostsObservedForAnotherMarketType(): void
    {
        [$policy, $protection] = $this->protection();
        $costs = $this->executionCosts($policy, 0.0001, marketType: 'spot');
        $risk = $this->riskDecision($policy, $protection, $costs);

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_net_r_cost_identity_mismatch');
        (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));
    }

    public function testFundingIntervalCeilingDoesNotOverflowAtMaximumHoldingWindow(): void
    {
        [$policy, $protection] = $this->protection('PT2562047788015215H30M7S');
        self::assertSame(PHP_INT_MAX, $policy->holdingWindowSeconds);
        $costs = $this->executionCosts($policy, 0.0001, fundingRate: 0.0);
        $risk = $this->riskDecision($policy, $protection, $costs);

        $decision = (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));

        self::assertSame(intdiv(PHP_INT_MAX - 1, 28_800) + 1, $decision->fundingIntervals);
    }

    /** @return array{0: CanonicalExecutionPolicy, 1: CanonicalProtectionDecision} */
    private function protection(string $timeStop = 'PT30M'): array
    {
        $policy = CanonicalExecutionPolicyFixture::policy('long', 'atr', $timeStop);
        $observed = new \DateTimeImmutable('2026-08-10T11:59:30+00:00');
        $zone = (new CanonicalEntryZoneEngine(new MockClock('2026-08-10T12:00:00+00:00')))->calculate(new CanonicalEntryZoneRequest(
            $policy,
            'BTCUSDT',
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'perpetual', 'vwap', '5m', 100.0, $observed, 'sha256:' . str_repeat('1', 64)),
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'perpetual', 'atr', '5m', 1.0, $observed, 'sha256:' . str_repeat('2', 64)),
            new CanonicalMarketSnapshot('fake', 'test', 'BTCUSDT', 'perpetual', 'order_book', 100.1, $observed, 'sha256:' . str_repeat('3', 64)),
            new CanonicalTickSnapshot('fake', 'test', 'BTCUSDT', 'perpetual', 0.1, $observed, 'sha256:' . str_repeat('4', 64)),
        ));
        $protection = (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest(
            $policy,
            $zone,
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'perpetual', 'atr', '5m', 1.0, $observed, 'sha256:' . str_repeat('5', 64)),
            null,
        ));

        return [$policy, $protection];
    }

    private function executionCosts(
        CanonicalExecutionPolicy $policy,
        float $rate,
        string $entrySpreadSource = 'order_book',
        string $symbol = 'BTCUSDT',
        float $fundingRate = 0.0001,
        string $marketType = 'perpetual',
    ): CanonicalExecutionCostSnapshot
    {
        return new CanonicalExecutionCostSnapshot(
            exchange: 'fake',
            environment: 'test',
            symbol: $symbol,
            marketType: $marketType,
            configHash: $policy->configHash,
            entryLiquidityRole: 'taker',
            stopLiquidityRole: 'taker',
            entrySpreadSource: $entrySpreadSource,
            entrySpreadRate: $rate,
            entrySlippageSource: 'execution_model',
            entrySlippageRate: $rate,
            stopSpreadSource: 'order_book',
            stopSpreadRate: $rate,
            stopSlippageSource: 'execution_model',
            stopSlippageRate: $rate,
            fundingSource: 'venue_schedule',
            fundingRate: $fundingRate,
            targets: [
                new CanonicalTargetCostSnapshot('tp1', 'order_book', $rate, 'execution_model', $rate),
                new CanonicalTargetCostSnapshot('tp2', 'order_book', $rate, 'execution_model', $rate),
            ],
            observedAt: new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            inputHash: 'sha256:' . str_repeat('6', 64),
        );
    }

    private function riskDecision(
        CanonicalExecutionPolicy $policy,
        CanonicalProtectionDecision $protection,
        CanonicalExecutionCostSnapshot $costs,
        ?float $entryPrice = null,
    ): CanonicalRiskDecision {
        return (new CanonicalRiskEngine())->calculate(new CanonicalRiskCalculationRequest(
            policy: $policy->riskPolicy,
            symbol: $protection->symbol,
            marketType: $protection->marketType,
            quoteCurrency: 'USDT',
            side: $protection->side,
            equityQuote: 1000.0,
            availableBalanceQuote: 1000.0,
            entryPrice: $entryPrice ?? $protection->entryPrice,
            stopPrice: $protection->stopPrice,
            contractSize: 1.0,
            quantityStep: 0.001,
            minQuantity: 0.001,
            maxQuantity: 100.0,
            marketMaxQuantity: 100.0,
            exchangeLeverageCap: 5.0,
            symbolLeverageCap: 5.0,
            costs: new CanonicalCostSnapshot(
                $costs->entryLiquidityRole,
                $costs->stopLiquidityRole,
                $costs->entrySpreadRate,
                $costs->stopSpreadRate,
                $costs->entrySlippageRate,
                $costs->stopSlippageRate,
                $costs->fundingRate,
                intdiv($policy->holdingWindowSeconds - 1, $policy->costContract->fundingIntervalSeconds) + 1,
            ),
            instrument: new CanonicalInstrumentSnapshot(
                $policy->riskPolicy->exchange,
                $policy->riskPolicy->environment,
                $protection->symbol,
                $protection->marketType,
                'USDT',
                1.0,
                0.001,
                0.001,
                100.0,
                100.0,
                5.0,
                5.0,
                new \DateTimeImmutable('2026-08-10T11:59:40+00:00'),
                'sha256:' . str_repeat('7', 64),
            ),
        ));
    }
}
