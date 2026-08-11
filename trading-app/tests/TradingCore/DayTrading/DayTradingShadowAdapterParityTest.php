<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\DayTrading;

use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\DayTrading\DayTradingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DayTradingShadowRuntime::class)]
#[CoversClass(CanonicalPortfolioAdapterSelector::class)]
final class DayTradingShadowAdapterParityTest extends TestCase
{
    public function testSelectorMapsEachCapabilityToItsDedicatedAdapter(): void
    {
        $selector = DayTradingShadowRuntimeTest::fixtureSelector();

        self::assertInstanceOf(FakeCanonicalPortfolioAdapter::class, $selector->select(ShadowExecutionCapability::Fake));
        self::assertInstanceOf(PaperCanonicalPortfolioAdapter::class, $selector->select(ShadowExecutionCapability::Paper));
        self::assertInstanceOf(BacktestCanonicalPortfolioAdapter::class, $selector->select(ShadowExecutionCapability::Backtest));
    }

    public function testFakePaperAndBacktestProduceIdenticalCanonicalShadowDecisions(): void
    {
        $normalized = [];
        foreach ($this->capabilities() as $capability) {
            $outcome = DayTradingShadowRuntimeTest::fixtureRuntime()->run(
                DayTradingShadowRuntimeTest::fixtureRequest(capability: $capability),
            );
            self::assertSame('planned', $outcome->status);
            self::assertNotNull($outcome->orderPlan);
            self::assertNotNull($outcome->reservation);
            $normalized[] = [
                'status' => $outcome->status,
                'reason' => $outcome->reasonCode,
                'config_hash' => $outcome->orderPlan->configHash,
                'plan_hash' => $outcome->orderPlan->planHash,
                'caps' => $outcome->orderPlan->capsApplied,
                'risk' => $outcome->reservation->reservedRiskQuote,
                'notional' => $outcome->reservation->reservedNotionalQuote,
                'reservation_hash' => $outcome->reservation->admissionHash,
            ];
        }

        self::assertCount(1, array_unique(array_map(
            static fn (array $decision): string => json_encode($decision, JSON_THROW_ON_ERROR),
            $normalized,
        )));
    }

    /** @return list<ShadowExecutionCapability> */
    private function capabilities(): array
    {
        return [
            ShadowExecutionCapability::Fake,
            ShadowExecutionCapability::Paper,
            ShadowExecutionCapability::Backtest,
        ];
    }
}
