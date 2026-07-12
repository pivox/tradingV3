<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

final class FailClosedHyperliquidReconciliationStatus implements HyperliquidReconciliationStatusInterface
{
    public function isInFlight(): bool
    {
        return true;
    }
}
