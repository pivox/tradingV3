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
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const FILE_TYPE_MASK = 0170000;

    private PaperDatasetManifestCodec $codec;
    private PaperDatasetVerifier $verifier;
    private PaperDatasetRecorderFilesystem $filesystem;
    private string $datasetRoot;
    private string $datasetDirectory;
    private string $eventsPath;
    private string $manifestPath;
    private string $lockPath;
    private PaperDatasetManifest $identityManifest;
    private PaperDatasetManifest $currentManifest;
    private bool $usable = true;

    /** @var array{dev: int, ino: int} */
    private array $datasetRootIdentity;

    /** @var array{dev: int, ino: int} */
    private array $datasetDirectoryIdentity;

    /** @var array{dev: int, ino: int} */
    private array $lockFileIdentity;

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
    private ?string $scannedPrefixSha256 = null;

    /** @var array{dev: int, ino: int}|null */
    private ?array $scannedFileIdentity = null;

    private ?string $lastEventId = null;
    private ?\DateTimeImmutable $startExchangeTimestamp = null;
    private ?\DateTimeImmutable $latestExchangeTimestamp = null;

    public function __construct(
        #[\SensitiveParameter] string $root,
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
        $this->datasetRoot = $root;
        $this->datasetRootIdentity = $this->pinDirectoryIdentity($root, 'paper_dataset_root_invalid');
        $requestedDatasetDirectory = $root . DIRECTORY_SEPARATOR . $manifest->datasetId;
        $preparedDatasetDirectory = $this->prepareManagedDirectory(
            $requestedDatasetDirectory,
            'paper_dataset_directory_invalid',
        );
        if ($preparedDatasetDirectory['path'] !== $requestedDatasetDirectory) {
            throw new \RuntimeException('paper_dataset_directory_invalid');
        }
        $this->datasetDirectory = $preparedDatasetDirectory['path'];
        $this->datasetDirectoryIdentity = $preparedDatasetDirectory['identity'];

        $checkpoints = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'checkpoints';
        $this->eventsPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'events.ndjson';
        $this->manifestPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->lockPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.dataset.lock';
        $this->assertNoSymlinkComponents($checkpoints);
        $this->assertNoSymlinkComponents($this->eventsPath);
        $this->assertNoSymlinkComponents($this->manifestPath);
        $this->assertNoSymlinkComponents($this->lockPath);
        $existingDataset = file_exists($this->eventsPath) || file_exists($this->manifestPath);
        $this->ensureLockFile(create: !$existingDataset);

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

    public function append(#[\SensitiveParameter] PaperMarketEvent $event): PaperDatasetAppendResult
    {
        $this->assertUsable();

        return $this->withDatasetLock(fn (): PaperDatasetAppendResult => $this->appendUnderLock($event));
    }

    private function appendUnderLock(#[\SensitiveParameter] PaperMarketEvent $event): PaperDatasetAppendResult
    {
        $this->reloadDurableState();
        $this->assertPinnedDirectories();
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
        $durableAppend = $this->appendDurably($line);
        $this->assertPinnedDirectories();

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
        } catch (\Throwable $failure) {
            $this->usable = false;
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_directory_changed'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_manifest_write_failed', 0, $failure);
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
        $this->scannedBytes = $durableAppend['size'];
        $this->scannedPrefixSha256 = $durableAppend['sha256'];
        $this->scannedFileIdentity = $durableAppend['identity'];
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
        $this->assertPinnedDirectories();
        $this->assertRecording();
        $checksum = $this->checksumEventsFile();
        $this->assertPinnedDirectories();

        $completed = $this->currentManifest->finalized(
            state: PaperDatasetState::COMPLETE,
            endExchangeTimestamp: $this->latestExchangeTimestamp,
            quality: $this->currentManifest->quality,
            eventsFileSha256: $checksum,
        );
        $this->writeFinalManifestAtomically($completed);

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
        $this->assertPinnedDirectories();
        $this->assertRecording();
        $checksum = $this->checksumEventsFile();
        $this->assertPinnedDirectories();

        $incomplete = $this->currentManifest->finalized(
            state: PaperDatasetState::INCOMPLETE,
            endExchangeTimestamp: $this->latestExchangeTimestamp,
            quality: PaperMarketDataQuality::INCOMPLETE,
            eventsFileSha256: $checksum,
        );
        $this->writeFinalManifestAtomically($incomplete);

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
        try {
            $this->scanDurableTailCandidate();
        } catch (\RuntimeException $failure) {
            if (\in_array($failure->getMessage(), [
                'paper_dataset_file_changed',
                'paper_dataset_events_prefix_changed',
                'paper_dataset_events_size_regressed',
                'paper_dataset_events_snapshot_changed',
                'paper_dataset_symlink_rejected',
            ], true)) {
                $this->usable = false;
            }

            throw $failure;
        }
    }

    private function scanDurableTailCandidate(): void
    {
        $handle = $this->openRegularFile($this->eventsPath, 'rb', 'paper_dataset_events_unreadable');

        try {
            $statistics = $this->filesystem->stat($handle, 'paper_dataset_events_read_failed');
            if ($statistics === false
                || !$this->isPrivateRegularFile($statistics)
                || !isset($statistics['size'])
                || !\is_int($statistics['size'])
            ) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
            $durableSize = $statistics['size'];
            if ($this->scannedFileIdentity !== null
                && !$this->sameFile($this->scannedFileIdentity, $statistics)
            ) {
                throw new \RuntimeException('paper_dataset_file_changed');
            }
            if ($durableSize < $this->scannedBytes) {
                throw new \RuntimeException('paper_dataset_events_size_regressed');
            }
            $parsedDigest = $this->readPrefixForScan($handle, $this->scannedBytes);
            $prefixSha256 = $parsedDigest['sha256'];
            if ($this->scannedPrefixSha256 !== null
                && !hash_equals($this->scannedPrefixSha256, $prefixSha256)
            ) {
                throw new \RuntimeException('paper_dataset_events_prefix_changed');
            }

            $identities = $this->identities;
            $lastSequences = $this->lastSequences;
            $sequenceGaps = $this->sequenceGaps;
            $channels = $this->channels;
            $eventCount = $this->eventCount;
            $lastEventId = $this->lastEventId;
            $startExchangeTimestamp = $this->startExchangeTimestamp;
            $latestExchangeTimestamp = $this->latestExchangeTimestamp;

            while (($line = $this->filesystem->readLine($handle, 'paper_dataset_events_read_failed')) !== false) {
                hash_update($parsedDigest['context'], $line);
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
                if (isset($identities[$event->eventId])) {
                    throw new \RuntimeException('paper_dataset_duplicate_identity');
                }

                $sequenceKey = $this->sequenceKey($event);
                if ($event->sequence !== null) {
                    $sequence = BigInteger::of($event->sequence);
                    if (isset($lastSequences[$sequenceKey])) {
                        $last = $lastSequences[$sequenceKey];
                        if ($sequence->isLessThanOrEqualTo($last)) {
                            throw new \RuntimeException('paper_dataset_sequence_invalid');
                        }
                        if ($sequence->isGreaterThan($last->plus(1))) {
                            $sequenceGaps[$sequenceKey] = ($sequenceGaps[$sequenceKey] ?? 0) + 1;
                        }
                    }
                    $lastSequences[$sequenceKey] = $sequence;
                }

                $identities[$event->eventId] = [
                    'payload_hash' => $event->payloadHash,
                    'event_hash' => hash('sha256', CanonicalJson::encode($event->toArray())),
                ];
                $channels[] = $event->channel->value;
                ++$eventCount;
                $lastEventId = $event->eventId;
                $startExchangeTimestamp = $this->minimumTimestamp(
                    $startExchangeTimestamp,
                    $event->exchangeTimestamp,
                );
                $latestExchangeTimestamp = $this->maximumTimestamp(
                    $latestExchangeTimestamp,
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
            $durableSha256 = hash_final($parsedDigest['context']);
            if (!$this->filesystem->seek($handle, 0, SEEK_SET, 'paper_dataset_events_tail_rehash')) {
                throw new \RuntimeException('paper_dataset_events_snapshot_changed');
            }
            $rehash = $this->filesystem->checksum($handle, 'paper_dataset_events_tail_rehash');
            if ($rehash['bytes'] !== $durableSize
                || !hash_equals($durableSha256, $rehash['checksum'])
            ) {
                throw new \RuntimeException('paper_dataset_events_snapshot_changed');
            }
            $finalStatistics = $this->filesystem->stat($handle, 'paper_dataset_events_tail_validation');
            if ($finalStatistics === false
                || !$this->isPrivateRegularFile($finalStatistics)
                || !isset($finalStatistics['size'])
                || !\is_int($finalStatistics['size'])
                || $finalStatistics['size'] !== $durableSize
                || !$this->sameFile($statistics, $finalStatistics)
            ) {
                throw new \RuntimeException('paper_dataset_events_snapshot_changed');
            }
            $this->assertHandleMatchesPath(
                $handle,
                $this->eventsPath,
                'paper_dataset_events_tail_validation',
            );
        } finally {
            fclose($handle);
        }

        $channels = array_values(array_unique($channels));
        sort($channels, SORT_STRING);
        ksort($sequenceGaps, SORT_STRING);
        $this->identities = $identities;
        $this->lastSequences = $lastSequences;
        $this->sequenceGaps = $sequenceGaps;
        $this->channels = $channels;
        $this->eventCount = $eventCount;
        $this->scannedBytes = $durableSize;
        $this->scannedPrefixSha256 = $durableSha256;
        $this->scannedFileIdentity = [
            'dev' => $statistics['dev'],
            'ino' => $statistics['ino'],
        ];
        $this->lastEventId = $lastEventId;
        $this->startExchangeTimestamp = $startExchangeTimestamp;
        $this->latestExchangeTimestamp = $latestExchangeTimestamp;
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

    private function assertEventMatchesManifest(#[\SensitiveParameter] PaperMarketEvent $event): void
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

    /** @return array{size: int, sha256: string, identity: array{dev: int, ino: int}} */
    private function appendDurably(#[\SensitiveParameter] string $line): array
    {
        $this->assertPinnedDirectories();
        $handle = $this->openRegularFile($this->eventsPath, 'a+b', 'paper_dataset_events_open_failed');
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
                $this->assertParsedSnapshotContinuity($handle, $this->scannedBytes);
                $expectedAppendedSha256 = $this->checksumPrefix(
                    $handle,
                    $this->scannedBytes,
                    'paper_dataset_events_snapshot_validation',
                    $line,
                );
                try {
                    $this->assertPinnedDirectories();
                    $this->writeAll($handle, $line, 'paper_dataset_events_write_failed');
                    $this->flushHandle($handle, 'paper_dataset_events_flush_failed');
                    $this->assertPinnedDirectories();
                    $statistics = $this->filesystem->stat($handle, 'paper_dataset_events_read_failed');
                    if ($statistics === false || !isset($statistics['size']) || !\is_int($statistics['size'])) {
                        throw new \RuntimeException('paper_dataset_events_read_failed');
                    }
                    if ($statistics['size'] !== $originalLength + strlen($line)) {
                        throw new \RuntimeException('paper_dataset_events_write_failed');
                    }
                    $this->assertParsedSnapshotContinuity($handle, $statistics['size']);
                    $this->assertHandleMatchesPath($handle, $this->eventsPath);
                    $appendedSha256 = $this->checksumPrefix($handle, $statistics['size']);
                    $this->assertExpectedAppendedChecksum($expectedAppendedSha256, $appendedSha256);
                    $this->assertParsedSnapshotContinuity($handle, $statistics['size']);
                    $finalAppendedSha256 = $this->checksumPrefix($handle, $statistics['size']);
                    $this->assertExpectedAppendedChecksum($expectedAppendedSha256, $finalAppendedSha256);

                    return [
                        'size' => $statistics['size'],
                        'sha256' => $finalAppendedSha256,
                        'identity' => [
                            'dev' => $statistics['dev'],
                            'ino' => $statistics['ino'],
                        ],
                    ];
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

    private function assertExpectedAppendedChecksum(string $expected, string $actual): void
    {
        if (hash_equals($expected, $actual)) {
            return;
        }

        $this->usable = false;

        throw new \RuntimeException('paper_dataset_events_snapshot_changed');
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

    private function checksumEventsFile(): string
    {
        $this->assertPinnedDirectories();
        $handle = $this->openRegularFile($this->eventsPath, 'rb', 'paper_dataset_events_unreadable');
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_events_lock_failed');
            }
            try {
                $this->assertHandleMatchesPath($handle, $this->eventsPath);
                $this->flushHandle($handle, 'paper_dataset_events_flush_failed');
                $this->assertParsedSnapshotContinuity($handle, $this->scannedBytes);
                if (!$this->filesystem->seek(
                    $handle,
                    0,
                    SEEK_SET,
                    'paper_dataset_events_checksum_failed',
                )) {
                    throw new \RuntimeException('paper_dataset_checksum_failed');
                }
                $result = $this->filesystem->checksum($handle, 'paper_dataset_events_checksum_failed');
                if ($result['bytes'] !== $this->scannedBytes) {
                    throw new \RuntimeException('paper_dataset_checksum_failed');
                }
                if ($this->scannedPrefixSha256 === null
                    || !hash_equals($this->scannedPrefixSha256, $result['checksum'])
                ) {
                    $this->usable = false;

                    throw new \RuntimeException('paper_dataset_events_snapshot_changed');
                }
                $this->assertParsedSnapshotContinuity($handle, $this->scannedBytes);
                $this->assertPinnedDirectories();
                $this->assertHandleMatchesPath($handle, $this->eventsPath);

                return $result['checksum'];
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function assertParsedSnapshotContinuity($handle, int $expectedFileSize): void
    {
        try {
            $statistics = $this->filesystem->stat(
                $handle,
                'paper_dataset_events_snapshot_validation',
            );
            if ($statistics === false
                || !$this->isPrivateRegularFile($statistics)
                || !isset($statistics['size'])
                || !\is_int($statistics['size'])
                || $statistics['size'] !== $expectedFileSize
                || $this->scannedFileIdentity === null
                || !$this->sameFile($this->scannedFileIdentity, $statistics)
                || $this->scannedPrefixSha256 === null
            ) {
                throw new \RuntimeException('paper_dataset_events_snapshot_changed');
            }

            $prefixSha256 = $this->checksumPrefix(
                $handle,
                $this->scannedBytes,
                'paper_dataset_events_snapshot_validation',
            );
            if (!hash_equals($this->scannedPrefixSha256, $prefixSha256)) {
                throw new \RuntimeException('paper_dataset_events_snapshot_changed');
            }

            $finalStatistics = $this->filesystem->stat(
                $handle,
                'paper_dataset_events_snapshot_validation',
            );
            if ($finalStatistics === false
                || !$this->isPrivateRegularFile($finalStatistics)
                || !isset($finalStatistics['size'])
                || !\is_int($finalStatistics['size'])
                || $finalStatistics['size'] !== $expectedFileSize
                || !$this->sameFile($statistics, $finalStatistics)
            ) {
                throw new \RuntimeException('paper_dataset_events_snapshot_changed');
            }
            $this->assertPinnedDirectories();
            $this->assertHandleMatchesPath(
                $handle,
                $this->eventsPath,
                'paper_dataset_events_snapshot_validation',
            );
        } catch (\Throwable $failure) {
            $this->usable = false;
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_events_snapshot_changed'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_events_snapshot_changed', 0, $failure);
        }
    }

    private function writeManifestAtomically(PaperDatasetManifest $manifest): void
    {
        $this->assertPinnedDirectories();
        $encoded = $this->codec->encode($manifest);
        $encodedBytes = strlen($encoded);
        $encodedChecksum = hash('sha256', $encoded);
        $destinationStat = $this->pathStatIfPresent($this->manifestPath);
        $previousContents = null;
        if ($destinationStat !== null) {
            $previousContents = $this->readStoredManifestContents();
            $stored = $this->codec->decode($previousContents);
            if ($stored->state !== PaperDatasetState::RECORDING && $stored != $manifest) {
                throw new \RuntimeException('paper_dataset_not_recording');
            }
        }
        $temporary = @tempnam($this->datasetDirectory, '.manifest-');
        if ($temporary === false) {
            throw new \RuntimeException('paper_dataset_manifest_temp_failed');
        }
        if (dirname($temporary) !== $this->datasetDirectory) {
            @unlink($temporary);
            throw new \RuntimeException('paper_dataset_manifest_temp_failed');
        }
        $handle = null;
        $backup = null;
        $publicationAttempted = false;
        $directoryChanged = false;
        try {
            $this->assertPinnedDirectories();
            $handle = $this->openRegularFile($temporary, 'r+b', 'paper_dataset_manifest_open_failed');
            $this->writeAll($handle, $encoded, 'paper_dataset_manifest_write_failed');
            $this->flushHandle($handle, 'paper_dataset_manifest_flush_failed');
            $temporaryStat = $this->assertManifestContentSnapshot(
                $handle,
                $temporary,
                $encodedBytes,
                $encodedChecksum,
            );
            if ($previousContents !== null) {
                $backup = $this->createManifestBackup($previousContents);
                $this->syncDatasetDirectory('paper_dataset_manifest_backup_directory_sync');
            }
            $this->assertPinnedDirectories();
            $this->assertPathUnchanged($this->manifestPath, $destinationStat);
            $this->assertManifestContentSnapshot(
                $handle,
                $temporary,
                $encodedBytes,
                $encodedChecksum,
                $temporaryStat,
            );
            if (!$this->filesystem->move(
                $temporary,
                $this->manifestPath,
                'paper_dataset_manifest_publish',
            )) {
                throw new \RuntimeException('paper_dataset_manifest_rename_failed');
            }
            $publicationAttempted = true;
            try {
                $this->assertPinnedDirectories();
                $this->assertManifestContentSnapshot(
                    $handle,
                    $this->manifestPath,
                    $encodedBytes,
                    $encodedChecksum,
                    $temporaryStat,
                );
            } catch (\Throwable $publicationFailure) {
                if ($this->isDirectoryChangedFailure($publicationFailure)) {
                    throw $publicationFailure;
                }
                $this->restorePreviousManifest($backup);
                if ($backup !== null) {
                    $this->discardManifestBackup($backup);
                }
                $backup = null;

                throw $publicationFailure;
            }
            $this->syncDatasetDirectory();
            $this->assertPinnedDirectories();
            if ($backup !== null) {
                $this->discardManifestBackup($backup);
                $backup = null;
            }
        } catch (\Throwable $failure) {
            $directoryChanged = $this->isDirectoryChangedFailure($failure);

            throw $failure;
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if (!$directoryChanged && file_exists($temporary)) {
                @unlink($temporary);
            }
            if ($backup !== null) {
                if (\is_resource($backup['handle'])) {
                    fclose($backup['handle']);
                }
                if (!$directoryChanged && !$publicationAttempted && file_exists($backup['path'])) {
                    @unlink($backup['path']);
                }
            }
        }
    }

    private function isDirectoryChangedFailure(\Throwable $failure): bool
    {
        do {
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_directory_changed'
            ) {
                return true;
            }
            $failure = $failure->getPrevious();
        } while ($failure !== null);

        return false;
    }

    /**
     * @param resource                     $handle
     * @param array<string, mixed>|null $expectedIdentity
     *
     * @return array<string, mixed>
     */
    private function assertManifestContentSnapshot(
        $handle,
        #[\SensitiveParameter] string $path,
        int $expectedBytes,
        string $expectedChecksum,
        ?array $expectedIdentity = null,
    ): array {
        $statistics = $this->filesystem->stat($handle, 'paper_dataset_manifest_snapshot_validation');
        if ($statistics === false
            || !$this->isPrivateRegularFile($statistics)
            || !isset($statistics['size'])
            || !\is_int($statistics['size'])
            || $statistics['size'] !== $expectedBytes
            || ($expectedIdentity !== null && !$this->sameFile($expectedIdentity, $statistics))
            || !$this->filesystem->seek(
                $handle,
                0,
                SEEK_SET,
                'paper_dataset_manifest_snapshot_validation',
            )
        ) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }
        $snapshot = $this->filesystem->checksum(
            $handle,
            'paper_dataset_manifest_snapshot_validation',
        );
        if ($snapshot['bytes'] !== $expectedBytes
            || !hash_equals($expectedChecksum, $snapshot['checksum'])
        ) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }
        $finalStatistics = $this->filesystem->stat(
            $handle,
            'paper_dataset_manifest_snapshot_validation',
        );
        if ($finalStatistics === false
            || !$this->isPrivateRegularFile($finalStatistics)
            || !isset($finalStatistics['size'])
            || !\is_int($finalStatistics['size'])
            || $finalStatistics['size'] !== $expectedBytes
            || !$this->sameFile($statistics, $finalStatistics)
        ) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }
        $this->assertHandleMatchesPath(
            $handle,
            $path,
            'paper_dataset_manifest_snapshot_validation',
        );

        return $finalStatistics;
    }

    /**
     * @return array{
     *   path: string,
     *   handle: resource,
     *   bytes: int,
     *   checksum: string,
     *   identity: array<string, mixed>,
     *   contents: string
     * }
     */
    private function createManifestBackup(#[\SensitiveParameter] string $contents): array
    {
        return $this->createManifestRecoveryFile(
            $contents,
            '.manifest-backup-',
            'paper_dataset_manifest_backup_failed',
        );
    }

    /**
     * @return array{
     *   path: string,
     *   handle: resource,
     *   bytes: int,
     *   checksum: string,
     *   identity: array<string, mixed>,
     *   contents: string
     * }
     */
    private function createManifestRecoveryFile(
        #[\SensitiveParameter] string $contents,
        string $prefix,
        string $error,
    ): array
    {
        $path = @tempnam($this->datasetDirectory, $prefix);
        if ($path === false || dirname($path) !== $this->datasetDirectory) {
            if ($path !== false) {
                @unlink($path);
            }
            throw new \RuntimeException($error);
        }
        $handle = null;
        try {
            $handle = $this->openRegularFile($path, 'r+b', $error);
            $this->writeAll($handle, $contents, $error);
            $this->flushHandle($handle, $error);
            $identity = $this->assertManifestContentSnapshot(
                $handle,
                $path,
                strlen($contents),
                hash('sha256', $contents),
            );

            return [
                'path' => $path,
                'handle' => $handle,
                'bytes' => strlen($contents),
                'checksum' => hash('sha256', $contents),
                'identity' => $identity,
                'contents' => $contents,
            ];
        } catch (\Throwable $failure) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            @unlink($path);

            throw $failure;
        }
    }

    /**
     * @param array{
     *   path: string,
     *   handle: resource,
     *   bytes: int,
     *   checksum: string,
     *   identity: array<string, mixed>,
     *   contents: string
     * }|null $backup
     */
    private function restorePreviousManifest(#[\SensitiveParameter] ?array $backup): void
    {
        if ($backup === null) {
            $this->assertPinnedDirectories();
            if (file_exists($this->manifestPath) && !@unlink($this->manifestPath)) {
                throw new \RuntimeException('paper_dataset_manifest_restore_failed');
            }
            $this->syncDatasetDirectory();
            $this->assertPinnedDirectories();

            return;
        }

        $backupFailure = null;
        try {
            $this->assertManifestContentSnapshot(
                $backup['handle'],
                $backup['path'],
                $backup['bytes'],
                $backup['checksum'],
                $backup['identity'],
            );
        } catch (\Throwable $failure) {
            $backupFailure = $failure;
        }
        $this->assertPinnedDirectories();
        $restore = $this->createManifestRecoveryFile(
            $backup['contents'],
            '.manifest-restore-',
            'paper_dataset_manifest_restore_failed',
        );
        try {
            $this->syncDatasetDirectory('paper_dataset_manifest_restore_candidate_directory_sync');
            if (!$this->filesystem->move(
                $restore['path'],
                $this->manifestPath,
                'paper_dataset_manifest_restore',
            )) {
                throw new \RuntimeException('paper_dataset_manifest_restore_failed');
            }
            $this->assertManifestContentSnapshot(
                $restore['handle'],
                $this->manifestPath,
                $restore['bytes'],
                $restore['checksum'],
                $restore['identity'],
            );
            $this->syncDatasetDirectory();
            $this->assertPinnedDirectories();
            if ($backupFailure !== null) {
                throw $backupFailure;
            }
        } finally {
            if (\is_resource($restore['handle'])) {
                fclose($restore['handle']);
            }
        }
    }

    /**
     * @param array{
     *   path: string,
     *   handle: resource,
     *   bytes: int,
     *   checksum: string,
     *   identity: array<string, mixed>,
     *   contents: string
     * } $backup
     */
    private function discardManifestBackup(#[\SensitiveParameter] array $backup): void
    {
        if (!@unlink($backup['path'])) {
            throw new \RuntimeException('paper_dataset_manifest_backup_cleanup_failed');
        }
        fclose($backup['handle']);
    }

    private function writeFinalManifestAtomically(PaperDatasetManifest $manifest): void
    {
        try {
            $this->writeManifestAtomically($manifest);
        } catch (\Throwable $failure) {
            $this->usable = false;
            try {
                $stored = $this->readStoredManifest();
                $this->assertSameDataset($this->identityManifest, $stored);
                $this->currentManifest = $stored;
            } catch (\Throwable) {
                // Keep the last unambiguous in-memory manifest while remaining poisoned.
            }

            throw $failure;
        }

        $this->currentManifest = $manifest;
    }

    private function syncDatasetDirectory(
        string $operation = 'paper_dataset_manifest_directory_sync_failed',
    ): void
    {
        $this->assertPinnedDirectories();
        $handle = @fopen($this->datasetDirectory, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_manifest_directory_open_failed');
        }

        try {
            $statistics = $this->filesystem->stat($handle, $operation);
            if ($statistics === false
                || !$this->isPrivateDirectory($statistics)
                || !$this->sameFile($statistics, $this->datasetDirectoryIdentity)
            ) {
                throw new \RuntimeException('paper_dataset_directory_changed');
            }
            if (!$this->filesystem->sync($handle, $operation)) {
                throw new \RuntimeException('paper_dataset_manifest_directory_sync_failed');
            }
            $this->assertPinnedDirectories();
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, #[\SensitiveParameter] string $contents, string $error): void
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

    /** @param resource $handle */
    private function checksumPrefix(
        $handle,
        int $length,
        string $operation = 'paper_dataset_events_read_failed',
        #[\SensitiveParameter]
        string $suffix = '',
    ): string
    {
        if (!$this->filesystem->seek($handle, 0, SEEK_SET, $operation)) {
            throw new \RuntimeException($operation);
        }

        $remaining = $length;
        $context = hash_init('sha256');
        while ($remaining > 0) {
            $chunk = $this->filesystem->read(
                $handle,
                min(8192, $remaining),
                $operation,
            );
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException($operation);
            }
            hash_update($context, $chunk);
            $remaining -= strlen($chunk);
        }
        if ($suffix !== '') {
            hash_update($context, $suffix);
        }

        return hash_final($context);
    }

    /**
     * @param resource $handle
     *
     * @return array{sha256: string, context: \HashContext}
     */
    private function readPrefixForScan($handle, int $length): array
    {
        if (!$this->filesystem->seek($handle, 0, SEEK_SET, 'paper_dataset_events_read_failed')) {
            throw new \RuntimeException('paper_dataset_events_read_failed');
        }

        $remaining = $length;
        $context = hash_init('sha256');
        while ($remaining > 0) {
            $chunk = $this->filesystem->read(
                $handle,
                min(8192, $remaining),
                'paper_dataset_events_read_failed',
            );
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
            hash_update($context, $chunk);
            $remaining -= strlen($chunk);
        }

        return [
            'sha256' => hash_final(hash_copy($context)),
            'context' => $context,
        ];
    }

    private function ensureEventsFile(bool $create): void
    {
        if (!file_exists($this->eventsPath)) {
            if (!$create) {
                throw new \RuntimeException('paper_dataset_events_missing');
            }
            $handle = $this->filesystem->createPrivateFile(
                $this->eventsPath,
                'paper_dataset_events_create_failed',
            );
            if ($handle === false) {
                throw new \RuntimeException('paper_dataset_events_create_failed');
            }
            fclose($handle);
        }
        $this->pathStat($this->eventsPath, 'paper_dataset_events_invalid');
    }

    private function ensureLockFile(bool $create): void
    {
        if (!file_exists($this->lockPath)) {
            if (!$create) {
                throw new \RuntimeException('paper_dataset_lock_invalid');
            }
            $handle = $this->filesystem->createPrivateFile(
                $this->lockPath,
                'paper_dataset_lock_create_failed',
            );
            if ($handle === false) {
                if (!is_file($this->lockPath)) {
                    throw new \RuntimeException('paper_dataset_lock_create_failed');
                }
            } else {
                fclose($handle);
            }
        }

        $this->pathStat($this->lockPath, 'paper_dataset_lock_invalid');
        $statistics = $this->pathStat($this->lockPath, 'paper_dataset_lock_invalid');
        $this->lockFileIdentity = [
            'dev' => $statistics['dev'],
            'ino' => $statistics['ino'],
        ];
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withDatasetLock(#[\SensitiveParameter] callable $operation): mixed
    {
        $this->assertPinnedDirectories();
        $directoryHandle = null;
        $directoryLocked = false;
        $handle = null;
        $locked = false;
        $result = null;
        $failure = null;
        try {
            $directoryHandle = $this->filesystem->openDirectory(
                $this->datasetDirectory,
                'paper_dataset_lock_open_failed',
            );
            if ($directoryHandle === false) {
                throw new \RuntimeException('paper_dataset_lock_open_failed');
            }
            $directoryStatistics = $this->filesystem->stat(
                $directoryHandle,
                'paper_dataset_lock_open_failed',
            );
            if ($directoryStatistics === false
                || !$this->isPrivateDirectory($directoryStatistics)
                || !$this->sameFile($this->datasetDirectoryIdentity, $directoryStatistics)
            ) {
                throw new \RuntimeException('paper_dataset_directory_changed');
            }
            if (!flock($directoryHandle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_lock_failed');
            }
            $directoryLocked = true;
            $this->assertDirectoryHandleMatchesPath(
                $directoryHandle,
                $this->datasetDirectory,
                $this->datasetDirectoryIdentity,
                'paper_dataset_directory_changed',
                requirePrivatePermissions: true,
            );
            $handle = $this->openRegularFile($this->lockPath, 'r+b', 'paper_dataset_lock_open_failed');
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_dataset_lock_failed');
            }
            $locked = true;
            $this->assertDatasetLockHandle($handle);
            $this->assertPinnedDirectories();
            $result = $operation();
            $this->assertPinnedDirectories();
        } catch (\Throwable $caught) {
            $failure = $caught;
        } finally {
            if ($locked && \is_resource($handle)) {
                try {
                    $this->assertDatasetLockHandle($handle);
                } catch (\Throwable $lockFailure) {
                    $this->usable = false;
                    $failure = $lockFailure;
                }
                flock($handle, LOCK_UN);
            }
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if ($directoryLocked && \is_resource($directoryHandle)) {
                flock($directoryHandle, LOCK_UN);
            }
            if (\is_resource($directoryHandle)) {
                fclose($directoryHandle);
            }
        }

        try {
            $this->assertPinnedDirectories();
        } catch (\Throwable $directoryFailure) {
            $this->usable = false;
            throw new \RuntimeException('paper_dataset_directory_changed', 0, $failure ?? $directoryFailure);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }

    /** @param resource $handle */
    private function assertDatasetLockHandle($handle): void
    {
        try {
            $opened = $this->assertHandleMatchesPath($handle, $this->lockPath);
            if (!$this->sameFile($this->lockFileIdentity, $opened)) {
                throw new \RuntimeException('paper_dataset_file_changed');
            }
        } catch (\Throwable $failure) {
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_file_changed'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_file_changed', 0, $failure);
        }
    }

    private function readStoredManifest(): PaperDatasetManifest
    {
        return $this->codec->decode($this->readStoredManifestContents());
    }

    private function readStoredManifestContents(): string
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

        return $json;
    }

    /** @return resource */
    private function openRegularFile(#[\SensitiveParameter] string $path, string $mode, string $openError)
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
    private function assertHandleMatchesPath(
        $handle,
        #[\SensitiveParameter] string $path,
        string $operation = 'paper_dataset_file_validation_failed',
    ): array
    {
        $opened = $this->filesystem->stat($handle, $operation);
        if ($opened === false || !$this->isPrivateRegularFile($opened)) {
            throw new \RuntimeException('paper_dataset_file_validation_failed');
        }

        $current = $this->pathStat($path, 'paper_dataset_file_changed');
        if (!$this->sameFile($opened, $current)) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }

        return $opened;
    }

    /** @return array<string, mixed> */
    private function pathStat(#[\SensitiveParameter] string $path, string $missingError): array
    {
        $this->assertNoSymlinkComponents($path);
        $statistics = $this->filesystem->pathStat($path, 'paper_dataset_file_validation_failed');
        if ($statistics === false) {
            throw new \RuntimeException($missingError);
        }
        if (($statistics['mode'] & self::FILE_TYPE_MASK) === 0120000) {
            throw new \RuntimeException('paper_dataset_symlink_rejected');
        }
        if (!$this->isPrivateRegularFile($statistics)) {
            throw new \RuntimeException('paper_dataset_file_validation_failed');
        }

        return $statistics;
    }

    /** @return array<string, mixed>|null */
    private function pathStatIfPresent(#[\SensitiveParameter] string $path): ?array
    {
        $this->assertNoSymlinkComponents($path);
        $statistics = $this->filesystem->pathStat($path, 'paper_dataset_file_validation_failed');
        if ($statistics === false) {
            return null;
        }
        if (($statistics['mode'] & self::FILE_TYPE_MASK) === 0120000) {
            throw new \RuntimeException('paper_dataset_symlink_rejected');
        }
        if (!$this->isPrivateRegularFile($statistics)) {
            throw new \RuntimeException('paper_dataset_file_validation_failed');
        }

        return $statistics;
    }

    /** @param array<string, mixed>|null $expected */
    private function assertPathUnchanged(#[\SensitiveParameter] string $path, ?array $expected): void
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

    /** @param array<string, mixed> $statistics */
    private function isPrivateRegularFile(array $statistics): bool
    {
        return $this->isRegularFile($statistics)
            && isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & 0777) === 0600
            && isset($statistics['nlink'])
            && \is_int($statistics['nlink'])
            && $statistics['nlink'] === 1;
    }

    /** @param array<string, mixed> $statistics */
    private function isDirectory(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateDirectory(array $statistics): bool
    {
        return $this->isDirectory($statistics)
            && isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & 0777) === 0700;
    }

    /** @return array{dev: int, ino: int} */
    private function pinDirectoryIdentity(#[\SensitiveParameter] string $path, string $error): array
    {
        $this->assertNoSymlinkComponents($path);
        $statistics = $this->filesystem->pathStat($path, 'paper_dataset_directory_validation');
        if ($statistics === false
            || !$this->isDirectory($statistics)
            || !isset($statistics['dev'], $statistics['ino'])
            || !\is_int($statistics['dev'])
            || !\is_int($statistics['ino'])
        ) {
            throw new \RuntimeException($error);
        }

        return ['dev' => $statistics['dev'], 'ino' => $statistics['ino']];
    }

    private function assertPinnedDirectories(): void
    {
        try {
            $root = $this->pinManagedDirectoryIdentity(
                $this->datasetRoot,
                'paper_dataset_directory_changed',
            );
            $dataset = $this->pinManagedDirectoryIdentity(
                $this->datasetDirectory,
                'paper_dataset_directory_changed',
            );
            if (!$this->sameFile($this->datasetRootIdentity, $root)
                || !$this->sameFile($this->datasetDirectoryIdentity, $dataset)
            ) {
                throw new \RuntimeException('paper_dataset_directory_changed');
            }
        } catch (\Throwable $failure) {
            $this->usable = false;
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_directory_changed'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_directory_changed', 0, $failure);
        }
    }

    /** @return array{dev: int, ino: int} */
    private function pinManagedDirectoryIdentity(#[\SensitiveParameter] string $path, string $error): array
    {
        $this->assertNoSymlinkComponents($path);
        $statistics = $this->filesystem->pathStat($path, 'paper_dataset_directory_validation');
        if ($statistics === false
            || !$this->isPrivateDirectory($statistics)
            || !isset($statistics['dev'], $statistics['ino'])
            || !\is_int($statistics['dev'])
            || !\is_int($statistics['ino'])
        ) {
            throw new \RuntimeException($error);
        }

        return ['dev' => $statistics['dev'], 'ino' => $statistics['ino']];
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

    private function prepareDirectory(#[\SensitiveParameter] string $directory): string
    {
        if ($directory === '' || str_contains($directory, "\0")) {
            throw new \RuntimeException('paper_dataset_root_invalid');
        }

        return $this->prepareManagedDirectory($directory, 'paper_dataset_root_invalid')['path'];
    }

    /** @return array{path: string, identity: array{dev: int, ino: int}} */
    private function prepareManagedDirectory(
        #[\SensitiveParameter] string $directory,
        string $error,
    ): array {
        $this->assertNoSymlinkComponents($directory);
        $pinned = is_dir($directory) ? $this->openPinnedDirectory($directory, $error) : null;
        try {
            $this->ensureDirectory($directory);
            if ($pinned === null) {
                $pinned = $this->openPinnedDirectory($directory, $error);
            }
            $resolved = realpath($directory);
            if ($resolved === false) {
                throw new \RuntimeException($error);
            }
            $this->assertDirectoryHandleMatchesPath(
                $pinned['handle'],
                $resolved,
                $pinned['identity'],
                'paper_dataset_directory_changed',
            );

            return ['path' => $resolved, 'identity' => $pinned['identity']];
        } finally {
            if ($pinned !== null) {
                fclose($pinned['handle']);
            }
        }
    }

    private function storedManifestExists(
        #[\SensitiveParameter] string $root,
        PaperDatasetManifest $manifest,
    ): bool
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

    private function ensureDirectory(#[\SensitiveParameter] string $directory): void
    {
        $this->assertNoSymlinkComponents($directory);
        if (is_dir($directory)) {
            $this->validateAndSyncManagedDirectory($directory);

            return;
        }

        $missing = [];
        $ancestor = rtrim($directory, DIRECTORY_SEPARATOR);
        while (!is_dir($ancestor)) {
            if ($ancestor === ''
                || file_exists($ancestor)
                || is_link($ancestor)
            ) {
                throw new \RuntimeException('paper_dataset_directory_create_failed');
            }
            $missing[] = $ancestor;
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new \RuntimeException('paper_dataset_directory_create_failed');
            }
            $ancestor = $parent;
        }
        $this->assertNoSymlinkComponents($ancestor);

        foreach (array_reverse($missing) as $candidate) {
            $this->assertNoSymlinkComponents($candidate);
            if (!$this->filesystem->createDirectory($candidate, 0700)) {
                $this->assertNoSymlinkComponents($candidate);
                if (!is_dir($candidate)) {
                    throw new \RuntimeException('paper_dataset_directory_create_failed');
                }
            }
            $this->validateAndSyncManagedDirectory($candidate);
        }
    }

    private function validateAndSyncManagedDirectory(#[\SensitiveParameter] string $directory): void
    {
        $pinned = $this->openPinnedDirectory($directory, 'paper_dataset_directory_create_failed');
        try {
            $statistics = $this->filesystem->stat(
                $pinned['handle'],
                'paper_dataset_directory_validation',
            );
            if ($statistics === false
                || !isset($statistics['mode'])
                || !\is_int($statistics['mode'])
                || ($statistics['mode'] & 0777) !== 0700
            ) {
                throw new \RuntimeException('paper_dataset_directory_mode_failed');
            }
            $this->syncDirectoryParent($directory);
            $this->assertDirectoryHandleMatchesPath(
                $pinned['handle'],
                $directory,
                $pinned['identity'],
                'paper_dataset_directory_changed',
            );
        } finally {
            fclose($pinned['handle']);
        }
    }

    /** @return array{handle: resource, identity: array{dev: int, ino: int}} */
    private function openPinnedDirectory(#[\SensitiveParameter] string $directory, string $error): array
    {
        $this->assertNoSymlinkComponents($directory);
        $handle = $this->filesystem->openDirectory($directory, 'paper_dataset_directory_validation');
        if ($handle === false) {
            throw new \RuntimeException($error);
        }
        try {
            $statistics = $this->filesystem->stat($handle, 'paper_dataset_directory_validation');
            if ($statistics === false
                || !$this->isDirectory($statistics)
                || !isset($statistics['dev'], $statistics['ino'])
                || !\is_int($statistics['dev'])
                || !\is_int($statistics['ino'])
            ) {
                throw new \RuntimeException($error);
            }
            $identity = ['dev' => $statistics['dev'], 'ino' => $statistics['ino']];
            $this->assertDirectoryHandleMatchesPath($handle, $directory, $identity, $error);

            return ['handle' => $handle, 'identity' => $identity];
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /**
     * @param resource                     $handle
     * @param array{dev: int, ino: int} $expected
     */
    private function assertDirectoryHandleMatchesPath(
        $handle,
        #[\SensitiveParameter] string $directory,
        array $expected,
        string $error,
        bool $requirePrivatePermissions = false,
    ): void {
        $opened = $this->filesystem->stat($handle, 'paper_dataset_directory_validation');
        $current = $requirePrivatePermissions
            ? $this->pinManagedDirectoryIdentity($directory, $error)
            : $this->pinDirectoryIdentity($directory, $error);
        if ($opened === false
            || ($requirePrivatePermissions
                ? !$this->isPrivateDirectory($opened)
                : !$this->isDirectory($opened))
            || !$this->sameFile($expected, $opened)
            || !$this->sameFile($expected, $current)
        ) {
            throw new \RuntimeException($error);
        }
    }

    private function syncDirectoryParent(#[\SensitiveParameter] string $directory): void
    {
        $parent = dirname($directory);
        $expected = $this->pinDirectoryIdentity(
            $parent,
            'paper_dataset_directory_parent_sync_failed',
        );
        $handle = $this->filesystem->openDirectory(
            $parent,
            'paper_dataset_directory_parent_sync_failed',
        );
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_directory_parent_sync_failed');
        }

        try {
            $opened = $this->filesystem->stat(
                $handle,
                'paper_dataset_directory_parent_sync_failed',
            );
            if ($opened === false
                || !$this->isDirectory($opened)
                || !$this->sameFile($expected, $opened)
            ) {
                throw new \RuntimeException('paper_dataset_directory_parent_sync_failed');
            }
            if (!$this->filesystem->sync($handle, 'paper_dataset_directory_parent_sync_failed')) {
                throw new \RuntimeException('paper_dataset_directory_parent_sync_failed');
            }
            $current = $this->pinDirectoryIdentity(
                $parent,
                'paper_dataset_directory_parent_sync_failed',
            );
            if (!$this->sameFile($expected, $current)) {
                throw new \RuntimeException('paper_dataset_directory_parent_sync_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    private function assertNoSymlinkComponents(#[\SensitiveParameter] string $path): void
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
