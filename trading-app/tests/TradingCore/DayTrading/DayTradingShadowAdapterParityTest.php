<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\DayTrading;

use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterInterface;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use App\TradingCore\DayTrading\DayTradingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DayTradingShadowRuntime::class)]
final class DayTradingShadowAdapterParityTest extends TestCase
{
    public function testFakePaperAndBacktestProduceIdenticalCanonicalShadowDecisions(): void
    {
        $normalized = [];
        foreach ($this->cells() as [$capability, $adapter]) {
            $outcome = DayTradingShadowRuntimeTest::fixtureRuntime($adapter)->run(
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

    public function testCapabilityAndAdapterMismatchIsFailClosed(): void
    {
        [, $paper] = $this->cells()[1];
        $outcome = DayTradingShadowRuntimeTest::fixtureRuntime($paper)->run(
            DayTradingShadowRuntimeTest::fixtureRequest(capability: ShadowExecutionCapability::Fake),
        );

        self::assertSame('no_trade', $outcome->status);
        self::assertSame('day_trading_shadow_adapter_mismatch', $outcome->reasonCode);
        self::assertNull($outcome->reservation);
    }

    /** @return list<array{ShadowExecutionCapability, CanonicalPortfolioAdapterInterface}> */
    private function cells(): array
    {
        /** @param class-string<CanonicalPortfolioAdapterInterface> $class */
        $adapter = static fn (string $class): CanonicalPortfolioAdapterInterface => new $class(
            new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')),
            new InMemoryCanonicalPortfolioReservationStore(),
        );

        return [
            [ShadowExecutionCapability::Fake, $adapter(FakeCanonicalPortfolioAdapter::class)],
            [ShadowExecutionCapability::Paper, $adapter(PaperCanonicalPortfolioAdapter::class)],
            [ShadowExecutionCapability::Backtest, $adapter(BacktestCanonicalPortfolioAdapter::class)],
        ];
    }
}
