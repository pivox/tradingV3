<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Enum;

enum ExecutionMode: string
{
    case DryRun = 'dry_run';
    case Live = 'live';
}
