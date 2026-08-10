<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;

interface CanonicalPortfolioReservationStoreInterface
{
    public function reserve(
        CanonicalPortfolioReservationDecision $decision,
        CanonicalOrderPlan $plan,
    ): CanonicalPortfolioReservation;

    public function save(
        CanonicalPortfolioReservation $expected,
        CanonicalPortfolioReservation $next,
    ): CanonicalPortfolioReservation;

    public function scopeVersion(CanonicalPortfolioScope $scope): int;

    public function plan(CanonicalPortfolioScope $scope, string $decisionKey): ?CanonicalOrderPlan;
}
