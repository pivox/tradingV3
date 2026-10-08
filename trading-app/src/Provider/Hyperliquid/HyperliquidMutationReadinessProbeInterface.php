<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Exchange\Readiness\ExchangeReadinessReport;
use App\TradingCore\Config\EffectiveTradingConfigRequest;

interface HyperliquidMutationReadinessProbeInterface
{
    /**
     * Returns evidence for the given canonical identity; without one the
     * effective configuration stays fail-closed. Execution must later match
     * its profile and config hash before mutation.
     */
    public function current(?EffectiveTradingConfigRequest $identity = null): ExchangeReadinessReport;
}
