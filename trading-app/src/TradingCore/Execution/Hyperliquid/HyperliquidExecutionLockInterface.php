<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidExecutionLockInterface
{
    public function acquire(): ?HyperliquidExecutionLockLeaseInterface;

    public function isInFlight(): bool;
}
