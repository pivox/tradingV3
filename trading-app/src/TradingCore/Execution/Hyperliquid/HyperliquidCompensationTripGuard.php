<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final class HyperliquidCompensationTripGuard
{
    private bool $attempted = false;

    /** @param array<string, mixed> $auditContext */
    public function __construct(
        private readonly HyperliquidKillSwitchTripInterface $killSwitch,
        private readonly array $auditContext,
    ) {
    }

    public function trip(): void
    {
        if ($this->attempted) {
            return;
        }

        $this->attempted = true;
        $this->killSwitch->trip('hyperliquid_compensation_unconfirmed', $this->auditContext);
    }
}
