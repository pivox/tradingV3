<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservationDecision;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalPortfolioAdmissionEngine::class)]
final class CanonicalPortfolioAdmissionEngineTest extends TestCase
{
    public function testReservationDecisionCannotBeConstructedOutsideAdmissionAuthority(): void
    {
        $constructor = (new \ReflectionClass(CanonicalPortfolioReservationDecision::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testAcceptsAgainstMostRestrictiveDailyCapAndBuildsStableReservation(): void
    {
        $request = $this->request();
        $engine = new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00'));

        $first = $engine->admit($request);
        $second = $engine->admit($request);

        self::assertSame(30.0, $first->effectiveDailyLossCapQuote);
        self::assertSame(10.0, $first->consumedDailyLossQuote);
        self::assertSame(20.0, $first->remainingDailyLossBeforeCandidateQuote);
        self::assertSame($request->plan->totalStopLoss, $first->reservedRiskQuote);
        self::assertSame($request->plan->positionNotional, $first->reservedNotionalQuote);
        self::assertSame(3, $first->projectedConcurrentPositions);
        self::assertSame(1, $first->expectedStateVersion);
        self::assertSame($first->reservationHash, $second->reservationHash);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $first->reservationHash);
    }

    public function testRejectsWhenAbsoluteDailyLossCapacityCannotReserveCandidate(): void
    {
        $request = $this->request(snapshot: $this->snapshot(realizedNetPnlQuote: -25.0, reservedRiskQuote: 1.0));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_daily_loss_exceeded');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsWhenPercentageDailyCapIsMoreRestrictiveThanAbsoluteCap(): void
    {
        $policySnapshot = CanonicalPortfolioFixture::snapshot([
            'daily_loss_cap' => $this->dailyLossDecision(1.0, 100.0, true),
        ]);
        $request = $this->request(
            policySnapshot: $policySnapshot,
            snapshot: $this->snapshot(realizedNetPnlQuote: -6.0, unrealizedNetPnlQuote: 0.0, reservedRiskQuote: 0.0),
        );

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_daily_loss_exceeded');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testExplicitlyExcludedUnrealizedLossDoesNotConsumeDailyCap(): void
    {
        $policySnapshot = CanonicalPortfolioFixture::snapshot([
            'daily_loss_cap' => $this->dailyLossDecision(6.0, 30.0, false),
        ]);
        $request = $this->request(
            policySnapshot: $policySnapshot,
            snapshot: $this->snapshot(unrealizedNetPnlQuote: -100.0),
        );

        $decision = (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);

        self::assertSame(8.0, $decision->consumedDailyLossQuote);
    }

    public function testRejectsConcurrentCandidateIncludingPendingEntry(): void
    {
        $request = $this->request(snapshot: $this->snapshot(openPositions: 3, pendingEntries: 1));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_concurrency_exceeded');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsProjectedModeExposureIncludingReservation(): void
    {
        $snapshot = CanonicalPortfolioFixture::snapshot([
            'mode_exposure_cap' => [
                'state' => 'defined',
                'value' => 30.0,
                'unit' => 'percent_equity_notional',
            ],
        ]);
        $request = $this->request(
            policySnapshot: $snapshot,
            snapshot: $this->snapshot(openNotionalQuote: 100.0, pendingNotionalQuote: 50.0),
        );

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_mode_exposure_exceeded');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsPortfolioArithmeticThatCannotBeRepresentedAsFiniteOutput(): void
    {
        $policySnapshot = CanonicalPortfolioFixture::snapshot([
            'mode_exposure_cap' => [
                'state' => 'defined',
                'value' => 1.0e308,
                'unit' => 'percent_equity_notional',
            ],
        ]);
        $request = $this->request(policySnapshot: $policySnapshot);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_arithmetic_invalid');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testAdmissionIdentityIsStableAcrossProcessingTimeRetries(): void
    {
        $request = $this->request();

        $first = (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
        $retry = (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:01+00:00')))->admit($request);

        self::assertSame($first->reservationHash, $retry->reservationHash);
        self::assertNotEquals($first->createdAt, $retry->createdAt);
    }

    public function testRejectsStalePortfolioState(): void
    {
        $request = $this->request(snapshot: $this->snapshot(observedAt: '2026-08-10T11:58:59+00:00'));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_state_stale');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsPlanThatIsNoLongerValidAtAdmissionTime(): void
    {
        $request = $this->request(snapshot: $this->snapshot(observedAt: '2026-08-10T12:00:30+00:00'));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_plan_invalid');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:31+00:00')))->admit($request);
    }

    public function testRejectsPortfolioStateObservedInTheFuture(): void
    {
        $request = $this->request(snapshot: $this->snapshot(observedAt: '2026-08-10T12:00:00.000001+00:00'));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_state_future');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsSnapshotFromAnotherAtomicAccountScope(): void
    {
        $foreignScope = new CanonicalPortfolioScope('paper-mainnet', 'fake', 'test', 'account-2', 'day_trading', 'USDT');
        $snapshot = new CanonicalPortfolioSnapshot(
            $foreignScope,
            'golden_fixture',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            -5.0,
            -2.0,
            1,
            1,
            100.0,
            50.0,
            3.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );
        $request = $this->request(snapshot: $snapshot);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_scope_mismatch');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsPortfolioEquityThatDiffersFromRiskSizingInput(): void
    {
        $request = $this->request(snapshot: $this->snapshot(equityQuote: 999.0));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_scope_mismatch');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsSnapshotWhosePolicyDayDoesNotMatchConfiguredBoundary(): void
    {
        $snapshot = new CanonicalPortfolioSnapshot(
            $this->scope(),
            'golden_fixture',
            '1.0.0',
            new \DateTimeImmutable('2026-08-09T12:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T12:00:00+00:00'),
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            1000.0,
            -5.0,
            -2.0,
            1,
            1,
            100.0,
            50.0,
            3.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );
        $request = $this->request(snapshot: $snapshot);

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_state_day_mismatch');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    public function testRejectsDuplicateDecisionKeyAlreadyInAtomicState(): void
    {
        $request = $this->request(snapshot: $this->snapshot(activeDecisionKeys: ['decision-1']));

        $this->expectException(CanonicalPortfolioException::class);
        $this->expectExceptionMessage('canonical_portfolio_reservation_duplicate');
        (new CanonicalPortfolioAdmissionEngine(new MockClock('2026-08-10T12:00:00+00:00')))->admit($request);
    }

    private function request(
        ?\App\TradingCore\Config\EffectiveTradingConfigSnapshot $policySnapshot = null,
        ?CanonicalPortfolioSnapshot $snapshot = null,
    ): CanonicalPortfolioAdmissionRequest {
        $policySnapshot ??= CanonicalPortfolioFixture::snapshot();

        return new CanonicalPortfolioAdmissionRequest(
            CanonicalPortfolioFixture::policy($policySnapshot),
            CanonicalPortfolioFixture::plan($policySnapshot),
            $this->scope(),
            $snapshot ?? $this->snapshot(),
            'decision-1',
        );
    }

    /** @param list<string> $activeDecisionKeys */
    private function snapshot(
        float $equityQuote = 1000.0,
        float $realizedNetPnlQuote = -5.0,
        float $unrealizedNetPnlQuote = -2.0,
        int $openPositions = 1,
        int $pendingEntries = 1,
        float $openNotionalQuote = 100.0,
        float $pendingNotionalQuote = 50.0,
        float $reservedRiskQuote = 3.0,
        string $observedAt = '2026-08-10T11:59:50+00:00',
        array $activeDecisionKeys = [],
    ): CanonicalPortfolioSnapshot {
        return new CanonicalPortfolioSnapshot(
            $this->scope(),
            'golden_fixture',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00+00:00'),
            new \DateTimeImmutable('2026-08-11T00:00:00+00:00'),
            new \DateTimeImmutable($observedAt),
            $equityQuote,
            $realizedNetPnlQuote,
            $unrealizedNetPnlQuote,
            $openPositions,
            $pendingEntries,
            $openNotionalQuote,
            $pendingNotionalQuote,
            $reservedRiskQuote,
            $activeDecisionKeys,
            1,
            'sha256:' . str_repeat('8', 64),
        );
    }

    private function scope(): CanonicalPortfolioScope
    {
        return new CanonicalPortfolioScope('paper-mainnet', 'fake', 'test', 'account-1', 'day_trading', 'USDT');
    }

    /** @return array<string, mixed> */
    private function dailyLossDecision(float $percent, float $absolute, bool $includeUnrealized): array
    {
        return [
            'state' => 'defined',
            'value' => [
                'percent_equity' => $percent,
                'absolute_quote' => $absolute,
                'quote_currency' => 'USDT',
                'day_timezone' => 'UTC',
                'day_boundary_local' => '00:00:00',
                'include_unrealized_loss' => $includeUnrealized,
            ],
            'unit' => 'compound_percent_equity_and_quote_per_day',
        ];
    }
}
