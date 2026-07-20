<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigInteger;

final class PaperDatasetRecorder
{
    private const REGULAR_FILE_TYPE = 0100000;
    private const FILE_TYPE_MASK = 0170000;

    private PaperDatasetManifestCodec $codec;
    private PaperDatasetVerifier $verifier;
    private PaperDatasetRecorderFilesystem $filesystem;
    private string $datasetDirectory;
    private string $eventsPath;
    private string $manifestPath;
    private string $lockPath;
    private PaperDatasetManifest $identityManifest;
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
    private int $scannedBytes = 0;
    private ?string $lastEventId = null;
    private ?\DateTimeImmutable $startExchangeTimestamp = null;
    private ?\DateTimeImmutable $latestExchangeTimestamp = null;

    public function __construct(
        string $root,
        PaperDatasetManifest $manifest,
        ?PaperDatasetManifestCodec $codec = null,
        ?PaperDatasetVerifier $verifier = null,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
    ) {
        PaperDatasetManifest::assertDatasetId($manifest->datasetId);
        $this->codec = $codec ?? new PaperDatasetManifestCodec();
        $this->verifier = $verifier ?? new PaperDatasetVerifier($this->codec);
        $this->filesystem = $filesystem ?? new PaperDatasetRecorderFilesystem();
        $this->identityManifest = $manifest;

        if ($manifest->state !== PaperDatasetState::RECORDING && !$this->storedManifestExists($root, $manifest)) {
            throw new \RuntimeException('paper_dataset_initial_state_invalid');
        }

        $root = $this->prepareDirectory($root);
        $this->datasetDirectory = $root . DIRECTORY_SEPARATOR . $manifest->datasetId;
        $this->assertNoSymlinkComponents($this->datasetDirectory);
        $this->ensureDirectory($this->datasetDirectory);

        $checkpoints = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'checkpoints';
        $this->eventsPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'events.ndjson';
        $this->manifestPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->lockPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.dataset.lock';
        $this->assertNoSymlinkComponents($checkpoints);
        $this->assertNoSymlinkComponents($this->eventsPath);
        $this->assertNoSymlinkComponents($this->manifestPath);
        $this->assertNoSymlinkComponents($this->lockPath);
        $this->ensureLockFile();

        $this->withDatasetLock(function () use ($checkpoints, $manifest): void {
            if (is_file($this->manifestPath)) {
                $this->ensureEventsFile(create: false);
                $this->ensureDirectory($checkpoints);
                $stored = $this->readStoredManifest();
                $this->assertSameDataset($manifest, $stored);
                $this->currentManifest = $stored;
            } else {
                if ($manifest->state !== PaperDatasetState::RECORDING) {
                    throw new \RuntimeException('paper_dataset_initial_state_invalid');
                }
                $this->ensureDirectory($checkpoints);
                $this->ensureEventsFile(create: true);
                $this->currentManifest = $manifest;
                $this->writeManifestAtomically($manifest);
            }

            $this->reloadDurableState();
        });
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
        $this->assertUsable();

        return $this->withDatasetLock(fn (): PaperDatasetAppendResult => $this->appendUnderLock($event));
    }

    private function appendUnderLock(PaperMarketEvent $event): PaperDatasetAppendResult
    {
        $this->reloadDurableState();
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
        $durableSize = $this->appendDurably($line);

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
        $this->scannedBytes = $durableSize;
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
        $this->assertUsable();

        return $this->withDatasetLock(fn (): PaperDatasetManifest => $this->completeUnderLock());
    }

    private function completeUnderLock(): PaperDatasetManifest
    {
        $this->reloadDurableState();
        $this->assertRecording();
        $this->flushEventsFile();
        $checksum = $this->checksumEventsFile();

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
        $this->assertUsable();

        return $this->withDatasetLock(fn (): PaperDatasetManifest => $this->markIncompleteUnderLock());
    }

