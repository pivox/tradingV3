<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical;

use App\TradingCore\Risk\Canonical\CanonicalCostSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalRiskEngine::class)]
final class CanonicalRiskEngineTest extends TestCase
{
    public function testSizesNoCostBaselineFromEquityRiskAndStopDistance(): void
    {
        $decision = (new CanonicalRiskEngine())->calculate($this->request());

        self::assertSame(10.0, $decision->riskBudgetQuote);
        self::assertSame(5.0, $decision->rawQuantity);
        self::assertSame(5.0, $decision->quantity);
        self::assertSame(500.0, $decision->positionNotional);
        self::assertSame(5, $decision->finalLeverage);
        self::assertSame(10.0, $decision->grossStopLoss);
        self::assertSame(0.0, $decision->entryFee);
        self::assertSame(0.0, $decision->stopExitFee);
        self::assertSame(10.0, $decision->totalStopLoss);
        self::assertLessThanOrEqual($decision->riskBudgetQuote, $decision->totalStopLoss);
    }

    public function testIncludesEveryStopPathCostBeforeSizingAndAfterQuantization(): void
    {
        $entry = 100.0;
        $stop = 98.0;
        $contractSize = 1.0;
        $costs = new CanonicalCostSnapshot('maker', 'taker', 0.0002, 0.0003, 0.0005, 0.0007, 0.0001, 2);
        $request = $this->request([
            'policy' => $this->policy('long', makerFeeRate: 0.001, takerFeeRate: 0.001),
            'costs' => $costs,
            'quantityStep' => 0.01,
            'minQuantity' => 0.01,
        ]);

        $decision = (new CanonicalRiskEngine())->calculate($request);
        $grossPerUnit = abs($entry - $stop) * $contractSize;
        $costPerUnit = $entry * $contractSize * (0.001 + 0.0002 + 0.0005 + 0.0001 * 2)
            + $stop * $contractSize * (0.001 + 0.0003 + 0.0007);
        $expectedRaw = 10.0 / ($grossPerUnit + $costPerUnit);

        self::assertEqualsWithDelta($expectedRaw, $decision->rawQuantity, 1e-12);
        self::assertSame(floor($expectedRaw / 0.01) * 0.01, $decision->quantity);
        self::assertEqualsWithDelta($decision->quantity * 2.0, $decision->grossStopLoss, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.001, $decision->entryFee, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 98.0 * 0.001, $decision->stopExitFee, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.0002, $decision->entrySpreadCost, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 98.0 * 0.0003, $decision->stopSpreadCost, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.0005, $decision->entrySlippageCost, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 98.0 * 0.0007, $decision->stopSlippageCost, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.0001 * 2, $decision->fundingCost, 1e-12);
        self::assertEqualsWithDelta(
            $decision->grossStopLoss + $decision->entryFee + $decision->stopExitFee
                + $decision->entrySpreadCost + $decision->stopSpreadCost
                + $decision->entrySlippageCost + $decision->stopSlippageCost + $decision->fundingCost,
            $decision->totalStopLoss,
            1e-12,
        );
        self::assertLessThanOrEqual($decision->riskBudgetQuote + 1e-12, $decision->totalStopLoss);
    }

