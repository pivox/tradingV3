<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;

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
        $decision = $engine->admit($request);
        $plan = $request->plan;
        $scopeKey = $this->scopeKey($decision->scope);
        $reservationKey = $scopeKey . '|' . $decision->decisionKey;
        $existing = $this->reservations[$reservationKey] ?? null;
        if ($existing instanceof CanonicalPortfolioReservation) {
            if (
                $existing->admissionHash !== $decision->reservationHash
                || $existing->planHash !== $plan->planHash
                || !isset($this->plans[$reservationKey])
                || $this->plans[$reservationKey]->planHash !== $plan->planHash
            ) {
                throw new CanonicalPortfolioException('canonical_portfolio_reservation_identity_conflict');
            }

            return $existing;
        }
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
}
