<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidCompensationInterface
{
    public function compensate(HyperliquidCompensationContext $context): HyperliquidCompensationResult;
}
