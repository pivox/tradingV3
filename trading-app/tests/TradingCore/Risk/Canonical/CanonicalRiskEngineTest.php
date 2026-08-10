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
        $costs = new CanonicalCostSnapshot(0.001, 0.001, 0.0002, 0.0005, 0.0001, 2);
        $request = $this->request([
            'costs' => $costs,
            'quantityStep' => 0.01,
            'minQuantity' => 0.01,
        ]);

        $decision = (new CanonicalRiskEngine())->calculate($request);
        $grossPerUnit = abs($entry - $stop) * $contractSize;
        $costPerUnit = $entry * $contractSize * (0.001 + 0.0002 + 0.0005 + 0.0001 * 2)
            + $stop * $contractSize * 0.001;
        $expectedRaw = 10.0 / ($grossPerUnit + $costPerUnit);

        self::assertEqualsWithDelta($expectedRaw, $decision->rawQuantity, 1e-12);
        self::assertSame(floor($expectedRaw / 0.01) * 0.01, $decision->quantity);
        self::assertEqualsWithDelta($decision->quantity * 2.0, $decision->grossStopLoss, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.001, $decision->entryFee, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 98.0 * 0.001, $decision->stopExitFee, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.0002, $decision->spreadCost, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.0005, $decision->slippageCost, 1e-12);
        self::assertEqualsWithDelta($decision->quantity * 100.0 * 0.0001 * 2, $decision->fundingCost, 1e-12);
        self::assertEqualsWithDelta(
            $decision->grossStopLoss + $decision->entryFee + $decision->stopExitFee
                + $decision->spreadCost + $decision->slippageCost + $decision->fundingCost,
            $decision->totalStopLoss,
            1e-12,
        );
        self::assertLessThanOrEqual($decision->riskBudgetQuote + 1e-12, $decision->totalStopLoss);
    }

    public function testUsesShortStopNotionalForShortStopExitCost(): void
    {
        $costs = new CanonicalCostSnapshot(0.0, 0.001, 0.0, 0.0, 0.0, 0);
        $decision = (new CanonicalRiskEngine())->calculate($this->request([
            'policy' => $this->policy('short'),
            'side' => 'short',
            'stopPrice' => 102.0,
            'costs' => $costs,
        ]));

        self::assertEqualsWithDelta($decision->quantity * 102.0 * 0.001, $decision->stopExitFee, 1e-12);
        self::assertLessThanOrEqual($decision->riskBudgetQuote + 1e-12, $decision->totalStopLoss);
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
            'costs' => new CanonicalCostSnapshot(0.0, 0.0, 0.0, 0.0, 0.0, 0),
        ];

        return new CanonicalRiskCalculationRequest(...array_replace($arguments, $overrides));
    }

    private function policy(
        string $side,
        float $riskRate = 0.01,
        float $modeLeverageCap = 5.0,
        float $exchangeMaxNotional = 1000.0,
        float $environmentMaxNotional = 500.0,
    ): CanonicalRiskPolicy {
        return new CanonicalRiskPolicy(
            modeId: 'day_trading',
            modeVersion: '1.0.0',
            setupId: 'day_trading.trend_continuation.' . $side,
            setupVersion: '1.0.0',
            exchange: 'fake',
            environment: 'test',
            side: $side,
            configHash: 'sha256:' . str_repeat('a', 64),
            riskRate: $riskRate,
            modeLeverageCap: $modeLeverageCap,
            makerFeeRate: 0.0,
            takerFeeRate: 0.0,
            exchangeMaxNotional: $exchangeMaxNotional,
            environmentMaxNotional: $environmentMaxNotional,
        );
    }
}
