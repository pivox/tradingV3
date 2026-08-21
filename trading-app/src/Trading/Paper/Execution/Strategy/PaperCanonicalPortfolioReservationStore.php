<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioException;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservationStoreInterface;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;

/**
 * Stateless admission boundary. The durable Paper/Fake execution store owns
 * every lifecycle transition and supplies the next authenticated snapshot.
 */
final class PaperCanonicalPortfolioReservationStore implements CanonicalPortfolioReservationStoreInterface
{
    public function reserve(
        CanonicalPortfolioAdmissionRequest $request,
        CanonicalPortfolioAdmissionEngine $engine,
    ): CanonicalPortfolioReservation {
        $reservation = CanonicalPortfolioReservation::open(
            $engine->admit($request),
            $request->plan,
        );
        $reservation->assertCanonicalOpeningState($request->plan);

        return $reservation;
    }

    public function applyFill(
        CanonicalPortfolioReservation $expected,
        CanonicalPortfolioFill $fill,
    ): CanonicalPortfolioReservation {
        $this->transitionForbidden();
    }

    public function cancelResidual(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        $this->transitionForbidden();
    }

    public function enforceHoldingDeadline(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        $this->transitionForbidden();
    }

    public function acknowledgeResidualReduction(
        CanonicalPortfolioReservation $expected,
        float $venueRemainingQuantity,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        $this->transitionForbidden();
    }

    public function close(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        $this->transitionForbidden();
    }

    public function scopeVersion(CanonicalPortfolioScope $scope): int
    {
        $this->transitionForbidden();
    }

    public function plan(CanonicalPortfolioScope $scope, string $decisionKey): ?CanonicalOrderPlan
    {
        $this->transitionForbidden();
    }

    private function transitionForbidden(): never
    {
        throw new CanonicalPortfolioException('paper_canonical_portfolio_transition_forbidden');
    }
}
