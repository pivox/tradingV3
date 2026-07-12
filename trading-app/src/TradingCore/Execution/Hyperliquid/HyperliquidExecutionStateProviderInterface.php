<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidExecutionStateProviderInterface
{
    public function current(string $symbol): HyperliquidExecutionState;
}
