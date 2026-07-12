<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final readonly class HyperliquidLeveragePolicy
{
    public function __construct(private bool $allowUnknownObservedLeverage = false)
    {
    }

    /** @return list<string> */
    public function blockingReasons(?int $observedLeverage): array
    {
        if ($observedLeverage === null) {
            return $this->allowUnknownObservedLeverage ? [] : ['hyperliquid_observed_leverage_required'];
        }

        return $observedLeverage > 0 ? [] : ['hyperliquid_observed_leverage_invalid'];
    }

    public function requiresUpdate(?int $observedLeverage, int $requestedLeverage): bool
    {
        return $observedLeverage === null || $observedLeverage !== $requestedLeverage;
    }
}
