<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;

final readonly class CanonicalOrderPlanBuildRequest
{
    public function __construct(
        public CanonicalExecutionPolicy $policy,
        public CanonicalEntryZone $zone,
        public CanonicalProtectionDecision $protection,
        public CanonicalRiskCalculationRequest $riskRequest,
        public CanonicalRiskDecision $risk,
        public CanonicalNetRDecision $netR,
        public CanonicalExecutionCostSnapshot $costs,
    ) {
    }
}
