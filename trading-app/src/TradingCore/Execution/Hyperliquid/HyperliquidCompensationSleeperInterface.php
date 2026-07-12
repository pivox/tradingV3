<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidCompensationSleeperInterface
{
    public function sleepMilliseconds(int $milliseconds): void;
}