    public function testUsesShortStopNotionalForShortStopExitCost(): void
    {
        $costs = new CanonicalCostSnapshot('maker', 'taker', 0.0, 0.0, 0.0, 0.0, 0.0, 0);
        $decision = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy('short', makerFeeRate: 0.0, takerFeeRate: 0.001),
            'side' => 'short',
            'stopPrice' => 102.0,
            'costs' => $costs,
        ]));

        self::assertEqualsWithDelta($decision->quantity * 102.0 * 0.001, $decision->stopExitFee, 1e-12);
        self::assertLessThanOrEqual($decision->riskBudgetQuote + 1e-12, $decision->totalStopLoss);
    }

    public function testFundingChargesOnlyTheAdverseRateForEachSide(): void
    {
        $shortPaying = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy('short'),
            'side' => 'short',
            'stopPrice' => 102.0,
            'costs' => new CanonicalCostSnapshot('maker', 'maker', 0.0, 0.0, 0.0, 0.0, -0.001, 1),
        ]));
        $shortReceiving = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy('short'),
            'side' => 'short',
            'stopPrice' => 102.0,
            'costs' => new CanonicalCostSnapshot('maker', 'maker', 0.0, 0.0, 0.0, 0.0, 0.001, 1),
        ]));
        $longPaying = (new CanonicalRiskEngine())->calculate($this->request([
            'costs' => new CanonicalCostSnapshot('maker', 'maker', 0.0, 0.0, 0.0, 0.0, 0.001, 1),
        ]));

        self::assertGreaterThan(0.0, $shortPaying->fundingCost);
        self::assertSame(0.0, $shortReceiving->fundingCost);
        self::assertGreaterThan(0.0, $longPaying->fundingCost);
    }

    public function testMostRestrictiveQuantityAndNotionalCapsWin(): void
    {
        $policy = $this->policy('long', exchangeMaxNotional: 80.0, environmentMaxNotional: 25.0);
        $decision = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $policy,
            'maxQuantity' => 1.0,
            'marketMaxQuantity' => 0.5,
        ]));

        self::assertSame(0.25, $decision->quantity);
        self::assertSame(25.0, $decision->positionNotional);
        self::assertContains('environment_max_notional', $decision->capsApplied);
        self::assertContains('market_max_quantity', $decision->capsApplied);
    }

    public function testLeverageCapacityReducesQuantityBeforeFinalLeverage(): void
    {
        $policy = $this->policy('long', modeLeverageCap: 2.9);
        $decision = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $policy,
            'availableBalanceQuote' => 10.0,
            'exchangeLeverageCap' => 10.0,
            'symbolLeverageCap' => 3.5,
        ]));

        self::assertSame(0.2, $decision->quantity);
        self::assertSame(20.0, $decision->positionNotional);
        self::assertSame(2, $decision->finalLeverage);
        self::assertContains('mode_leverage_cap', $decision->capsApplied);
        self::assertContains('symbol_leverage_cap', $decision->capsApplied);
    }

    public function testRejectsExplicitZeroAvailableBalance(): void
    {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_risk_available_balance_exhausted');
        (new CanonicalRiskEngine())->calculate($this->request(['availableBalanceQuote' => 0.0]));
    }

    public function testNeverRoundsUpToMinimumQuantity(): void
    {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_risk_quantity_below_minimum');
        (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy('long', riskRate: 0.00001),
            'quantityStep' => 1.0,
            'minQuantity' => 1.0,
        ]));
    }

    public function testPreservesAnExactDecimalGridPointDuringQuantization(): void
    {
        $decision = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy(
                'long',
                exchangeMinNotional: 0.0,
                exchangeMaxNotional: 100.0,
                environmentMaxNotional: 30.0,
            ),
            'quantityStep' => 0.1,
            'minQuantity' => 0.3,
            'maxQuantity' => 0.3,
            'marketMaxQuantity' => 0.3,
        ]));

        self::assertSame(0.3, $decision->quantity);
    }

    public function testRejectsZeroQuantityAtMinimumSupportedStep(): void
    {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_risk_quantity_below_minimum');
        (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy(
                'long',
                exchangeMinNotional: 0.0,
                exchangeMaxNotional: 1.0,
                environmentMaxNotional: 5.0e-11,
            ),
            'quantityStep' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
            'minQuantity' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
            'maxQuantity' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
            'marketMaxQuantity' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
        ]));
    }

    public function testRejectsPositiveQuantizedQuantityBelowMinimumWithoutAbsoluteTolerance(): void
    {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_risk_quantity_below_minimum');
        (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy(
                'long',
                exchangeMinNotional: 0.0,
                exchangeMaxNotional: 1.0,
                environmentMaxNotional: 1.5e-10,
            ),
            'quantityStep' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
            'minQuantity' => 2.0e-12,
            'maxQuantity' => 2.0e-12,
            'marketMaxQuantity' => 2.0e-12,
        ]));
    }

    public function testRejectsPositionBelowTinyExchangeMinimumNotional(): void
    {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_risk_notional_below_minimum');
        (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy(
                'long',
                exchangeMinNotional: 2.0e-12,
                exchangeMaxNotional: 1.0,
                environmentMaxNotional: 1.0,
            ),
            'entryPrice' => 1.0,
            'stopPrice' => 0.5,
            'quantityStep' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
            'minQuantity' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
            'maxQuantity' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
            'marketMaxQuantity' => CanonicalRiskCalculationRequest::MIN_QUANTITY_STEP,
        ]));
    }

    public function testRejectsBelowExchangeMinimumNotionalWithoutRoundingUp(): void
    {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage('canonical_risk_notional_below_minimum');
        (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy('long', exchangeMinNotional: 600.0),
        ]));
    }

    public function testDerivesEachFeeFromItsCompiledLiquidityRole(): void
    {
        $decision = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy('long', makerFeeRate: 0.001, takerFeeRate: 0.002),
            'costs' => new CanonicalCostSnapshot('maker', 'taker', 0.0, 0.0, 0.0, 0.0, 0.0, 0),
        ]));

        self::assertEqualsWithDelta($decision->positionNotional * 0.001, $decision->entryFee, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 98.0 * 0.002, $decision->stopExitFee, 1e-12);
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): CanonicalRiskCalculationRequest
    {
        $arguments = [
            'policy' => $this->policy('long'),
            'symbol' => 'BTCUSDT',
            'side' => 'long',
            'equityQuote' => 1000.0,
            'availableBalanceQuote' => 100.0,
            'entryPrice' => 100.0,
            'stopPrice' => 98.0,
            'contractSize' => 1.0,
            'quantityStep' => 0.001,
            'minQuantity' => 0.001,
            'maxQuantity' => 100.0,
            'marketMaxQuantity' => 100.0,
            'exchangeLeverageCap' => 20.0,
            'symbolLeverageCap' => 10.0,
            'costs' => new CanonicalCostSnapshot('maker', 'maker', 0.0, 0.0, 0.0, 0.0, 0.0, 0),
        ];

        return new CanonicalRiskCalculationRequest(...array_replace($arguments, $overrides));
    }

    private function policy(
        string $side,
        float $riskRate = 0.01,
        float $modeLeverageCap = 5.0,
        float $makerFeeRate = 0.0,
        float $takerFeeRate = 0.0,
        float $exchangeMinNotional = 1.0,
        float $exchangeMaxNotional = 1000.0,
        float $environmentMaxNotional = 500.0,
    ): CanonicalRiskPolicy {
        return CanonicalRiskTestFactory::policy(
            side: $side,
            riskRate: $riskRate,
            modeLeverageCap: $modeLeverageCap,
            makerFeeRate: $makerFeeRate,
            takerFeeRate: $takerFeeRate,
            exchangeMinNotional: $exchangeMinNotional,
            exchangeMaxNotional: $exchangeMaxNotional,
            environmentMaxNotional: $environmentMaxNotional,
        );
    }
}
