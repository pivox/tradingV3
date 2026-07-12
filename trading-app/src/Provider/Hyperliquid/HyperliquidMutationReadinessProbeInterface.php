<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Exchange\Readiness\ExchangeReadinessReport;

interface HyperliquidMutationReadinessProbeInterface
{
    public function current(): ExchangeReadinessReport;
}
