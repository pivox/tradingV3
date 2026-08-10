<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlan;

final readonly class CanonicalPortfolioAdmissionRequest
{
    public function __construct(
        public CanonicalPortfolioPolicy $policy,
        public CanonicalOrderPlan $plan,
        public CanonicalPortfolioScope $scope,
        public CanonicalPortfolioSnapshot $snapshot,
        public string $decisionKey,
    ) {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,127}\z/D', $decisionKey) !== 1) {
            throw new CanonicalPortfolioException('canonical_portfolio_decision_key_invalid');
        }
    }
}
