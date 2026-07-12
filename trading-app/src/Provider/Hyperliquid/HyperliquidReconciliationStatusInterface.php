<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

interface HyperliquidReconciliationStatusInterface
{
    public function isInFlight(): bool;
}
