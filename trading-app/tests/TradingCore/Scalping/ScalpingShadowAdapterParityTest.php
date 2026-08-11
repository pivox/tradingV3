<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Scalping;

use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanTarget;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\BacktestCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterInterface;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\CanonicalPortfolioAdapterSelector;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\FakeCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\Adapter\PaperCanonicalPortfolioAdapter;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use App\TradingCore\Scalping\ScalpingShadowRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(ScalpingShadowRuntime::class)]
#[CoversClass(CanonicalPortfolioAdapterSelector::class)]
final class ScalpingShadowAdapterParityTest extends TestCase
{
    public function testSelectorMapsCapabilitiesToDedicatedAdaptersWithIsolatedStores(): void
    {
        [$selector, $adapters] = $this->harness();

        self::assertSame($adapters['fake'], $selector->select(ShadowExecutionCapability::Fake));
        self::assertSame($adapters['paper'], $selector->select(ShadowExecutionCapability::Paper));
        self::assertSame($adapters['backtest'], $selector->select(ShadowExecutionCapability::Backtest));
        self::assertNotSame($adapters['fake'], $adapters['paper']);
        self::assertNotSame($adapters['fake'], $adapters['backtest']);
        self::assertNotSame($adapters['paper'], $adapters['backtest']);

        $fakeOutcome = ScalpingShadowRuntimeTest::fixtureRuntime(adapters: $selector)->run(
            ScalpingShadowRuntimeTest::fixtureRequest(
                'scalping.trend_continuation.long',
                'long',
                capability: ShadowExecutionCapability::Fake,
            ),
        );
        self::assertNotNull($fakeOutcome->orderPlan);
        self::assertNotNull($fakeOutcome->reservation);
        $fakeFilled = $adapters['fake']->applyFill(
            $fakeOutcome->reservation,
            new CanonicalPortfolioFill(
                $fakeOutcome->reservation->scope,
                $fakeOutcome->reservation->decisionKey,
                $fakeOutcome->reservation->planHash,
                $fakeOutcome->reservation->admissionHash,
                'isolation-fill',
                $fakeOutcome->orderPlan->quantity,
                $fakeOutcome->orderPlan->entryPrice,
                $fakeOutcome->orderPlan->entryFee,
                $fakeOutcome->orderPlan->quantity,
                0.0,
                new \DateTimeImmutable('2026-08-10T12:00:44+00:00'),
                'sha256:' . str_repeat('7', 64),
            ),
        );

        foreach ([
            'paper' => ShadowExecutionCapability::Paper,
            'backtest' => ShadowExecutionCapability::Backtest,
        ] as $name => $capability) {
            $isolated = ScalpingShadowRuntimeTest::fixtureRuntime(adapters: $selector)->run(
                ScalpingShadowRuntimeTest::fixtureRequest(
                    'scalping.trend_continuation.long',
                    'long',
                    capability: $capability,
                ),
            );
            self::assertNotNull($isolated->reservation);
            self::assertSame('active', $isolated->reservation->status);
            self::assertSame(0.0, $isolated->reservation->filledQuantity);
            self::assertSame(1, $isolated->reservation->version);
            self::assertNotSame($fakeFilled->stateHash, $isolated->reservation->stateHash, $name);
        }
    }

