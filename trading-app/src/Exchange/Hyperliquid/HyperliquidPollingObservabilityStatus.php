<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use App\Common\Enum\Exchange;

final readonly class HyperliquidPollingObservabilityStatus
{
    public function __construct(
        public Exchange $exchange,
        public string $environment,
        public string $endpoint,
        public bool $initialSnapshotLoaded,
        public bool $ordersReady,
        public bool $fillsReady,
        public bool $positionsReady,
        public bool $reconciliationInFlight,
        public \DateTimeImmutable $observedAt,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'exchange' => $this->exchange->value,
            'environment' => $this->environment,
            'endpoint' => $this->endpoint,
            'initial_snapshot_loaded' => $this->initialSnapshotLoaded,
            'orders_ready' => $this->ordersReady,
            'fills_ready' => $this->fillsReady,
            'positions_ready' => $this->positionsReady,
            'reconciliation_in_flight' => $this->reconciliationInFlight,
            'observed_at' => $this->observedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
