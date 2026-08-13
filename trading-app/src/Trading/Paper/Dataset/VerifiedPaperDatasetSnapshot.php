<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class VerifiedPaperDatasetSnapshot
{
    public PaperDatasetManifest $manifest;

    /** @var list<PaperMarketEvent> */
    public array $events;

    /** @param array<array-key, mixed> $events */
    public function __construct(PaperDatasetManifest $manifest, array $events)
    {
        foreach ($events as $event) {
            if (!$event instanceof PaperMarketEvent) {
                throw new \InvalidArgumentException('verified_paper_dataset_events_invalid');
            }
        }

        $this->manifest = $manifest;
        $this->events = array_values($events);
    }
}
