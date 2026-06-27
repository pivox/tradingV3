<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

enum ExchangeRuntimeEnvironment: string
{
    case LOCAL_DRY_RUN = 'local_dry_run';
    case DEMO = 'demo';
    case TESTNET = 'testnet';
    case MAINNET = 'mainnet';

    public function isDemoOrTestnet(): bool
    {
        return $this === self::DEMO || $this === self::TESTNET;
    }
}
