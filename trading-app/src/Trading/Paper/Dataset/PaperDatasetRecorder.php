<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigInteger;

final class PaperDatasetRecorder
{
    private PaperDatasetManifestCodec $codec;
    private PaperDatasetVerifier $verifier;
    private string $datasetDirectory;
    private string $eventsPath;
    private string $manifestPath;
    private PaperDatasetManifest $currentManifest;
    private bool $usable = true;

    /** @var array<string, array{payload_hash: string, event_hash: string}> */
    private array $identities = [];

    /** @var array<string, BigInteger> */
    private array $lastSequences = [];

    /** @var array<string, int> */
    private array $sequenceGaps = [];

    /** @var list<string> */
    private array $channels = [];

    private int $eventCount = 0;
    private ?string $lastEventId = null;
    private ?\DateTimeImmutable $startExchangeTimestamp = null;
    private ?\DateTimeImmutable $latestExchangeTimestamp = null;

    public function __construct(
        string $root,
        PaperDatasetManifest $manifest,
        ?PaperDatasetManifestCodec $codec = null,
        ?PaperDatasetVerifier $verifier = null,
    ) {
        PaperDatasetManifest::assertDatasetId($manifest->datasetId);
        $this->codec = $codec ?? new PaperDatasetManifestCodec();
        $this->verifier = $verifier ?? new PaperDatasetVerifier($this->codec);

        $root = $this->prepareDirectory($root);
        $this->datasetDirectory = $root . DIRECTORY_SEPARATOR . $manifest->datasetId;
        $this->assertNotSymlink($this->datasetDirectory);
        $this->ensureDirectory($this->datasetDirectory);

        $checkpoints = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'checkpoints';
        $this->assertNotSymlink($checkpoints);
        $this->ensureDirectory($checkpoints);

        $this->eventsPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'events.ndjson';
        $this->manifestPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->assertNotSymlink($this->eventsPath);
        $this->assertNotSymlink($this->manifestPath);
        $this->ensureEventsFile();

        if (is_file($this->manifestPath)) {
            $stored = $this->readStoredManifest();
            $this->assertSameDataset($manifest, $stored);
            $this->currentManifest = $stored;
        } else {
            $this->currentManifest = $manifest;
            $this->writeManifestAtomically($manifest);
        }

        $this->scanEvents();
        if ($this->currentManifest->state === PaperDatasetState::RECORDING) {
            $reconciled = $this->currentManifest->withRecordingFacts(
                startExchangeTimestamp: $this->startExchangeTimestamp,
                channels: $this->channels,
                eventCount: $this->eventCount,
                sequenceGaps: $this->sequenceGaps,
                lastEventId: $this->lastEventId,
            );
            if ($reconciled != $this->currentManifest) {
                $this->writeManifestAtomically($reconciled);
                $this->currentManifest = $reconciled;
            }
        }
    }

    public function manifest(): PaperDatasetManifest
    {
        return $this->currentManifest;
    }

    public function datasetDirectory(): string
    {
        return $this->datasetDirectory;
    }

    public function append(PaperMarketEvent $event): PaperDatasetAppendResult
    {
        $this->assertRecording();
        $this->assertEventMatchesManifest($event);
        $canonicalEvent = CanonicalJson::encode($event->toArray());
        $eventHash = hash('sha256', $canonicalEvent);

        if (isset($this->identities[$event->eventId])) {
            $identity = $this->identities[$event->eventId];
            if (!hash_equals($identity['payload_hash'], $event->payloadHash)
                || !hash_equals($identity['event_hash'], $eventHash)
            ) {
                throw new \RuntimeException('market_event_identity_conflict');
            }

            return PaperDatasetAppendResult::REPLAYED;
        }

        $sequenceKey = $this->sequenceKey($event);
        $nextSequence = $event->sequence === null ? null : BigInteger::of($event->sequence);
        $gap = false;
        if ($nextSequence !== null && isset($this->lastSequences[$sequenceKey])) {
            $lastSequence = $this->lastSequences[$sequenceKey];
            if ($nextSequence->isLessThanOrEqualTo($lastSequence)) {
                throw new \RuntimeException('market_event_out_of_order');
            }
            $gap = $nextSequence->isGreaterThan($lastSequence->plus(1));
        }

        $line = $canonicalEvent . "\n";
        $this->appendDurably($line);

        $nextChannels = $this->channels;
        $nextChannels[] = $event->channel->value;
        $nextChannels = array_values(array_unique($nextChannels));
        sort($nextChannels, SORT_STRING);
        $nextGaps = $this->sequenceGaps;
        if ($gap) {
            $nextGaps[$sequenceKey] = ($nextGaps[$sequenceKey] ?? 0) + 1;
            ksort($nextGaps, SORT_STRING);
        }
        $nextStart = $this->minimumTimestamp($this->startExchangeTimestamp, $event->exchangeTimestamp);

        $nextManifest = $this->currentManifest->withRecordingFacts(
            startExchangeTimestamp: $nextStart,
            channels: $nextChannels,
            eventCount: $this->eventCount + 1,
            sequenceGaps: $nextGaps,
            lastEventId: $event->eventId,
        );

        try {
            $this->writeManifestAtomically($nextManifest);
        } catch (\Throwable) {
            $this->usable = false;
            throw new \RuntimeException('paper_dataset_manifest_write_failed');
        }

        $this->identities[$event->eventId] = [
            'payload_hash' => $event->payloadHash,
            'event_hash' => $eventHash,
        ];
        if ($nextSequence !== null) {
            $this->lastSequences[$sequenceKey] = $nextSequence;
        }
        $this->channels = $nextChannels;
        $this->sequenceGaps = $nextGaps;
        $this->eventCount = $nextManifest->eventCount;
        $this->lastEventId = $event->eventId;
        $this->startExchangeTimestamp = $nextStart;
        $this->latestExchangeTimestamp = $this->maximumTimestamp(
            $this->latestExchangeTimestamp,
            $event->exchangeTimestamp,
        );
        $this->currentManifest = $nextManifest;

        return PaperDatasetAppendResult::APPENDED;
    }

