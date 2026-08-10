<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class InMemoryCanonicalPortfolioReservationStore implements CanonicalPortfolioReservationStoreInterface
{
    /** @var array<string, CanonicalPortfolioReservation> */
    private array $reservations = [];

    /** @var array<string, CanonicalOrderPlan> */
    private array $plans = [];

    /** @var array<string, int> */
    private array $scopeVersions = [];

    public function reserve(
        CanonicalPortfolioAdmissionRequest $request,
        CanonicalPortfolioAdmissionEngine $engine,
    ): CanonicalPortfolioReservation {
        $plan = $request->plan;
        $scopeKey = $this->scopeKey($request->scope);
        $reservationKey = $scopeKey . '|' . $request->decisionKey;
        $existing = $this->reservations[$reservationKey] ?? null;
        if ($existing instanceof CanonicalPortfolioReservation) {
            try {
                $expectedPlanHash = $plan->expectedPlanHash();
            } catch (\Throwable $exception) {
                throw new CanonicalPortfolioException('canonical_portfolio_reservation_identity_conflict', [], $exception);
            }
            if (
                !hash_equals($expectedPlanHash, $plan->planHash)
                || $existing->planHash !== $plan->planHash
                || $existing->configHash !== $request->policy->configHash
                || $existing->portfolioInputHash !== $request->snapshot->inputHash
                || $existing->portfolioSnapshotIdentityHash !== $request->snapshot->identityHash()
                || $existing->portfolioSource !== $request->snapshot->source
                || $existing->portfolioSourceVersion !== $request->snapshot->sourceVersion
                || $existing->scope != $request->scope
                || !isset($this->plans[$reservationKey])
                || $this->plans[$reservationKey]->planHash !== $plan->planHash
            ) {
                throw new CanonicalPortfolioException('canonical_portfolio_reservation_identity_conflict');
            }

            return $existing;
        }
        $this->assertSnapshotIncludesCommittedState($request->snapshot);
        $decision = $engine->admit($request);
        if ($decision->expectedStateVersion !== ($this->scopeVersions[$scopeKey] ?? 1)) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_state_conflict');
        }

        $reservation = CanonicalPortfolioReservation::open($decision, $plan);
        $this->reservations[$reservationKey] = $reservation;
        $this->plans[$reservationKey] = $plan;
        $this->scopeVersions[$scopeKey] = $decision->expectedStateVersion + 1;

        return $reservation;
    }

    public function applyFill(
        CanonicalPortfolioReservation $expected,
        CanonicalPortfolioFill $fill,
    ): CanonicalPortfolioReservation {
        return $this->transition($expected, static fn (CanonicalPortfolioReservation $stored): CanonicalPortfolioReservation => $stored->applyFill($fill));
    }

    public function cancelResidual(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        return $this->transition($expected, static fn (CanonicalPortfolioReservation $stored): CanonicalPortfolioReservation => $stored->cancelResidual($observedAt, $inputHash));
    }

    public function acknowledgeResidualReduction(
        CanonicalPortfolioReservation $expected,
        float $venueRemainingQuantity,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        return $this->transition($expected, static fn (CanonicalPortfolioReservation $stored): CanonicalPortfolioReservation => $stored->acknowledgeResidualReduction($venueRemainingQuantity, $observedAt, $inputHash));
    }

    public function close(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        return $this->transition($expected, static fn (CanonicalPortfolioReservation $stored): CanonicalPortfolioReservation => $stored->close($observedAt, $inputHash));
    }

    /** @param \Closure(CanonicalPortfolioReservation): CanonicalPortfolioReservation $transition */
    private function transition(CanonicalPortfolioReservation $expected, \Closure $transition): CanonicalPortfolioReservation
    {
        $scopeKey = $this->scopeKey($expected->scope);
        $reservationKey = $scopeKey . '|' . $expected->decisionKey;
        $stored = $this->reservations[$reservationKey] ?? null;
        if (
            !$stored instanceof CanonicalPortfolioReservation
            || !hash_equals($expected->expectedStateHash(), $expected->stateHash)
            || !hash_equals($stored->stateHash, $expected->stateHash)
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_state_conflict');
        }
        $next = $transition($stored);
        if ($next === $stored) {
            return $stored;
        }
        if (
            $next->scope != $expected->scope
            || $next->decisionKey !== $expected->decisionKey
            || $next->admissionHash !== $expected->admissionHash
            || $next->version !== $expected->version + 1
            || $next->previousStateHash !== $expected->stateHash
            || !hash_equals($next->expectedStateHash(), $next->stateHash)
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_reservation_state_conflict');
        }

        $this->reservations[$reservationKey] = $next;
        $this->scopeVersions[$scopeKey] = ($this->scopeVersions[$scopeKey] ?? 1) + 1;

        return $next;
    }

    public function scopeVersion(CanonicalPortfolioScope $scope): int
    {
        return $this->scopeVersions[$this->scopeKey($scope)] ?? 1;
    }

    public function plan(CanonicalPortfolioScope $scope, string $decisionKey): ?CanonicalOrderPlan
    {
        return $this->plans[$this->scopeKey($scope) . '|' . $decisionKey] ?? null;
    }

    private function scopeKey(CanonicalPortfolioScope $scope): string
    {
        return hash('sha256', CanonicalPortfolioDecimal::encode(
            $scope->toArray(),
            'canonical_portfolio_scope_hash_invalid',
        ));
    }

    private function assertSnapshotIncludesCommittedState(CanonicalPortfolioSnapshot $snapshot): void
    {
        $scopeKey = $this->scopeKey($snapshot->scope);
        $reservedRisk = BigDecimal::zero();
        $openNotional = BigDecimal::zero();
        $pendingNotional = BigDecimal::zero();
        $openPositions = 0;
        $pendingEntries = 0;
        $activeDecisionKeys = [];
        foreach ($this->reservations as $reservationKey => $reservation) {
            if (!str_starts_with($reservationKey, $scopeKey . '|') || $reservation->status === 'closed') {
                continue;
            }
            $filledRisk = BigDecimal::of($reservation->filledRiskDecimal);
            $residualRisk = BigDecimal::of($reservation->residualRiskDecimal);
            $originalRisk = CanonicalPortfolioDecimal::fromFloat(
                $reservation->reservedRiskQuote,
                'canonical_portfolio_state_unreconciled',
            );
            $committedRisk = $filledRisk->plus($residualRisk);
            $venueExceedsAuthorized = BigDecimal::of($reservation->venueRemainingQuantityDecimal)
                ->isGreaterThan(BigDecimal::of($reservation->remainingQuantityDecimal));
            $riskToReserve = $venueExceedsAuthorized && $originalRisk->isGreaterThan($committedRisk)
                ? $originalRisk
                : $committedRisk;
            $filledNotional = BigDecimal::of($reservation->filledNotionalDecimal);
            if ($riskToReserve->isZero() && $filledNotional->isZero() && $reservation->venueRemainingQuantity === 0.0) {
                continue;
            }
            $reservedRisk = $reservedRisk->plus(
                $riskToReserve,
            );
            $openNotional = $openNotional->plus($filledNotional);
            if ($reservation->filledQuantity > 0.0) {
                ++$openPositions;
            }
            if ($reservation->venueRemainingQuantity > 0.0) {
                ++$pendingEntries;
                $pendingNotional = $pendingNotional->plus(
                    CanonicalPortfolioDecimal::fromFloat(
                        $reservation->reservedNotionalQuote,
                        'canonical_portfolio_state_unreconciled',
                    )
                        ->multipliedBy(BigDecimal::of($reservation->venueRemainingQuantityDecimal))
                        ->dividedBy(
                            CanonicalPortfolioDecimal::fromFloat(
                                $reservation->plannedQuantity,
                                'canonical_portfolio_state_unreconciled',
                            ),
                            24,
                            RoundingMode::UP,
                        ),
                );
            }
            $activeDecisionKeys[] = $reservation->decisionKey;
        }

        $snapshotKeys = array_fill_keys($snapshot->activeDecisionKeys, true);
        foreach ($activeDecisionKeys as $decisionKey) {
            if (!isset($snapshotKeys[$decisionKey])) {
                throw new CanonicalPortfolioException('canonical_portfolio_state_unreconciled');
            }
        }
        if (
            $snapshot->openPositions < $openPositions
            || $snapshot->pendingEntries < $pendingEntries
            || CanonicalPortfolioDecimal::fromFloat($snapshot->reservedRiskQuote, 'canonical_portfolio_state_unreconciled')->isLessThan($reservedRisk)
            || CanonicalPortfolioDecimal::fromFloat($snapshot->openNotionalQuote, 'canonical_portfolio_state_unreconciled')->isLessThan($openNotional)
            || CanonicalPortfolioDecimal::fromFloat($snapshot->pendingNotionalQuote, 'canonical_portfolio_state_unreconciled')->isLessThan($pendingNotional)
        ) {
            throw new CanonicalPortfolioException('canonical_portfolio_state_unreconciled');
        }
    }
}
