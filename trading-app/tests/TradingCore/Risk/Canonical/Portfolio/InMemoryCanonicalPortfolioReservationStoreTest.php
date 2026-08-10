<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Risk\Canonical\Portfolio\InMemoryCanonicalPortfolioReservationStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(InMemoryCanonicalPortfolioReservationStore::class)]
final class InMemoryCanonicalPortfolioReservationStoreTest extends TestCase
{
    public function testReserveIsAtomicAndSameDecisionIsIdempotent(): void
    {
        [$decision, $plan] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();

        $first = $store->reserve($decision, $plan);
        $second = $store->reserve($decision, $plan);

        self::assertSame($first, $second);
        self::assertSame($plan, $store->plan($decision->scope, $decision->decisionKey));
        self::assertSame(2, $store->scopeVersion($decision->scope));
    }

    public function testEquivalentReconstructedPlanIsAnIdempotentRetry(): void
    {
        [$decision, $plan] = $this->admission('decision-1', 1);
        $copy = unserialize(serialize($plan));
        self::assertNotSame($plan, $copy);
        $store = new InMemoryCanonicalPortfolioReservationStore();

        $first = $store->reserve($decision, $plan);

        self::assertSame($first, $store->reserve($decision, $copy));
    }

    public function testStaleAdmissionCannotRaceACommittedReservation(): void
    {
        [$first, $firstPlan] = $this->admission('decision-1', 1);
        [$stale, $stalePlan] = $this->admission('decision-2', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $store->reserve($first, $firstPlan);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_reservation_state_conflict');
        $store->reserve($stale, $stalePlan);
    }

    public function testTransitionSaveUsesReservationCompareAndSwap(): void
    {
        [$decision, $plan] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $current = $store->reserve($decision, $plan);
        $next = $current->cancelResidual(
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('a', 64),
        );

        self::assertSame($next, $store->save($current, $next));
        self::assertSame(3, $store->scopeVersion($decision->scope));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_reservation_state_conflict');
        $store->save($current, $next);
    }

    public function testTransitionCannotReplaceCommittedHistoryWithAnotherBranch(): void
    {
        [$decision, $plan] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $current = $store->reserve($decision, $plan);
        $committed = $current->cancelResidual(
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('a', 64),
        );
        $foreignBranch = $current->cancelResidual(
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('b', 64),
        )->close(
            new \DateTimeImmutable('2026-08-10T12:00:02+00:00'),
            'sha256:' . str_repeat('c', 64),
        );
        $store->save($current, $committed);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_reservation_state_conflict');
        $store->save($committed, $foreignBranch);
    }

    /** @return array{\App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservationDecision, \App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan} */
    private function admission(string $decisionKey, int $stateVersion): array
    {
        $policy = CanonicalPortfolioFixture::policy();
        $plan = CanonicalPortfolioFixture::plan();
        $scope = new CanonicalPortfolioScope('paper-mainnet', 'fake', 'test', 'account-1', 'day_trading', 'USDT');
        $snapshot = new CanonicalPortfolioSnapshot(
            $scope,
            'golden_fixture',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            0.0,
            0.0,
            0,
            0,
            0.0,
            0.0,
            0.0,
            [],
            $stateVersion,
            'sha256:' . str_repeat('8', 64),
        );
        $decision = (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))
            ->admit(new CanonicalPortfolioAdmissionRequest($policy, $plan, $scope, $snapshot, $decisionKey));

        return [$decision, $plan];
    }
}