    public function complete(): PaperDatasetManifest
    {
        $this->assertRecording();
        $this->flushEventsFile();
        $checksum = hash_file('sha256', $this->eventsPath);
        if (!\is_string($checksum)) {
            throw new \RuntimeException('paper_dataset_checksum_failed');
        }

        $completed = $this->currentManifest->finalized(
            state: PaperDatasetState::COMPLETE,
            endExchangeTimestamp: $this->latestExchangeTimestamp,
            quality: $this->currentManifest->quality,
            eventsFileSha256: $checksum,
        );
        $this->writeManifestAtomically($completed);
        $this->currentManifest = $completed;

        return $this->verifier->verify($this->datasetDirectory);
    }

    public function markIncomplete(): PaperDatasetManifest
    {
        $this->assertRecording();
        $this->flushEventsFile();
        $checksum = hash_file('sha256', $this->eventsPath);
        if (!\is_string($checksum)) {
            throw new \RuntimeException('paper_dataset_checksum_failed');
        }

        $incomplete = $this->currentManifest->finalized(
            state: PaperDatasetState::INCOMPLETE,
            endExchangeTimestamp: $this->latestExchangeTimestamp,
            quality: PaperMarketDataQuality::INCOMPLETE,
            eventsFileSha256: $checksum,
        );
        $this->writeManifestAtomically($incomplete);
        $this->currentManifest = $incomplete;

        return $incomplete;
    }

    private function scanEvents(): void
    {
        $handle = @fopen($this->eventsPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_events_unreadable');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                if (!str_ends_with($line, "\n")) {
                    throw new \RuntimeException('paper_dataset_event_line_truncated');
                }
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    if (!\is_array($decoded) || array_is_list($decoded)) {
                        throw new \JsonException();
                    }
                    /** @var array<string, mixed> $decoded */
                    $event = PaperMarketEvent::fromArray($decoded);
                } catch (\Throwable) {
                    throw new \RuntimeException('paper_dataset_event_invalid');
                }

                $this->assertEventMatchesManifest($event);
                if (isset($this->identities[$event->eventId])) {
                    throw new \RuntimeException('paper_dataset_duplicate_identity');
                }

                $sequenceKey = $this->sequenceKey($event);
                if ($event->sequence !== null) {
                    $sequence = BigInteger::of($event->sequence);
                    if (isset($this->lastSequences[$sequenceKey])) {
                        $last = $this->lastSequences[$sequenceKey];
                        if ($sequence->isLessThanOrEqualTo($last)) {
                            throw new \RuntimeException('paper_dataset_sequence_invalid');
                        }
                        if ($sequence->isGreaterThan($last->plus(1))) {
                            $this->sequenceGaps[$sequenceKey] = ($this->sequenceGaps[$sequenceKey] ?? 0) + 1;
                        }
                    }
                    $this->lastSequences[$sequenceKey] = $sequence;
                }

