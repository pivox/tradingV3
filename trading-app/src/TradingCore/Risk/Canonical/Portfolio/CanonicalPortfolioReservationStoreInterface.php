<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;

interface CanonicalPortfolioReservationStoreInterface
{
    public function reserve(
        CanonicalPortfolioAdmissionRequest $request,
        CanonicalPortfolioAdmissionEngine $engine,
    ): CanonicalPortfolioReservation;

    public function applyFill(
        CanonicalPortfolioReservation $expected,
        CanonicalPortfolioFill $fill,
    ): CanonicalPortfolioReservation;

    public function cancelResidual(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation;

    public function enforceHoldingDeadline(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation;

    public function acknowledgeResidualReduction(
        CanonicalPortfolioReservation $expected,
        float $venueRemainingQuantity,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation;

    public function close(
        CanonicalPortfolioReservation $expected,
        \DateTimeImmutable $observedAt,
        string $inputHash,
    ): CanonicalPortfolioReservation;

    public function scopeVersion(CanonicalPortfolioScope $scope): int;

    public function plan(CanonicalPortfolioScope $scope, string $decisionKey): ?CanonicalOrderPlan;
}
