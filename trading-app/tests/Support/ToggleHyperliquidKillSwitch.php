<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;

final class ToggleHyperliquidKillSwitch implements HyperliquidKillSwitchTripInterface
{
    public function __construct(public bool $tripped = false)
    {
    }

    public function isTripped(): bool
    {
        return $this->tripped;
    }

    public function trip(string $reason, array $auditContext): void
    {
        $this->tripped = true;
    }
}
