<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\PaperMarketEvent;

interface PaperLiveEventConsumerInterface
{
    /**
     * Implementations MUST atomically and idempotently persist the authoritative
     * (datasetId, eventId) identity, its associated payload hash, the event effect,
     * and its checkpoint. An exact identity/hash retry MUST be a no-op success;
     * the same identity with a different hash MUST fail with
     * "market_event_identity_conflict". This method MUST return only after both
     * the effect and checkpoint are durable.
     */
    public function consume(string $datasetId, PaperMarketEvent $event): void;
}
