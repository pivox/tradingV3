<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Scalping;

use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterInterface;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use App\TradingCore\Scalping\ScalpingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ScalpingShadowRuntime::class)]
#[CoversClass(CanonicalPortfolioReservation::class)]
final class ScalpingShadowFillLifecycleTest extends TestCase
{
    #[DataProvider('capabilities')]
    public function testMakerFullFillBeforeEntryTtlBecomesFilled(ShadowExecutionCapability $capability): void
    {
        [$selector, $adapter] = $this->harness($capability);
        $outcome = ScalpingShadowRuntimeTest::fixtureRuntime(adapters: $selector)->run(
            ScalpingShadowRuntimeTest::fixtureRequest(
                'scalping.trend_continuation.long',
                'long',
                capability: $capability,
            ),
        );
        self::assertNotNull($outcome->orderPlan);
        self::assertNotNull($outcome->reservation);

        $filled = $adapter->applyFill(
            $outcome->reservation,
            $this->fill(
                $outcome->reservation,
                $outcome->orderPlan,
                'maker-full-fill',
                $outcome->orderPlan->quantity,
                0.0,
                new \DateTimeImmutable('2026-08-10T12:00:44+00:00'),
            ),
        );

        self::assertSame('filled', $filled->status);
        self::assertSame('none', $filled->requiredAction);
        self::assertSame($outcome->orderPlan->quantity, $filled->filledQuantity);
        self::assertSame(0.0, $filled->remainingQuantity);
        self::assertCount(1, $filled->appliedFillHashes);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $filled->stateHash);
    }

    #[DataProvider('capabilities')]
    public function testNoFillAtEntryTtlCancelsResidual(ShadowExecutionCapability $capability): void
    {
        [$selector, $adapter] = $this->harness($capability);
        $outcome = ScalpingShadowRuntimeTest::fixtureRuntime(adapters: $selector)->run(
            ScalpingShadowRuntimeTest::fixtureRequest(
                'scalping.trend_continuation.long',
                'long',
                capability: $capability,
            ),
        );
        self::assertNotNull($outcome->reservation);

        $cancelled = $adapter->cancelResidual(
            $outcome->reservation,
            new \DateTimeImmutable('2026-08-10T12:00:45+00:00'),
            'sha256:' . str_repeat('1', 64),
        );

        self::assertSame('cancelled', $cancelled->status);
        self::assertSame('none', $cancelled->requiredAction);
        self::assertSame(0.0, $cancelled->filledQuantity);
        self::assertSame(0.0, $cancelled->remainingQuantity);
        self::assertSame('sha256:' . str_repeat('1', 64), $cancelled->transitionInputHashes[array_key_last($cancelled->transitionInputHashes)]);
    }

    #[DataProvider('capabilities')]
    public function testPartialMakerFillThenResidualCancelAtCancelDeadlineRemainsPartiallyFilled(
        ShadowExecutionCapability $capability,
    ): void {
        [$selector, $adapter] = $this->harness($capability);
        $outcome = ScalpingShadowRuntimeTest::fixtureRuntime(adapters: $selector)->run(
            ScalpingShadowRuntimeTest::fixtureRequest(
                'scalping.trend_continuation.long',
                'long',
                capability: $capability,
            ),
        );
        self::assertNotNull($outcome->orderPlan);
        self::assertNotNull($outcome->reservation);
        $partialQuantity = 0.1;
        $venueRemaining = $outcome->orderPlan->quantity - $partialQuantity;
        $partiallyFilled = $adapter->applyFill(
            $outcome->reservation,
            $this->fill(
                $outcome->reservation,
                $outcome->orderPlan,
                'maker-partial-fill',
                $partialQuantity,
                $venueRemaining,
                new \DateTimeImmutable('2026-08-10T12:00:44+00:00'),
            ),
        );

        $cancelled = $adapter->cancelResidual(
            $partiallyFilled,
            new \DateTimeImmutable('2026-08-10T12:01:15+00:00'),
            'sha256:' . str_repeat('2', 64),
        );

        self::assertSame('partially_filled', $cancelled->status);
        self::assertSame('none', $cancelled->requiredAction);
        self::assertSame($partialQuantity, $cancelled->filledQuantity);
        self::assertSame(0.0, $cancelled->remainingQuantity);
        self::assertCount(1, $cancelled->appliedFillHashes);
    }

    #[DataProvider('capabilities')]
    public function testHoldingDeadlineAfterFullOrPartialFillRequiresPositionClose(
        ShadowExecutionCapability $capability,
    ): void {
        foreach ([
            'full' => null,
            'partial' => 0.1,
        ] as $fillId => $partialQuantity) {
            [$selector, $adapter] = $this->harness($capability);
            $outcome = ScalpingShadowRuntimeTest::fixtureRuntime(adapters: $selector)->run(
                ScalpingShadowRuntimeTest::fixtureRequest(
                    'scalping.trend_continuation.long',
                    'long',
                    capability: $capability,
                ),
            );
            self::assertNotNull($outcome->orderPlan);
            self::assertNotNull($outcome->reservation);
            $quantity = $partialQuantity ?? $outcome->orderPlan->quantity;
            $filled = $adapter->applyFill(
                $outcome->reservation,
                $this->fill(
                    $outcome->reservation,
                    $outcome->orderPlan,
                    $fillId . '-holding-fill',
                    $quantity,
                    $outcome->orderPlan->quantity - $quantity,
                    new \DateTimeImmutable('2026-08-10T12:00:44+00:00'),
                ),
            );

            $expired = $adapter->enforceHoldingDeadline(
                $filled,
                new \DateTimeImmutable('2026-08-10T14:00:00+00:00'),
                'sha256:' . str_repeat('3', 64),
            );

            self::assertSame('holding_expired', $expired->status);
            self::assertSame('close_position', $expired->requiredAction);
            self::assertSame(0.0, $expired->remainingQuantity);
        }
    }

    /** @return iterable<string, array{ShadowExecutionCapability}> */
    public static function capabilities(): iterable
    {
        yield 'fake' => [ShadowExecutionCapability::Fake];
        yield 'paper' => [ShadowExecutionCapability::Paper];
        yield 'backtest' => [ShadowExecutionCapability::Backtest];
    }

    /** @return array{CanonicalPortfolioAdapterSelector, CanonicalPortfolioAdapterInterface} */
    private function harness(ShadowExecutionCapability $capability): array
    {
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $fake = new FakeCanonicalPortfolioAdapter(
            new CanonicalPortfolioAdmissionEngine($clock),
            new InMemoryCanonicalPortfolioReservationStore(),
        );
        $paper = new PaperCanonicalPortfolioAdapter(
            new CanonicalPortfolioAdmissionEngine($clock),
            new InMemoryCanonicalPortfolioReservationStore(),
        );
        $backtest = new BacktestCanonicalPortfolioAdapter(
            new CanonicalPortfolioAdmissionEngine($clock),
            new InMemoryCanonicalPortfolioReservationStore(),
        );
        $selector = new CanonicalPortfolioAdapterSelector($fake, $paper, $backtest);

        return [$selector, $selector->select($capability)];
    }

    private function fill(
        CanonicalPortfolioReservation $reservation,
        CanonicalOrderPlan $plan,
        string $fillId,
        float $quantity,
        float $remainingOrderQuantity,
        \DateTimeImmutable $observedAt,
    ): CanonicalPortfolioFill {
        return new CanonicalPortfolioFill(
            $reservation->scope,
            $reservation->decisionKey,
            $reservation->planHash,
            $reservation->admissionHash,
            $fillId,
            $quantity,
            $plan->entryPrice,
            $plan->entryFee * ($quantity / $plan->quantity),
            $quantity,
            $remainingOrderQuantity,
            $observedAt,
            'sha256:' . hash('sha256', $fillId),
        );
    }
}
