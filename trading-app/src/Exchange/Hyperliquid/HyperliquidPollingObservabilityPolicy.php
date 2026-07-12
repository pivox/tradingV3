<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use App\Common\Enum\Exchange;
use Psr\Clock\ClockInterface;

final readonly class HyperliquidPollingObservabilityPolicy
{
    private const TESTNET_ENDPOINT = 'https://api.hyperliquid-testnet.xyz';
    private const MAX_AGE_MILLISECONDS = 2_000;

    public function __construct(private ClockInterface $clock)
    {
    }

    /** @return list<string> */
    public function blockingReasons(HyperliquidPollingObservabilityStatus $status): array
    {
        $reasons = [];

        $status->exchange === Exchange::HYPERLIQUID || $reasons[] = 'hyperliquid_poll_exchange_required';
        $status->environment === 'testnet' || $reasons[] = 'hyperliquid_poll_testnet_environment_required';
        $status->endpoint === self::TESTNET_ENDPOINT || $reasons[] = 'hyperliquid_poll_testnet_endpoint_required';
        $status->initialSnapshotLoaded || $reasons[] = 'hyperliquid_poll_initial_snapshot_not_loaded';
        $status->ordersReady || $reasons[] = 'hyperliquid_orders_poll_not_ready';
        $status->fillsReady || $reasons[] = 'hyperliquid_fills_poll_not_ready';
        $status->positionsReady || $reasons[] = 'hyperliquid_positions_poll_not_ready';
        !$status->reconciliationInFlight || $reasons[] = 'hyperliquid_reconciliation_in_flight';

        $age = $this->milliseconds($this->clock->now()) - $this->milliseconds($status->observedAt);
        if ($age < 0) {
            $reasons[] = 'hyperliquid_poll_observed_at_in_future';
        } elseif ($age > self::MAX_AGE_MILLISECONDS) {
            $reasons[] = 'hyperliquid_poll_snapshot_stale';
        }

        return array_values(array_unique($reasons));
    }

    private function milliseconds(\DateTimeInterface $dateTime): int
    {
        return ((int) $dateTime->format('U') * 1_000) + (int) $dateTime->format('v');
    }
}
