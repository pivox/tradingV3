<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio\Adapter;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionEngine;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservationDecision;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservationStoreInterface;

abstract class AbstractCanonicalPortfolioAdapter implements CanonicalPortfolioAdapterInterface
{
    public function __construct(
        private readonly CanonicalPortfolioAdmissionEngine $admissionEngine,
        private readonly CanonicalPortfolioReservationStoreInterface $reservationStore,
    ) {
    }

    final public function admit(CanonicalPortfolioAdmissionRequest $request): CanonicalPortfolioReservationDecision
    {
        return $this->admissionEngine->admit($request);
    }

    final public function reserve(
        CanonicalPortfolioReservationDecision $decision,
        CanonicalOrderPlan $plan,
    ): CanonicalPortfolioReservation {
        return $this->reservationStore->reserve($decision, $plan);
    }

    final public function applyFill(
        CanonicalPortfolioReservation $reservation,
        CanonicalPortfolioFill $fill,
    ): CanonicalPortfolioReservation {
        $next = $reservation->applyFill($fill);

        return $next === $reservation ? $reservation : $this->reservationStore->save($reservation, $next);
    }

    final public function cancelResidual(
        CanonicalPortfolioReservation $reservation,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        $next = $reservation->cancelResidual($observedAt, $inputHash);

        return $next === $reservation ? $reservation : $this->reservationStore->save($reservation, $next);
    }

    final public function acknowledgeResidualReduction(
        CanonicalPortfolioReservation $reservation,
        float $venueRemainingQuantity,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        $next = $reservation->acknowledgeResidualReduction($venueRemainingQuantity, $observedAt, $inputHash);

        return $next === $reservation ? $reservation : $this->reservationStore->save($reservation, $next);
    }

    final public function close(
        CanonicalPortfolioReservation $reservation,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        $next = $reservation->close($observedAt, $inputHash);

        return $next === $reservation ? $reservation : $this->reservationStore->save($reservation, $next);
    }
}
