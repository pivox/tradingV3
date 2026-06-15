<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Enum;

enum ExecutionStatus: string
{
    case DryRun = 'dry_run';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
