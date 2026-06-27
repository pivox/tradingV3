<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

enum DemoTradingSafetyLevel: string
{
    case Blocked = 'blocked';
    case LocalDryRun = 'local_dry_run';
    case DemoTestnetCandidate = 'demo_testnet_candidate';
    case DemoTestnetEnabled = 'demo_testnet_enabled';
}