    private function markIncompleteUnderLock(): PaperDatasetManifest
    {
        $this->reloadDurableState();
        $this->assertRecording();
        $this->flushEventsFile();
        $checksum = $this->checksumEventsFile();

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

    private function reloadDurableState(): void
    {
        $stored = $this->readStoredManifest();
        $this->assertSameDataset($this->identityManifest, $stored);
        $this->currentManifest = $stored;
        $this->scanDurableTail();

        if ($stored->state !== PaperDatasetState::RECORDING) {
            return;
        }

        $reconciled = $stored->withRecordingFacts(
            startExchangeTimestamp: $this->startExchangeTimestamp,
            channels: $this->channels,
            eventCount: $this->eventCount,
            sequenceGaps: $this->sequenceGaps,
            lastEventId: $this->lastEventId,
        );
        if ($reconciled != $stored) {
            $this->writeManifestAtomically($reconciled);
            $this->currentManifest = $reconciled;
        }
    }

    private function scanDurableTail(): void
    {
        $handle = $this->openRegularFile($this->eventsPath, 'rb', 'paper_dataset_events_unreadable');

        try {
            $statistics = fstat($handle);
            if ($statistics === false) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
            $durableSize = $statistics['size'];
            if ($durableSize < $this->scannedBytes) {
                throw new \RuntimeException('paper_dataset_events_size_regressed');
            }
            if ($durableSize === $this->scannedBytes) {
                return;
            }
            if (fseek($handle, $this->scannedBytes) !== 0) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }

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
            $position = ftell($handle);
            if ($position === false || $position !== $durableSize) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
            $this->assertHandleMatchesPath($handle, $this->eventsPath);
        } finally {
            fclose($handle);
        }

        $this->channels = array_values(array_unique($this->channels));
        sort($this->channels, SORT_STRING);
        ksort($this->sequenceGaps, SORT_STRING);
        $this->scannedBytes = $durableSize;
    }

    private function assertRecording(): void
    {
        $this->assertUsable();
        if ($this->currentManifest->state !== PaperDatasetState::RECORDING) {
            throw new \RuntimeException('paper_dataset_not_recording');
        }
    }

