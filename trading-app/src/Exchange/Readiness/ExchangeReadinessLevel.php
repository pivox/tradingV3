<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

enum ExchangeReadinessLevel: string
{
    case NotReady = 'not_ready';
    case PublicReadOnly = 'public_read_only';
    case PrivateReadOnly = 'private_read_only';
    case LocalDryRunReady = 'local_dry_run_ready';
    case DemoTestnetCandidate = 'demo_testnet_candidate';
    case DemoTestnetEnabled = 'demo_testnet_enabled';
}
