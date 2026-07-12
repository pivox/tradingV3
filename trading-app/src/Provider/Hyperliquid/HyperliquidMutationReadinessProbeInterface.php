<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Exchange\Readiness\ExchangeReadinessReport;

interface HyperliquidMutationReadinessProbeInterface
{
    /**
     * Returns evidence for the single effective profile configured in DI.
     * Execution must later match its profile and config hash before mutation.
     */
    public function current(): ExchangeReadinessReport;
}
