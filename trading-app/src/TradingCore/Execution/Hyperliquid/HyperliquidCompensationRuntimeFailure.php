<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final class HyperliquidCompensationRuntimeFailure extends \RuntimeException
{
    public function __construct(public readonly HyperliquidCompensationReasonCode $reasonCode)
    {
        parent::__construct($reasonCode->value);
    }
}
