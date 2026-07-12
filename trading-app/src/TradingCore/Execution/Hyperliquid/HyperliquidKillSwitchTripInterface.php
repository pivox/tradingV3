<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

interface HyperliquidKillSwitchTripInterface
{
    public function isTripped(): bool;

    /** @param array<string, mixed> $auditContext */
    public function trip(string $reason, array $auditContext): void;
}
