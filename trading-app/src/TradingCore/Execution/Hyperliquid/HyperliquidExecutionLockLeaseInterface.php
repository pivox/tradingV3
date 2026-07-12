<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidExecutionLockLeaseInterface
{
    public function release(): void;

    public function retain(): void;
}
