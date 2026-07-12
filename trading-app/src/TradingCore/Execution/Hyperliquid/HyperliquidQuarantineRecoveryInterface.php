<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidQuarantineRecoveryInterface
{
    public function recoverFallbackMarker(): HyperliquidQuarantineRecoveryStatus;
}