                $this->identities[$event->eventId] = [
                    'payload_hash' => $event->payloadHash,
                    'event_hash' => hash('sha256', CanonicalJson::encode($event->toArray())),
                ];
                $this->channels[] = $event->channel->value;
                ++$this->eventCount;
                $this->lastEventId = $event->eventId;
                $this->startExchangeTimestamp = $this->minimumTimestamp(
                    $this->startExchangeTimestamp,
                    $event->exchangeTimestamp,
                );
                $this->latestExchangeTimestamp = $this->maximumTimestamp(
                    $this->latestExchangeTimestamp,
                    $event->exchangeTimestamp,
                );
            }
            if (!feof($handle)) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
        } finally {
            fclose($handle);
        }

        $this->channels = array_values(array_unique($this->channels));
        sort($this->channels, SORT_STRING);
        ksort($this->sequenceGaps, SORT_STRING);
    }

    private function assertRecording(): void
    {
        if (!$this->usable) {
            throw new \RuntimeException('paper_dataset_recorder_unusable');
        }
        if ($this->currentManifest->state !== PaperDatasetState::RECORDING) {
            throw new \RuntimeException('paper_dataset_not_recording');
        }
    }

    private function assertEventMatchesManifest(PaperMarketEvent $event): void
    {
        if ($event->sourceVenue !== $this->currentManifest->venue) {
            throw new \RuntimeException('paper_dataset_event_venue_mismatch');
        }
        if (!array_key_exists($event->symbol, $this->currentManifest->symbols)) {
            throw new \RuntimeException('paper_dataset_event_symbol_mismatch');
        }
    }

    private function sequenceKey(PaperMarketEvent $event): string
    {
        return $event->sourceVenue->value . '/' . $event->symbol . '/' . $event->channel->value;
    }

    private function appendDurably(string $line): void
    {
        $handle = @fopen($this->eventsPath, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_events_open_failed');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_events_lock_failed');
            }
            try {
                $this->writeAll($handle, $line, 'paper_dataset_events_write_failed');
                $this->flushHandle($handle, 'paper_dataset_events_flush_failed');
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function flushEventsFile(): void
    {
        $handle = @fopen($this->eventsPath, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_events_open_failed');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_events_lock_failed');
            }
            try {
                $this->flushHandle($handle, 'paper_dataset_events_flush_failed');
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function writeManifestAtomically(PaperDatasetManifest $manifest): void
    {
        $temporary = @tempnam($this->datasetDirectory, '.manifest-');
        if ($temporary === false) {
            throw new \RuntimeException('paper_dataset_manifest_temp_failed');
        }
        if (dirname($temporary) !== $this->datasetDirectory) {
            @unlink($temporary);
            throw new \RuntimeException('paper_dataset_manifest_temp_failed');
        }
        $handle = null;
        try {
            if (!@chmod($temporary, 0600)) {
                throw new \RuntimeException('paper_dataset_manifest_mode_failed');
            }
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('paper_dataset_manifest_open_failed');
            }
            $this->writeAll($handle, $this->codec->encode($manifest), 'paper_dataset_manifest_write_failed');
            $this->flushHandle($handle, 'paper_dataset_manifest_flush_failed');
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, $this->manifestPath)) {
                throw new \RuntimeException('paper_dataset_manifest_rename_failed');
            }
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents, string $error): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException($error);
            }
            $offset += $written;
        }
    }

    /** @param resource $handle */
    private function flushHandle($handle, string $error): void
    {
        if (!fflush($handle)) {
            throw new \RuntimeException($error);
        }
        if (\function_exists('fsync') && !fsync($handle)) {
            throw new \RuntimeException($error);
        }
    }

    private function ensureEventsFile(): void
    {
        if (!file_exists($this->eventsPath)) {
            $handle = @fopen($this->eventsPath, 'xb');
            if ($handle === false) {
                throw new \RuntimeException('paper_dataset_events_create_failed');
            }
            fclose($handle);
        }
        if (!is_file($this->eventsPath) || !chmod($this->eventsPath, 0600)) {
            throw new \RuntimeException('paper_dataset_events_invalid');
        }
    }

    private function readStoredManifest(): PaperDatasetManifest
    {
        $json = @file_get_contents($this->manifestPath);
        if ($json === false) {
            throw new \RuntimeException('paper_dataset_manifest_unreadable');
        }

        return $this->codec->decode($json);
    }

    private function assertSameDataset(PaperDatasetManifest $requested, PaperDatasetManifest $stored): void
    {
        if ($requested->schemaVersion !== $stored->schemaVersion
            || $requested->recorderVersion !== $stored->recorderVersion
            || $requested->datasetId !== $stored->datasetId
            || $requested->venue !== $stored->venue
            || $requested->symbols !== $stored->symbols
        ) {
            throw new \RuntimeException('paper_dataset_manifest_identity_mismatch');
        }
    }

    private function prepareDirectory(string $directory): string
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new \RuntimeException('paper_dataset_root_invalid');
        }
        $this->assertNotSymlink($directory);
        $ancestor = $directory;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                break;
            }
            $ancestor = $parent;
        }
        $this->assertNotSymlink($ancestor);
        $this->ensureDirectory($directory);
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new \RuntimeException('paper_dataset_root_invalid');
        }

        return $resolved;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('paper_dataset_directory_create_failed');
        }
        if (!@chmod($directory, 0700)) {
            throw new \RuntimeException('paper_dataset_directory_mode_failed');
        }
    }

    private function assertNotSymlink(string $path): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('paper_dataset_symlink_rejected');
        }
    }

    private function minimumTimestamp(
        ?\DateTimeImmutable $current,
        \DateTimeImmutable $candidate,
    ): \DateTimeImmutable {
        return $current === null || $candidate < $current ? $candidate : $current;
    }

    private function maximumTimestamp(
        ?\DateTimeImmutable $current,
        \DateTimeImmutable $candidate,
    ): \DateTimeImmutable {
        return $current === null || $candidate > $current ? $candidate : $current;
    }
}
