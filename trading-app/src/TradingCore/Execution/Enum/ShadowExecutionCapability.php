<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Enum;

enum ShadowExecutionCapability: string
{
    case Fake = 'fake';
    case Paper = 'paper';
    case Backtest = 'backtest';
    case PrivateMainnet = 'private_mainnet';

    public function permitsShadow(): bool
    {
        return $this !== self::PrivateMainnet;
    }
}
