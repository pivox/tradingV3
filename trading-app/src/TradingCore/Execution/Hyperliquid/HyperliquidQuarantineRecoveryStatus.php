<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

enum HyperliquidQuarantineRecoveryStatus
{
    case Transferred;
    case NoMarker;
    case RepositoryNotTripped;
}
