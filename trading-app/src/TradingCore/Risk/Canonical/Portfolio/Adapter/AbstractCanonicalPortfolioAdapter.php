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
        return $this->reservationStore->save($reservation, $reservation->applyFill($fill));
    }
}
