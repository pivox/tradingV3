<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidExecutionLockLeaseInterface
{
    public function release(): void;

    /** Retains the exclusion guard for this process lifetime; recovery requires process restart. */
    public function retain(): void;
}
