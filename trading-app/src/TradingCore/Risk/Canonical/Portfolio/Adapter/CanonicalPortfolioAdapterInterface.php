<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio\Adapter;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioAdmissionRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioFill;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservation;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioReservationDecision;

interface CanonicalPortfolioAdapterInterface
{
    public function admit(CanonicalPortfolioAdmissionRequest $request): CanonicalPortfolioReservationDecision;

    public function reserve(
        CanonicalPortfolioReservationDecision $decision,
        CanonicalOrderPlan $plan,
    ): CanonicalPortfolioReservation;

    public function applyFill(
        CanonicalPortfolioReservation $reservation,
        CanonicalPortfolioFill $fill,
    ): CanonicalPortfolioReservation;

    public function cancelResidual(
        CanonicalPortfolioReservation $reservation,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation;

    public function acknowledgeResidualReduction(
        CanonicalPortfolioReservation $reservation,
        float $venueRemainingQuantity,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation;

    public function close(
        CanonicalPortfolioReservation $reservation,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation;
}
