<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

final class HyperliquidDurableTripPersistenceException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('hyperliquid_durable_trip_persistence_failed');
    }
}
