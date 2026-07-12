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
}