    public function testEqualObservationsProduceIdenticalPlansTransitionsHashesAndNetCosts(): void
    {
        $normalized = [];
        foreach ($this->capabilities() as $name => $capability) {
            [$selector, $adapters] = $this->harness();
            $adapter = $adapters[$name];
            $outcome = ScalpingShadowRuntimeTest::fixtureRuntime(adapters: $selector)->run(
                ScalpingShadowRuntimeTest::fixtureRequest(
                    'scalping.trend_continuation.long',
                    'long',
                    capability: $capability,
                ),
            );
            self::assertSame('planned', $outcome->status);
            self::assertNotNull($outcome->orderPlan);
            self::assertNotNull($outcome->reservation);
            $plan = $outcome->orderPlan;
            $reservation = $outcome->reservation;
            $partialQuantity = 0.1;
            $fill = new CanonicalPortfolioFill(
                $reservation->scope,
                $reservation->decisionKey,
                $reservation->planHash,
                $reservation->admissionHash,
                'parity-partial-fill',
                $partialQuantity,
                $plan->entryPrice,
                $plan->entryFee * ($partialQuantity / $plan->quantity),
                $partialQuantity,
                $plan->quantity - $partialQuantity,
                new \DateTimeImmutable('2026-08-10T12:00:44+00:00'),
                'sha256:' . str_repeat('4', 64),
            );
            $filled = $adapter->applyFill($reservation, $fill);
            $cancelled = $adapter->cancelResidual(
                $filled,
                new \DateTimeImmutable('2026-08-10T12:01:15+00:00'),
                'sha256:' . str_repeat('5', 64),
            );
            $expired = $adapter->enforceHoldingDeadline(
                $cancelled,
                new \DateTimeImmutable('2026-08-10T14:00:00+00:00'),
                'sha256:' . str_repeat('6', 64),
            );

            $normalized[] = [
                'status' => $outcome->status,
                'reason' => $outcome->reasonCode,
                'config_hash' => $outcome->lineage->configHash,
                'plan_hash' => $plan->planHash,
                'admission_hash' => $reservation->admissionHash,
                'reservation_hash' => $reservation->stateHash,
                'plan_costs' => [
                    'entry_fee' => $plan->entryFee,
                    'stop_exit_fee' => $plan->stopExitFee,
                    'entry_spread_cost' => $plan->entrySpreadCost,
                    'stop_spread_cost' => $plan->stopSpreadCost,
                    'entry_slippage_cost' => $plan->entrySlippageCost,
                    'stop_slippage_cost' => $plan->stopSlippageCost,
                    'funding_cost' => $plan->fundingCost,
                    'targets' => array_map(
                        static fn (CanonicalOrderPlanTarget $target): array => $target->toArray(),
                        $plan->targets,
                    ),
                ],
                'applied_fill_hashes' => $filled->appliedFillHashes,
                'fill_transition_hash' => $filled->stateHash,
                'cancel_transition_hash' => $cancelled->stateHash,
                'holding_transition_hash' => $expired->stateHash,
                'transition_inputs' => $expired->transitionInputHashes,
                'status_transition' => [$reservation->status, $filled->status, $cancelled->status, $expired->status],
                'required_action' => $expired->requiredAction,
                'filled_entry_notional_quote' => $expired->filledEntryNotionalQuote,
                'accumulated_entry_fee_quote' => $expired->accumulatedEntryFeeQuote,
                'accumulated_gross_stop_loss_quote' => $expired->accumulatedGrossStopLossQuote,
                'filled_risk_quote' => $expired->filledRiskQuote,
                'planned_funding_cost_quote' => $expired->plannedFundingCostQuote,
            ];
        }

        self::assertCount(1, array_unique(array_map(
            static fn (array $decision): string => json_encode($decision, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $normalized,
        )));
    }

    /** @return array{CanonicalPortfolioAdapterSelector, array{fake: FakeCanonicalPortfolioAdapter, paper: PaperCanonicalPortfolioAdapter, backtest: BacktestCanonicalPortfolioAdapter}} */
    private function harness(): array
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

        return [
            new CanonicalPortfolioAdapterSelector($fake, $paper, $backtest),
            ['fake' => $fake, 'paper' => $paper, 'backtest' => $backtest],
        ];
    }

    /** @return array<string, ShadowExecutionCapability> */
    private function capabilities(): array
    {
        return [
            'fake' => ShadowExecutionCapability::Fake,
            'paper' => ShadowExecutionCapability::Paper,
            'backtest' => ShadowExecutionCapability::Backtest,
        ];
    }
}
