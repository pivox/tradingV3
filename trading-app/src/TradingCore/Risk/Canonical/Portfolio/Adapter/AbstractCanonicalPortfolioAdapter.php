<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio\Adapter;

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
        CanonicalPortfolioAdmissionRequest $request,
    ): CanonicalPortfolioReservation {
        return $this->reservationStore->reserve($request, $this->admissionEngine);
    }

    final public function applyFill(
        CanonicalPortfolioReservation $reservation,
        CanonicalPortfolioFill $fill,
    ): CanonicalPortfolioReservation {
        return $this->reservationStore->applyFill($reservation, $fill);
    }

    final public function cancelResidual(
        CanonicalPortfolioReservation $reservation,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        return $this->reservationStore->cancelResidual($reservation, $observedAt, $inputHash);
    }

    final public function enforceHoldingDeadline(
        CanonicalPortfolioReservation $reservation,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        return $this->reservationStore->enforceHoldingDeadline($reservation, $observedAt, $inputHash);
    }

    final public function acknowledgeResidualReduction(
        CanonicalPortfolioReservation $reservation,
        float $venueRemainingQuantity,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        return $this->reservationStore->acknowledgeResidualReduction(
            $reservation,
            $venueRemainingQuantity,
            $observedAt,
            $inputHash,
        );
    }

    final public function close(
        CanonicalPortfolioReservation $reservation,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation {
        return $this->reservationStore->close($reservation, $observedAt, $inputHash);
    }
}
