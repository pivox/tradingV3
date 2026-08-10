<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;

final readonly class CanonicalNetRRequest
{
    public function __construct(
        public CanonicalExecutionPolicy $policy,
        public CanonicalProtectionDecision $protection,
        public CanonicalRiskDecision $riskDecision,
        public CanonicalExecutionCostSnapshot $costs,
    ) {
    }
}
