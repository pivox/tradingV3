<?php

declare(strict_types=1);

namespace App\Trading\Paper\Capture;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetState;

final readonly class PaperPublicCaptureResult
{
    /** @param array<string, mixed> $payload */
    private function __construct(private array $payload)
    {
    }

    public static function fromManifest(PaperDatasetManifest $manifest): self
    {
        if ($manifest->state !== PaperDatasetState::COMPLETE
            || $manifest->eventsFileSha256 === null
            || $manifest->startExchangeTimestamp === null
            || $manifest->endExchangeTimestamp === null
        ) {
            throw new \LogicException('paper_public_capture_not_complete');
        }

        return new self([
            'schema_version' => 'paper-public-capture-result-v1',
            'dataset_id' => $manifest->datasetId,
            'source_network' => $manifest->network->value,
            'source_venue' => $manifest->venue->value,
            'state' => $manifest->state->value,
            'quality' => $manifest->quality->value,
            'event_count' => $manifest->eventCount,
            'start_exchange_timestamp' => $manifest->startExchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'),
            'end_exchange_timestamp' => $manifest->endExchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'),
            'channels' => $manifest->channels,
            'events_file_sha256' => $manifest->eventsFileSha256,
            'certification_status' => 'not_evaluated',
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
