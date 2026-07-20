<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigInteger;

final class PaperDatasetVerifier
{
    public function __construct(private readonly PaperDatasetManifestCodec $codec = new PaperDatasetManifestCodec())
    {
    }

    public function verify(string $datasetDirectory): PaperDatasetManifest
    {
        if (is_link($datasetDirectory) || !is_dir($datasetDirectory) || !is_readable($datasetDirectory)) {
            throw new \RuntimeException('paper_dataset_directory_invalid');
        }

        $manifestPath = $datasetDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $eventsPath = $datasetDirectory . DIRECTORY_SEPARATOR . 'events.ndjson';
        foreach ([$manifestPath, $eventsPath] as $path) {
            if (is_link($path)) {
                throw new \RuntimeException('paper_dataset_symlink_rejected');
            }
            if (!is_file($path) || !is_readable($path)) {
                throw new \RuntimeException('paper_dataset_file_unreadable');
            }
        }

        $json = @file_get_contents($manifestPath);
        if ($json === false) {
            throw new \RuntimeException('paper_dataset_manifest_unreadable');
        }

        $manifest = $this->codec->decode($json);
        if ($manifest->state !== PaperDatasetState::COMPLETE) {
            throw new \RuntimeException('paper_dataset_not_complete');
        }

        $facts = $this->scan($eventsPath, $manifest);
        $checksum = hash_file('sha256', $eventsPath);
        if (!\is_string($checksum) || $manifest->eventsFileSha256 === null
            || !hash_equals($manifest->eventsFileSha256, $checksum)
        ) {
            throw new \RuntimeException('paper_dataset_checksum_mismatch');
        }
        if ($facts['event_count'] !== $manifest->eventCount) {
            throw new \RuntimeException('paper_dataset_event_count_mismatch');
        }
        if ($facts['last_event_id'] !== $manifest->lastEventId) {
            throw new \RuntimeException('paper_dataset_last_event_id_mismatch');
        }
        if ($facts['start_exchange_timestamp'] != $manifest->startExchangeTimestamp) {
            throw new \RuntimeException('paper_dataset_start_timestamp_mismatch');
        }
        if ($facts['end_exchange_timestamp'] != $manifest->endExchangeTimestamp) {
            throw new \RuntimeException('paper_dataset_end_timestamp_mismatch');
        }
        if ($facts['channels'] !== $manifest->channels) {
            throw new \RuntimeException('paper_dataset_channels_mismatch');
        }
        if ($facts['sequence_gaps'] !== $manifest->sequenceGaps) {
            throw new \RuntimeException('paper_dataset_sequence_gaps_mismatch');
        }

        return $manifest;
    }

    /**
     * @return array{
     *   event_count: int,
     *   last_event_id: string|null,
     *   start_exchange_timestamp: \DateTimeImmutable|null,
     *   end_exchange_timestamp: \DateTimeImmutable|null,
     *   channels: list<string>,
     *   sequence_gaps: array<string, int>
     * }
     */
    private function scan(string $eventsPath, PaperDatasetManifest $manifest): array
    {
        $handle = @fopen($eventsPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_events_unreadable');
        }

        /** @var array<string, true> $identities */
        $identities = [];
        /** @var array<string, BigInteger> $lastSequences */
        $lastSequences = [];
        /** @var array<string, int> $sequenceGaps */
        $sequenceGaps = [];
        /** @var list<string> $channels */
        $channels = [];
        $count = 0;
        $lastEventId = null;
        $start = null;
        $end = null;

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                if (!str_ends_with($line, "\n")) {
                    throw new \RuntimeException('paper_dataset_event_invalid');
                }

                $raw = substr($line, 0, -1);
                $event = $this->decodeEvent($raw);
                if (CanonicalJson::encode($event->toArray()) !== $raw) {
                    throw new \RuntimeException('paper_dataset_event_not_canonical');
                }
                if ($event->sourceVenue !== $manifest->venue) {
                    throw new \RuntimeException('paper_dataset_event_venue_mismatch');
                }
                if (!array_key_exists($event->symbol, $manifest->symbols)) {
                    throw new \RuntimeException('paper_dataset_event_symbol_mismatch');
                }
                if (isset($identities[$event->eventId])) {
                    throw new \RuntimeException('paper_dataset_duplicate_identity');
                }
                $identities[$event->eventId] = true;

                $sequenceKey = $event->sourceVenue->value . '/' . $event->symbol . '/' . $event->channel->value;
                if ($event->sequence !== null) {
                    $sequence = BigInteger::of($event->sequence);
                    if (isset($lastSequences[$sequenceKey])) {
                        $previous = $lastSequences[$sequenceKey];
                        if ($sequence->isLessThanOrEqualTo($previous)) {
                            throw new \RuntimeException('paper_dataset_sequence_regression');
                        }
                        if ($sequence->isGreaterThan($previous->plus(1))) {
                            $sequenceGaps[$sequenceKey] = ($sequenceGaps[$sequenceKey] ?? 0) + 1;
                        }
                    }
                    $lastSequences[$sequenceKey] = $sequence;
                }

                ++$count;
                $lastEventId = $event->eventId;
                $channels[] = $event->channel->value;
                $start = $start === null || $event->exchangeTimestamp < $start ? $event->exchangeTimestamp : $start;
                $end = $end === null || $event->exchangeTimestamp > $end ? $event->exchangeTimestamp : $end;
            }
            if (!feof($handle)) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
        } finally {
            fclose($handle);
        }

        $channels = array_values(array_unique($channels));
        sort($channels, SORT_STRING);
        ksort($sequenceGaps, SORT_STRING);

        return [
            'event_count' => $count,
            'last_event_id' => $lastEventId,
            'start_exchange_timestamp' => $start,
            'end_exchange_timestamp' => $end,
            'channels' => $channels,
            'sequence_gaps' => $sequenceGaps,
        ];
    }

    private function decodeEvent(#[\SensitiveParameter] string $raw): PaperMarketEvent
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!\is_array($decoded) || array_is_list($decoded)) {
                throw new \JsonException();
            }
            /** @var array<string, mixed> $decoded */
            return PaperMarketEvent::fromArray($decoded);
        } catch (\Throwable) {
            throw new \RuntimeException('paper_dataset_event_invalid');
        }
    }
}