    private function assertUsable(): void
    {
        if (!$this->usable) {
            throw new \RuntimeException('paper_dataset_recorder_unusable');
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

    private function appendDurably(string $line): int
    {
        $handle = $this->openRegularFile($this->eventsPath, 'ab', 'paper_dataset_events_open_failed');
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_events_lock_failed');
            }
            try {
                $this->assertHandleMatchesPath($handle, $this->eventsPath);
                $statistics = $this->filesystem->stat($handle, 'paper_dataset_events_read_failed');
                if ($statistics === false || !isset($statistics['size']) || !\is_int($statistics['size'])) {
                    throw new \RuntimeException('paper_dataset_events_read_failed');
                }

                $originalLength = $statistics['size'];
                try {
                    $this->writeAll($handle, $line, 'paper_dataset_events_write_failed');
                    $this->flushHandle($handle, 'paper_dataset_events_flush_failed');
                    $statistics = $this->filesystem->stat($handle, 'paper_dataset_events_read_failed');
                    if ($statistics === false || !isset($statistics['size']) || !\is_int($statistics['size'])) {
                        throw new \RuntimeException('paper_dataset_events_read_failed');
                    }
                    $this->assertHandleMatchesPath($handle, $this->eventsPath);

                    return $statistics['size'];
                } catch (\Throwable $failure) {
                    if (!$this->rollbackAppend($handle, $originalLength)) {
                        $this->usable = false;

                        throw new \RuntimeException('paper_dataset_events_rollback_failed', 0, $failure);
                    }

                    throw $failure;
                }
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function rollbackAppend($handle, int $originalLength): bool
    {
        try {
            if (!$this->filesystem->truncate(
                $handle,
                $originalLength,
                'paper_dataset_events_rollback_failed',
            )) {
                return false;
            }
            $this->flushHandle($handle, 'paper_dataset_events_rollback_failed');
            $statistics = $this->filesystem->stat($handle, 'paper_dataset_events_rollback_failed');

            return $statistics !== false
                && isset($statistics['size'])
                && \is_int($statistics['size'])
                && $statistics['size'] === $originalLength;
        } catch (\Throwable) {
            return false;
        }
    }

    private function flushEventsFile(): void
    {
        $handle = $this->openRegularFile($this->eventsPath, 'ab', 'paper_dataset_events_open_failed');
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_events_lock_failed');
            }
            try {
                $this->assertHandleMatchesPath($handle, $this->eventsPath);
                $this->flushHandle($handle, 'paper_dataset_events_flush_failed');
                $this->assertHandleMatchesPath($handle, $this->eventsPath);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function checksumEventsFile(): string
    {
        $handle = $this->openRegularFile($this->eventsPath, 'rb', 'paper_dataset_events_unreadable');
        try {
            $statistics = $this->filesystem->stat($handle, 'paper_dataset_events_checksum_failed');
            if ($statistics === false || !isset($statistics['size']) || !\is_int($statistics['size'])) {
                throw new \RuntimeException('paper_dataset_checksum_failed');
            }
            $result = $this->filesystem->checksum($handle, 'paper_dataset_events_checksum_failed');
            if ($result['bytes'] !== $statistics['size']) {
                throw new \RuntimeException('paper_dataset_checksum_failed');
            }
            $this->assertHandleMatchesPath($handle, $this->eventsPath);

            return $result['checksum'];
        } finally {
            fclose($handle);
        }
    }

    private function writeManifestAtomically(PaperDatasetManifest $manifest): void
    {
        $destinationStat = $this->pathStatIfPresent($this->manifestPath);
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
            $handle = $this->openRegularFile($temporary, 'r+b', 'paper_dataset_manifest_open_failed');
            $this->writeAll($handle, $this->codec->encode($manifest), 'paper_dataset_manifest_write_failed');
            $this->flushHandle($handle, 'paper_dataset_manifest_flush_failed');
            $this->assertHandleMatchesPath($handle, $temporary);
            $temporaryStat = $this->pathStat($temporary, 'paper_dataset_manifest_temp_failed');
            fclose($handle);
            $handle = null;
            $this->assertPathUnchanged($this->manifestPath, $destinationStat);
            if (!@rename($temporary, $this->manifestPath)) {
                throw new \RuntimeException('paper_dataset_manifest_rename_failed');
            }
            $this->assertPathUnchanged($this->manifestPath, $temporaryStat);
            $this->syncDatasetDirectory();
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function syncDatasetDirectory(): void
    {
        $handle = @fopen($this->datasetDirectory, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_manifest_directory_open_failed');
        }

        try {
            if (!$this->filesystem->sync($handle, 'paper_dataset_manifest_directory_sync_failed')) {
                throw new \RuntimeException('paper_dataset_manifest_directory_sync_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents, string $error): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = $this->filesystem->write($handle, substr($contents, $offset), $error);
            if ($written === false || $written === 0) {
                throw new \RuntimeException($error);
            }
            $offset += $written;
        }
    }

    /** @param resource $handle */
    private function flushHandle($handle, string $error): void
    {
        if (!$this->filesystem->flush($handle, $error)) {
            throw new \RuntimeException($error);
        }
        if (!$this->filesystem->sync($handle, $error)) {
            throw new \RuntimeException($error);
        }
    }

    private function ensureEventsFile(bool $create): void
    {
        if (!file_exists($this->eventsPath)) {
            if (!$create) {
                throw new \RuntimeException('paper_dataset_events_missing');
            }
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

    private function ensureLockFile(): void
    {
        if (!file_exists($this->lockPath)) {
            $handle = @fopen($this->lockPath, 'x+b');
            if ($handle === false) {
                if (!is_file($this->lockPath)) {
                    throw new \RuntimeException('paper_dataset_lock_create_failed');
                }
            } else {
                fclose($handle);
            }
        }

        if (!is_file($this->lockPath) || !@chmod($this->lockPath, 0600)) {
            throw new \RuntimeException('paper_dataset_lock_invalid');
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withDatasetLock(callable $operation): mixed
    {
        $handle = $this->openRegularFile($this->lockPath, 'r+b', 'paper_dataset_lock_open_failed');

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_lock_failed');
            }

            try {
                $this->assertHandleMatchesPath($handle, $this->lockPath);

                return $operation();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function readStoredManifest(): PaperDatasetManifest
    {
        $handle = $this->openRegularFile($this->manifestPath, 'rb', 'paper_dataset_manifest_unreadable');
        try {
            $json = stream_get_contents($handle);
            if ($json === false) {
                throw new \RuntimeException('paper_dataset_manifest_unreadable');
            }
            $this->assertHandleMatchesPath($handle, $this->manifestPath);
        } finally {
            fclose($handle);
        }

        return $this->codec->decode($json);
    }

    /** @return resource */
    private function openRegularFile(string $path, string $mode, string $openError)
    {
        $before = $this->pathStat($path, $openError);
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException($openError);
        }

        try {
            $opened = $this->assertHandleMatchesPath($handle, $path);
            if (!$this->sameFile($before, $opened)) {
                throw new \RuntimeException('paper_dataset_file_changed');
            }

            return $handle;
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /**
     * @param resource $handle
     *
     * @return array<string, mixed>
     */
    private function assertHandleMatchesPath($handle, string $path): array
    {
        $opened = $this->filesystem->stat($handle, 'paper_dataset_file_validation_failed');
        if ($opened === false || !$this->isRegularFile($opened)) {
            throw new \RuntimeException('paper_dataset_file_validation_failed');
        }

        $current = $this->pathStat($path, 'paper_dataset_file_changed');
        if (!$this->sameFile($opened, $current)) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }

        return $opened;
    }

    /** @return array<string, mixed> */
    private function pathStat(string $path, string $missingError): array
    {
        $this->assertNoSymlinkComponents($path);
        clearstatcache(true, $path);
        $statistics = @lstat($path);
        if ($statistics === false) {
            throw new \RuntimeException($missingError);
        }
        if (($statistics['mode'] & self::FILE_TYPE_MASK) === 0120000) {
            throw new \RuntimeException('paper_dataset_symlink_rejected');
        }
        if (!$this->isRegularFile($statistics)) {
            throw new \RuntimeException('paper_dataset_file_validation_failed');
        }

        return $statistics;
    }

    /** @return array<string, mixed>|null */
    private function pathStatIfPresent(string $path): ?array
    {
        $this->assertNoSymlinkComponents($path);
        clearstatcache(true, $path);
        $statistics = @lstat($path);
        if ($statistics === false) {
            return null;
        }
        if (($statistics['mode'] & self::FILE_TYPE_MASK) === 0120000) {
            throw new \RuntimeException('paper_dataset_symlink_rejected');
        }
        if (!$this->isRegularFile($statistics)) {
            throw new \RuntimeException('paper_dataset_file_validation_failed');
        }

        return $statistics;
    }

    /** @param array<string, mixed>|null $expected */
    private function assertPathUnchanged(string $path, ?array $expected): void
    {
        $current = $this->pathStatIfPresent($path);
        if ($expected === null || $current === null) {
            if ($expected !== $current) {
                throw new \RuntimeException('paper_dataset_file_changed');
            }

            return;
        }
        if (!$this->sameFile($expected, $current)) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }
    }

    /** @param array<string, mixed> $statistics */
    private function isRegularFile(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE_TYPE;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sameFile(array $left, array $right): bool
    {
        foreach (['dev', 'ino'] as $field) {
            if (!isset($left[$field], $right[$field])
                || !\is_int($left[$field])
                || !\is_int($right[$field])
                || $left[$field] !== $right[$field]
            ) {
                return false;
            }
        }

        return true;
    }

    private function assertSameDataset(PaperDatasetManifest $requested, PaperDatasetManifest $stored): void
    {
        if ($requested->schemaVersion !== $stored->schemaVersion
            || $requested->recorderVersion !== $stored->recorderVersion
            || $requested->datasetId !== $stored->datasetId
            || $requested->venue !== $stored->venue
            || $requested->symbols !== $stored->symbols
            || ($stored->state !== PaperDatasetState::INCOMPLETE && (
                $requested->quality !== $stored->quality
                || $requested->modelName !== $stored->modelName
                || $requested->modelVersion !== $stored->modelVersion
            ))
        ) {
            throw new \RuntimeException('paper_dataset_manifest_identity_mismatch');
        }
    }

    private function prepareDirectory(string $directory): string
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new \RuntimeException('paper_dataset_root_invalid');
        }
        $this->assertNoSymlinkComponents($directory);
        $this->ensureDirectory($directory);
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new \RuntimeException('paper_dataset_root_invalid');
        }

        return $resolved;
    }

    private function storedManifestExists(string $root, PaperDatasetManifest $manifest): bool
    {
        if ($root === '' || str_contains($root, "\0")) {
            throw new \RuntimeException('paper_dataset_root_invalid');
        }
        $this->assertNoSymlinkComponents($root);

        return is_file(
            rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $manifest->datasetId
            . DIRECTORY_SEPARATOR
            . 'manifest.json',
        );
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

    private function assertNoSymlinkComponents(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw new \RuntimeException('paper_dataset_root_invalid');
            }
            $path = $workingDirectory . DIRECTORY_SEPARATOR . $path;
        }

        $current = DIRECTORY_SEPARATOR;
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                $current = dirname($current);

                continue;
            }

            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $component;
            if (is_link($current)) {
                throw new \RuntimeException('paper_dataset_symlink_rejected');
            }
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
