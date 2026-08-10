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
        [$request, $engine] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();

        $first = $store->reserve($request, $engine);
        $second = $store->reserve($request, $engine);

        self::assertSame($first, $second);
        self::assertSame($request->plan, $store->plan($request->scope, $request->decisionKey));
        self::assertSame(2, $store->scopeVersion($request->scope));
    }

    public function testEquivalentReconstructedPlanIsAnIdempotentRetry(): void
    {
        [$request, $engine] = $this->admission('decision-1', 1);
        $copy = unserialize(serialize($request->plan));
        self::assertNotSame($request->plan, $copy);
        $retry = new CanonicalPortfolioAdmissionRequest(
            $request->policy,
            $copy,
            $request->scope,
            $request->snapshot,
            $request->decisionKey,
        );
        $store = new InMemoryCanonicalPortfolioReservationStore();

        $first = $store->reserve($request, $engine);

        self::assertSame($first, $store->reserve($retry, $engine));
    }

    public function testStaleAdmissionCannotRaceACommittedReservation(): void
    {
        [$first, $firstEngine] = $this->admission('decision-1', 1);
        [$stale, $staleEngine] = $this->admission('decision-2', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $store->reserve($first, $firstEngine);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_state_unreconciled');
        $store->reserve($stale, $staleEngine);
    }

    public function testTransitionSaveUsesReservationCompareAndSwap(): void
    {
        [$request, $engine] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $current = $store->reserve($request, $engine);
        $next = $store->cancelResidual(
            $current,
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('a', 64),
        );

        self::assertSame(3, $store->scopeVersion($request->scope));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_reservation_state_conflict');
        $store->cancelResidual(
            $current,
            new \DateTimeImmutable('2026-08-10T12:00:02+00:00'),
            'sha256:' . str_repeat('b', 64),
        );
    }

    public function testStoreDoesNotExposeArbitraryNextStatePersistence(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(InMemoryCanonicalPortfolioReservationStore::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        self::assertNotContains('save', $methods);
    }

    public function testReserveRevalidatesFreshnessAtCommitTime(): void
    {
        [$request, $engine, $clock] = $this->admission('decision-1', 1);
        $engine->admit($request);
        $clock->sleep(600);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_plan_invalid');
        (new InMemoryCanonicalPortfolioReservationStore())->reserve($request, $engine);
    }

    public function testCommittedRetryReturnsExistingReservationAfterFreshnessDeadline(): void
    {
        [$request, $engine, $clock] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $committed = $store->reserve($request, $engine);
        $clock->sleep(600);

        self::assertSame($committed, $store->reserve($request, $engine));
    }

    public function testNewReservationCannotOmitCommittedScopeState(): void
    {
        [$first, $firstEngine] = $this->admission('decision-1', 1);
        [$omittingCommitted, $secondEngine] = $this->admission('decision-2', 2);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $store->reserve($first, $firstEngine);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_state_unreconciled');
        $store->reserve($omittingCommitted, $secondEngine);
    }

    public function testNewReservationAcceptsSnapshotThatCoversCommittedScopeState(): void
    {
        [$first, $engine] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $committed = $store->reserve($first, $engine);
        $snapshot = new CanonicalPortfolioSnapshot(
            $first->scope,
            'golden_fixture',
            '1.0.0',
            $first->snapshot->policyDayStart,
            $first->snapshot->policyDayEnd,
            $first->snapshot->observedAt,
            1000.0,
            0.0,
            0.0,
            0,
            1,
            0.0,
            $committed->reservedNotionalQuote,
            $committed->reservedRiskQuote,
            [$committed->decisionKey],
            2,
            'sha256:' . str_repeat('7', 64),
        );
        $second = new CanonicalPortfolioAdmissionRequest(
            $first->policy,
            $first->plan,
            $first->scope,
            $snapshot,
            'decision-2',
        );

        $reserved = $store->reserve($second, $engine);

        self::assertSame('decision-2', $reserved->decisionKey);
        self::assertSame(3, $store->scopeVersion($first->scope));
    }

    public function testReserveRejectsHydratedPlanWhoseContentNoLongerMatchesItsHash(): void
    {
        [$request, $engine] = $this->admission('decision-1', 1);
        $serialized = serialize($request->plan);
        $tamperedPayload = str_replace('d:' . $request->plan->quantity . ';', 'd:1.497;', $serialized, $replacements);
        self::assertGreaterThan(0, $replacements);
        $tamperedPlan = unserialize($tamperedPayload);
        $tamperedRequest = new CanonicalPortfolioAdmissionRequest(
            $request->policy,
            $tamperedPlan,
            $request->scope,
            $request->snapshot,
            $request->decisionKey,
        );

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_plan_invalid');
        (new InMemoryCanonicalPortfolioReservationStore())->reserve($tamperedRequest, $engine);
    }

    public function testTransitionRejectsHydratedExpectedStateWhoseHashWasNotRecomputed(): void
    {
        [$request, $engine] = $this->admission('decision-1', 1);
        $store = new InMemoryCanonicalPortfolioReservationStore();
        $current = $store->reserve($request, $engine);
        $serialized = serialize($current);
        $tamperedPayload = str_replace(
            'd:' . $current->reservedRiskQuote . ';',
            'd:0;',
            $serialized,
            $replacements,
        );
        self::assertGreaterThan(0, $replacements);
        $tampered = unserialize($tamperedPayload);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_reservation_state_conflict');
        $store->cancelResidual(
            $tampered,
            new \DateTimeImmutable('2026-08-10T12:00:01+00:00'),
            'sha256:' . str_repeat('a', 64),
        );
    }

    /** @return array{CanonicalPortfolioAdmissionRequest, CanonicalPortfolioAdmissionEngine, MockClock} */
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
        $clock = new MockClock('2026-08-10T12:00:00+00:00');
        $engine = new CanonicalPortfolioAdmissionEngine($clock);
        $request = new CanonicalPortfolioAdmissionRequest($policy, $plan, $scope, $snapshot, $decisionKey);

        return [$request, $engine, $clock];
    }
}
