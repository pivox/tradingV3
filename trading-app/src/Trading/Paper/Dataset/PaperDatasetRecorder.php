<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigInteger;

final class PaperDatasetRecorder
{
    private const MAX_APPEND_INTENT_BYTES = 9_000_000;
    private const MAX_MANIFEST_TRANSITION_BYTES = 200_000;
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const FILE_TYPE_MASK = 0170000;

    private PaperDatasetManifestCodec $codec;
    private PaperDatasetVerifier $verifier;
    private PaperDatasetRecorderFilesystem $filesystem;
    private PaperDatasetLineReader $lineReader;
    private string $datasetRoot;
    private string $datasetDirectory;
    private string $eventsPath;
    private string $manifestPath;
    private string $lockPath;
    private string $appendIntentPath;
    private string $appendIntentStagingPath;
    private string $manifestTransitionPath;
    private string $manifestTransitionStagingPath;
    private string $manifestCandidatePath;
    private string $manifestCandidateStagingPath;
    private string $manifestBackupPath;
    private string $manifestBackupStagingPath;
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
    private bool $terminalTransitionAwaitingAuthentication = false;

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
        $this->lineReader = new PaperDatasetLineReader($this->filesystem);
        $this->identityManifest = $manifest;

        if ($manifest->state !== PaperDatasetState::RECORDING
            && !$this->storedManifestOrRecoveryEvidenceExists($root, $manifest)
        ) {
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
        $this->appendIntentPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.append-intent.json';
        $this->appendIntentStagingPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.append-intent.json.staging';
        $this->manifestTransitionPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-transition.json';
        $this->manifestTransitionStagingPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-transition.json.staging';
        $this->manifestCandidatePath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-candidate-fixed';
        $this->manifestCandidateStagingPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-candidate-fixed.staging';
        $this->manifestBackupPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-backup-fixed';
        $this->manifestBackupStagingPath = $this->datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-backup-fixed.staging';
        $this->assertNoSymlinkComponents($checkpoints);
        $this->assertNoSymlinkComponents($this->eventsPath);
        $this->assertNoSymlinkComponents($this->manifestPath);
        $this->assertNoSymlinkComponents($this->lockPath);
        $this->assertNoSymlinkComponents($this->appendIntentPath);
        $this->assertNoSymlinkComponents($this->appendIntentStagingPath);
        $this->assertNoSymlinkComponents($this->manifestTransitionPath);
        $this->assertNoSymlinkComponents($this->manifestTransitionStagingPath);
        $this->assertNoSymlinkComponents($this->manifestCandidatePath);
        $this->assertNoSymlinkComponents($this->manifestCandidateStagingPath);
        $this->assertNoSymlinkComponents($this->manifestBackupPath);
        $this->assertNoSymlinkComponents($this->manifestBackupStagingPath);
        $virginCohort = $this->cohortHasNoArtifacts();
        if ($manifest->state !== PaperDatasetState::RECORDING && $virginCohort) {
            throw new \RuntimeException('paper_dataset_initial_state_invalid');
        }
        $this->ensureLockFile(create: $virginCohort);

        $this->withDatasetLock(function () use ($checkpoints, $manifest, $virginCohort): void {
            $this->recoverPendingAppendIntent();
            $this->recoverPendingManifestTransition();
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
                if (!$virginCohort || $this->pathEntryAppearsPresent($this->eventsPath)) {
                    throw new \RuntimeException('paper_dataset_manifest_missing');
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
        $this->recoverPendingAppendIntent();
        $this->recoverPendingManifestTransition('paper_dataset_manifest_write_failed');
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
        $this->writeAppendIntent($event, $line);
        $durableAppend = null;
        try {
            $durableAppend = $this->appendDurably($line);
        } catch (\Throwable $failure) {
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_events_rollback_failed'
            ) {
                throw $failure;
            }
            try {
                $this->removeAppendIntent();
            } catch (\Throwable $cleanupFailure) {
                $this->usable = false;

                throw new \RuntimeException('paper_dataset_append_intent_cleanup_failed', 0, $cleanupFailure);
            }

            throw $failure;
        }
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

        $this->writeRecordingManifestAtomically($nextManifest);
        $this->removeAppendIntent();

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
        try {
            $this->assertUsable();

            return $this->withDatasetLock(fn (): PaperDatasetManifest => $this->completeUnderLock());
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_complete_failed', 0, $failure);
        }
    }

    private function completeUnderLock(): PaperDatasetManifest
    {
        $this->recoverPendingAppendIntent();
        $this->recoverPendingManifestTransition();
        $this->reloadDurableState();
        $this->assertPinnedDirectories();
        if ($this->currentManifest->state === PaperDatasetState::COMPLETE) {
            return $this->verifier->verify($this->datasetDirectory);
        }
        $this->assertRecording();
        $completed = $this->publishTerminalManifestUnderEventsLock(
            state: PaperDatasetState::COMPLETE,
            quality: $this->currentManifest->quality,
        );

        return $this->verifier->verify($this->datasetDirectory);
    }

    public function markIncomplete(): PaperDatasetManifest
    {
        try {
            $this->assertUsable();

            return $this->withDatasetLock(fn (): PaperDatasetManifest => $this->markIncompleteUnderLock());
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_mark_incomplete_failed', 0, $failure);
        }
    }

    private function markIncompleteUnderLock(): PaperDatasetManifest
    {
        $this->recoverPendingAppendIntent();
        $this->recoverPendingManifestTransition();
        $this->reloadDurableState();
        $this->assertPinnedDirectories();
        if ($this->currentManifest->state === PaperDatasetState::INCOMPLETE) {
            return $this->currentManifest;
        }
        $this->assertRecording();
        return $this->publishTerminalManifestUnderEventsLock(
            state: PaperDatasetState::INCOMPLETE,
            quality: PaperMarketDataQuality::INCOMPLETE,
        );
    }

    private function reloadDurableState(): void
    {
        $stored = $this->readStoredManifest();
        $this->assertSameDataset($this->identityManifest, $stored);
        $this->currentManifest = $stored;
        $this->scanDurableTail();

        if ($stored->state !== PaperDatasetState::RECORDING) {
            if (!$this->terminalManifestMatchesDurableFacts($stored)) {
                throw new \RuntimeException('paper_dataset_terminal_manifest_invalid');
            }
            $this->cleanupAuthenticatedTerminalTransition();

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
            $this->writeRecordingManifestAtomically($reconciled);
            $this->currentManifest = $reconciled;
        }
    }

    private function writeRecordingManifestAtomically(PaperDatasetManifest $manifest): void
    {
        try {
            $this->writeManifestAtomically($manifest);
        } catch (\Throwable $failure) {
            $this->usable = false;
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_directory_changed'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_manifest_write_failed', 0, $failure);
        }
    }

    private function writeAppendIntent(
        #[\SensitiveParameter] PaperMarketEvent $event,
        #[\SensitiveParameter] string $canonicalLine,
    ): void {
        if (strlen($canonicalLine) > PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES
            || $this->scannedPrefixSha256 === null
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }

        $contents = $this->encodeAppendIntent([
            'version' => 1,
            'dataset_id' => $this->identityManifest->datasetId,
            'event_id' => $event->eventId,
            'original_events_bytes' => $this->scannedBytes,
            'original_events_sha256' => $this->scannedPrefixSha256,
            'canonical_line_base64' => base64_encode($canonicalLine),
            'canonical_line_sha256' => hash('sha256', $canonicalLine),
        ]);
        if (strlen($contents) > self::MAX_APPEND_INTENT_BYTES) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }

        $this->createFixedMarker(
            $this->appendIntentPath,
            $this->appendIntentStagingPath,
            $contents,
            'paper_dataset_append_intent_flush_failed',
            'paper_dataset_append_intent_directory_sync_failed',
            'paper_dataset_append_intent_publish',
        );
    }

    private function recoverPendingAppendIntent(): void
    {
        $this->recoverFixedArtifactStaging(
            $this->appendIntentPath,
            $this->appendIntentStagingPath,
            'paper_dataset_append_intent_invalid',
            'paper_dataset_append_intent_cleanup_failed',
            'paper_dataset_append_intent_directory_sync_failed',
        );
        if ($this->pathStatIfPresent($this->appendIntentPath) === null) {
            return;
        }

        try {
            $intent = $this->decodeAppendIntent(
                $this->readBoundedFixedFile(
                    $this->appendIntentPath,
                    self::MAX_APPEND_INTENT_BYTES,
                    'paper_dataset_append_intent_read_failed',
                ),
            );
            $handle = $this->openRegularFile($this->eventsPath, 'r+b', 'paper_dataset_events_unreadable');
            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('paper_dataset_events_lock_failed');
                }
                try {
                    $this->recoverStagedAppendUnderLock($handle, $intent);
                } finally {
                    flock($handle, LOCK_UN);
                }
            } finally {
                fclose($handle);
            }
            $this->removeAppendIntent();
        } catch (\Throwable $failure) {
            $this->usable = false;
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_append_intent_invalid'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_append_intent_invalid', 0, $failure);
        }
    }

    /**
     * @param resource $handle
     * @param array{
     *   version: int,
     *   dataset_id: string,
     *   event_id: string,
     *   original_events_bytes: int,
     *   original_events_sha256: string,
     *   canonical_line_base64: string,
     *   canonical_line_sha256: string,
     *   canonical_line: string
     * } $intent
     */
    private function recoverStagedAppendUnderLock($handle, #[\SensitiveParameter] array $intent): void
    {
        $statistics = $this->filesystem->stat($handle, 'paper_dataset_append_recovery_validation');
        $line = $intent['canonical_line'];
        $boundary = $intent['original_events_bytes'];
        if ($statistics === false
            || !$this->isPrivateRegularFile($statistics)
            || !isset($statistics['size'])
            || !\is_int($statistics['size'])
            || $statistics['size'] < $boundary
            || $statistics['size'] > $boundary + strlen($line)
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        $this->assertHandleMatchesPath(
            $handle,
            $this->eventsPath,
            'paper_dataset_append_recovery_validation',
        );
        $prefixSha256 = $this->checksumPrefix(
            $handle,
            $boundary,
            'paper_dataset_append_recovery_validation',
        );
        if (!hash_equals($intent['original_events_sha256'], $prefixSha256)) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }

        $stagedBytes = $statistics['size'] - $boundary;
        if (!$this->filesystem->seek(
            $handle,
            $boundary,
            SEEK_SET,
            'paper_dataset_append_recovery_validation',
        )) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        $staged = $this->readExactBytes(
            $handle,
            $stagedBytes,
            'paper_dataset_append_recovery_validation',
        );
        if (!hash_equals(substr($line, 0, $stagedBytes), $staged)) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }

        $final = $this->filesystem->stat($handle, 'paper_dataset_append_recovery_validation');
        if ($final === false
            || !$this->isPrivateRegularFile($final)
            || !isset($final['size'])
            || !\is_int($final['size'])
            || $final['size'] !== $statistics['size']
            || !$this->sameFile($statistics, $final)
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        $this->assertHandleMatchesPath(
            $handle,
            $this->eventsPath,
            'paper_dataset_append_recovery_validation',
        );

        if ($stagedBytes === strlen($line)) {
            $this->flushHandle($handle, 'paper_dataset_append_recovery_failed');
            $this->assertRecoveredAppendSnapshot($handle, $statistics, $intent);

            return;
        }
        if (!$this->filesystem->truncate(
            $handle,
            $boundary,
            'paper_dataset_append_recovery_failed',
        )) {
            throw new \RuntimeException('paper_dataset_append_recovery_failed');
        }
        $this->flushHandle($handle, 'paper_dataset_append_recovery_failed');
        $recovered = $this->filesystem->stat($handle, 'paper_dataset_append_recovery_validation');
        if ($recovered === false
            || !$this->isPrivateRegularFile($recovered)
            || !isset($recovered['size'])
            || !\is_int($recovered['size'])
            || $recovered['size'] !== $boundary
            || !$this->sameFile($statistics, $recovered)
        ) {
            throw new \RuntimeException('paper_dataset_append_recovery_failed');
        }
        $recoveredPrefix = $intent;
        $recoveredPrefix['canonical_line'] = '';
        $this->assertRecoveredAppendSnapshot($handle, $recovered, $recoveredPrefix);
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $expectedIdentity
     * @param array{
     *   original_events_bytes: int,
     *   original_events_sha256: string,
     *   canonical_line: string
     * } $intent
     */
    private function assertRecoveredAppendSnapshot(
        $handle,
        array $expectedIdentity,
        #[\SensitiveParameter] array $intent,
    ): void {
        $boundary = $intent['original_events_bytes'];
        $line = $intent['canonical_line'];
        $expectedSize = $boundary + strlen($line);
        $statistics = $this->filesystem->stat($handle, 'paper_dataset_append_recovery_validation');
        if ($statistics === false
            || !$this->isPrivateRegularFile($statistics)
            || !isset($statistics['size'])
            || !\is_int($statistics['size'])
            || $statistics['size'] !== $expectedSize
            || !$this->sameFile($expectedIdentity, $statistics)
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        $this->assertHandleMatchesPath(
            $handle,
            $this->eventsPath,
            'paper_dataset_append_recovery_validation',
        );
        if (!hash_equals(
            $intent['original_events_sha256'],
            $this->checksumPrefix(
                $handle,
                $boundary,
                'paper_dataset_append_recovery_validation',
            ),
        ) || !$this->filesystem->seek(
            $handle,
            $boundary,
            SEEK_SET,
            'paper_dataset_append_recovery_validation',
        ) || !hash_equals(
            $line,
            $this->readExactBytes(
                $handle,
                strlen($line),
                'paper_dataset_append_recovery_validation',
            ),
        )) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        $final = $this->filesystem->stat($handle, 'paper_dataset_append_recovery_validation');
        if ($final === false
            || !$this->isPrivateRegularFile($final)
            || !isset($final['size'])
            || !\is_int($final['size'])
            || $final['size'] !== $expectedSize
            || !$this->sameFile($statistics, $final)
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        $this->assertHandleMatchesPath(
            $handle,
            $this->eventsPath,
            'paper_dataset_append_recovery_validation',
        );
    }

    /**
     * @param array{
     *   version: int,
     *   dataset_id: string,
     *   event_id: string,
     *   original_events_bytes: int,
     *   original_events_sha256: string,
     *   canonical_line_base64: string,
     *   canonical_line_sha256: string
     * } $intent
     */
    private function encodeAppendIntent(#[\SensitiveParameter] array $intent): string
    {
        try {
            return json_encode(
                $intent,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
        } catch (\JsonException $failure) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid', 0, $failure);
        }
    }

    /**
     * @return array{
     *   version: int,
     *   dataset_id: string,
     *   event_id: string,
     *   original_events_bytes: int,
     *   original_events_sha256: string,
     *   canonical_line_base64: string,
     *   canonical_line_sha256: string,
     *   canonical_line: string
     * }
     */
    private function decodeAppendIntent(#[\SensitiveParameter] string $contents): array
    {
        try {
            $decoded = json_decode(
                $contents,
                true,
                32,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (\JsonException $failure) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid', 0, $failure);
        }
        $expectedKeys = [
            'version',
            'dataset_id',
            'event_id',
            'original_events_bytes',
            'original_events_sha256',
            'canonical_line_base64',
            'canonical_line_sha256',
        ];
        if (!\is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== $expectedKeys
            || $decoded['version'] !== 1
            || !\is_string($decoded['dataset_id'])
            || !hash_equals($this->identityManifest->datasetId, $decoded['dataset_id'])
            || !\is_string($decoded['event_id'])
            || !\is_int($decoded['original_events_bytes'])
            || $decoded['original_events_bytes'] < 0
            || !\is_string($decoded['original_events_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/D', $decoded['original_events_sha256']) !== 1
            || !\is_string($decoded['canonical_line_base64'])
            || !\is_string($decoded['canonical_line_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/D', $decoded['canonical_line_sha256']) !== 1
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        /** @var array{
         *   version: int,
         *   dataset_id: string,
         *   event_id: string,
         *   original_events_bytes: int,
         *   original_events_sha256: string,
         *   canonical_line_base64: string,
         *   canonical_line_sha256: string
         * } $decoded
         */
        if (!hash_equals($this->encodeAppendIntent($decoded), $contents)) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        $line = base64_decode($decoded['canonical_line_base64'], true);
        if ($line === false
            || $line === ''
            || strlen($line) > PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES
            || !str_ends_with($line, "\n")
            || str_contains(substr($line, 0, -1), "\n")
            || !hash_equals($decoded['canonical_line_sha256'], hash('sha256', $line))
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }
        try {
            $eventData = json_decode(
                substr($line, 0, -1),
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
            if (!\is_array($eventData) || array_is_list($eventData)) {
                throw new \InvalidArgumentException();
            }
            /** @var array<string, mixed> $eventData */
            $event = PaperMarketEvent::fromArray($eventData);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid', 0, $failure);
        }
        if (!hash_equals($decoded['event_id'], $event->eventId)
            || !hash_equals(CanonicalJson::encode($event->toArray()) . "\n", $line)
        ) {
            throw new \RuntimeException('paper_dataset_append_intent_invalid');
        }

        return $decoded + ['canonical_line' => $line];
    }

    private function terminalManifestMatchesDurableFacts(PaperDatasetManifest $manifest): bool
    {
        return $manifest->state !== PaperDatasetState::RECORDING
            && $manifest->eventsFileSha256 !== null
            && $this->scannedPrefixSha256 !== null
            && hash_equals($manifest->eventsFileSha256, $this->scannedPrefixSha256)
            && $manifest->startExchangeTimestamp == $this->startExchangeTimestamp
            && $manifest->endExchangeTimestamp == $this->latestExchangeTimestamp
            && $manifest->channels === $this->channels
            && $manifest->eventCount === $this->eventCount
            && $manifest->sequenceGaps === $this->sequenceGaps
            && $manifest->lastEventId === $this->lastEventId;
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

            while (($line = $this->lineReader->read(
                $handle,
                'paper_dataset_events_read_failed',
                'paper_dataset_event_line_truncated',
            )) !== false) {
                hash_update($parsedDigest['context'], $line);
                if (trim($line) === '') {
                    continue;
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

    private function publishTerminalManifestUnderEventsLock(
        PaperDatasetState $state,
        PaperMarketDataQuality $quality,
    ): PaperDatasetManifest
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

                $terminal = $this->currentManifest->finalized(
                    state: $state,
                    endExchangeTimestamp: $this->latestExchangeTimestamp,
                    quality: $quality,
                    eventsFileSha256: $result['checksum'],
                );
                $this->writeFinalManifestAtomically(
                    $terminal,
                    function () use ($handle, $terminal, $result): void {
                        $this->assertParsedSnapshotContinuity($handle, $this->scannedBytes);
                        $rehash = $this->checksumPrefix(
                            $handle,
                            $this->scannedBytes,
                            'paper_dataset_events_snapshot_validation',
                        );
                        if (!hash_equals($result['checksum'], $rehash)
                            || !$this->terminalManifestMatchesDurableFacts($terminal)
                        ) {
                            $this->usable = false;

                            throw new \RuntimeException('paper_dataset_events_snapshot_changed');
                        }
                        $this->assertParsedSnapshotContinuity($handle, $this->scannedBytes);
                        $this->assertPinnedDirectories();
                        $this->assertHandleMatchesPath(
                            $handle,
                            $this->eventsPath,
                            'paper_dataset_events_snapshot_validation',
                        );
                    },
                );

                return $terminal;
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

    /** @param (callable(): void)|null $postPublicationValidation */
    private function writeManifestAtomically(
        PaperDatasetManifest $manifest,
        ?callable $postPublicationValidation = null,
    ): void
    {
        $this->assertPinnedDirectories();
        $encoded = $this->codec->encode($manifest);
        if (strlen($encoded) > PaperDatasetFormatLimits::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('paper_dataset_manifest_write_failed');
        }
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
        if ($previousContents === $encoded) {
            return;
        }

        $transition = $this->encodeManifestTransition($previousContents, $encoded);
        if (strlen($transition) > self::MAX_MANIFEST_TRANSITION_BYTES) {
            throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
        }
        $this->createFixedMarker(
            $this->manifestTransitionPath,
            $this->manifestTransitionStagingPath,
            $transition,
            'paper_dataset_manifest_transition_flush_failed',
            'paper_dataset_manifest_transition_directory_sync_failed',
            'paper_dataset_manifest_transition_publish',
            'paper_dataset_manifest_transition_invalid',
        );

        $candidateHandle = null;
        $backupHandle = null;
        try {
            if ($previousContents !== null) {
                $this->createFixedMarker(
                    $this->manifestBackupPath,
                    $this->manifestBackupStagingPath,
                    $previousContents,
                    'paper_dataset_manifest_backup_failed',
                    'paper_dataset_manifest_backup_directory_sync',
                    'paper_dataset_manifest_backup_publish',
                    'paper_dataset_manifest_transition_invalid',
                );
            }
            $this->createFixedMarker(
                $this->manifestCandidatePath,
                $this->manifestCandidateStagingPath,
                $encoded,
                'paper_dataset_manifest_flush_failed',
                'paper_dataset_manifest_candidate_directory_sync',
                'paper_dataset_manifest_candidate_publish',
                'paper_dataset_manifest_transition_invalid',
            );
            $candidateHandle = $this->openRegularFile(
                $this->manifestCandidatePath,
                'r+b',
                'paper_dataset_manifest_open_failed',
            );
            $candidateIdentity = $this->assertManifestContentSnapshot(
                $candidateHandle,
                $this->manifestCandidatePath,
                $encodedBytes,
                $encodedChecksum,
            );
            if ($previousContents !== null) {
                $backupHandle = $this->openRegularFile(
                    $this->manifestBackupPath,
                    'rb',
                    'paper_dataset_manifest_backup_failed',
                );
                $this->assertManifestContentSnapshot(
                    $backupHandle,
                    $this->manifestBackupPath,
                    strlen($previousContents),
                    hash('sha256', $previousContents),
                );
            }
            $this->assertPinnedDirectories();
            $this->assertPathUnchanged($this->manifestPath, $destinationStat);
            $this->assertManifestContentSnapshot(
                $candidateHandle,
                $this->manifestCandidatePath,
                $encodedBytes,
                $encodedChecksum,
                $candidateIdentity,
            );
            try {
                if (!$this->filesystem->move(
                    $this->manifestCandidatePath,
                    $this->manifestPath,
                    'paper_dataset_manifest_publish',
                )) {
                    throw new \RuntimeException('paper_dataset_manifest_rename_failed');
                }
                $this->assertPinnedDirectories();
                $this->assertManifestContentSnapshot(
                    $candidateHandle,
                    $this->manifestPath,
                    $encodedBytes,
                    $encodedChecksum,
                    $candidateIdentity,
                );
                if ($backupHandle !== null && $previousContents !== null) {
                    $this->assertManifestContentSnapshot(
                        $backupHandle,
                        $this->manifestBackupPath,
                        strlen($previousContents),
                        hash('sha256', $previousContents),
                    );
                }
                $this->syncDatasetDirectory();
                $this->assertPinnedDirectories();
                if ($postPublicationValidation !== null) {
                    $postPublicationValidation();
                }
            } catch (\Throwable $failure) {
                $this->usable = false;
                $this->validateAmbiguousManifestEvidence(
                    $candidateHandle,
                    $candidateIdentity,
                    $encoded,
                    $backupHandle,
                    $previousContents,
                    $failure,
                );

                throw $failure;
            }

            if ($previousContents !== null) {
                $this->removeFixedFile(
                    $this->manifestBackupPath,
                    'paper_dataset_manifest_backup_cleanup_failed',
                    'paper_dataset_manifest_backup_cleanup_directory_sync',
                );
            }
            $this->assertManifestPublicationArtifactsCleared();
            $this->removeFixedFile(
                $this->manifestTransitionPath,
                'paper_dataset_manifest_transition_cleanup_failed',
                'paper_dataset_manifest_transition_directory_sync_failed',
            );
        } finally {
            if (\is_resource($candidateHandle)) {
                fclose($candidateHandle);
            }
            if (\is_resource($backupHandle)) {
                fclose($backupHandle);
            }
        }
    }

    /**
     * @param resource                     $candidateHandle
     * @param array<string, mixed>          $candidateIdentity
     * @param resource|null                $backupHandle
     */
    private function validateAmbiguousManifestEvidence(
        $candidateHandle,
        array $candidateIdentity,
        #[\SensitiveParameter] string $candidateContents,
        #[\SensitiveParameter] $backupHandle,
        #[\SensitiveParameter] ?string $backupContents,
        \Throwable $publicationFailure,
    ): void {
        $directoryChanged = $this->isDirectoryChangedFailure($publicationFailure);
        $candidateFailure = null;
        $backupFailure = null;
        try {
            $this->assertManifestHandleSnapshot(
                $candidateHandle,
                strlen($candidateContents),
                hash('sha256', $candidateContents),
                $candidateIdentity,
            );
            if (!$directoryChanged) {
                $this->assertManifestCandidatePath(
                    $candidateHandle,
                    strlen($candidateContents),
                    hash('sha256', $candidateContents),
                    $candidateIdentity,
                );
            }
        } catch (\Throwable $failure) {
            $candidateFailure = $failure;
        }
        if ($backupHandle !== null && $backupContents !== null) {
            try {
                $this->assertManifestHandleSnapshot(
                    $backupHandle,
                    strlen($backupContents),
                    hash('sha256', $backupContents),
                );
                if (!$directoryChanged) {
                    $this->assertManifestContentSnapshot(
                        $backupHandle,
                        $this->manifestBackupPath,
                        strlen($backupContents),
                        hash('sha256', $backupContents),
                    );
                }
            } catch (\Throwable $failure) {
                $backupFailure = $failure;
            }
        }
        if (!$directoryChanged && $candidateFailure !== null) {
            throw new \RuntimeException(
                'paper_dataset_manifest_candidate_changed',
                0,
                $candidateFailure,
            );
        }
        if (!$directoryChanged && $backupFailure !== null) {
            throw new \RuntimeException(
                'paper_dataset_manifest_backup_changed',
                0,
                $backupFailure,
            );
        }
    }

    /**
     * @param resource                     $handle
     * @param array<string, mixed>          $expectedIdentity
     */
    private function assertManifestCandidatePath(
        $handle,
        int $expectedBytes,
        string $expectedChecksum,
        array $expectedIdentity,
    ): void {
        $candidatePath = null;
        foreach ([$this->manifestCandidatePath, $this->manifestPath] as $path) {
            $statistics = $this->pathStatIfPresent($path);
            if ($statistics === null || !$this->sameFile($expectedIdentity, $statistics)) {
                continue;
            }
            if ($candidatePath !== null) {
                throw new \RuntimeException('paper_dataset_file_changed');
            }
            $candidatePath = $path;
        }
        if ($candidatePath === null) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }
        $this->assertManifestContentSnapshot(
            $handle,
            $candidatePath,
            $expectedBytes,
            $expectedChecksum,
            $expectedIdentity,
        );
    }

    private function recoverPendingManifestTransition(?string $recoveryError = null): void
    {
        try {
            $this->recoverPendingManifestArtifactStaging();
            $transitionPresent = $this->pathStatIfPresent($this->manifestTransitionPath) !== null;
            $candidatePresent = $this->pathStatIfPresent($this->manifestCandidatePath) !== null;
            $backupPresent = $this->pathStatIfPresent($this->manifestBackupPath) !== null;
            if (!$transitionPresent) {
                if ($candidatePresent || $backupPresent) {
                    throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
                }

                return;
            }

            $transition = $this->decodeManifestTransition(
                $this->readBoundedFixedFile(
                    $this->manifestTransitionPath,
                    self::MAX_MANIFEST_TRANSITION_BYTES,
                    'paper_dataset_manifest_transition_read_failed',
                ),
            );
            if ($candidatePresent) {
                $this->assertFixedManifestArtifact(
                    $this->manifestCandidatePath,
                    $transition['new_manifest'],
                );
            }
            if ($backupPresent) {
                if ($transition['old_manifest'] === null) {
                    throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
                }
                $this->assertFixedManifestArtifact(
                    $this->manifestBackupPath,
                    $transition['old_manifest'],
                );
            }

            $storedIdentity = $this->pathStatIfPresent($this->manifestPath);
            $stored = $storedIdentity === null
                ? null
                : $this->readStoredManifestContents();
            $matchesOld = $stored === null
                ? $transition['old_manifest'] === null
                : $transition['old_manifest'] !== null
                    && hash_equals($transition['old_manifest'], $stored);
            $matchesNew = $stored !== null && hash_equals($transition['new_manifest'], $stored);
            $recoverableMissingPublication = $stored === null
                && $transition['old_manifest'] !== null
                && $backupPresent;
            if (!$matchesOld && !$matchesNew && !$recoverableMissingPublication) {
                throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
            }
            if ($matchesOld || $recoverableMissingPublication) {
                $this->rollForwardManifestTransition(
                    $transition['old_manifest'],
                    $transition['new_manifest'],
                    $storedIdentity,
                    $candidatePresent,
                    $backupPresent,
                );
                $candidatePresent = false;
                $backupPresent = $transition['old_manifest'] !== null;
            }

            $newManifest = $this->codec->decode($transition['new_manifest']);
            if ($newManifest->state !== PaperDatasetState::RECORDING) {
                if ($candidatePresent) {
                    throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
                }
                if (!$backupPresent) {
                    if (!$matchesNew || $transition['old_manifest'] === null) {
                        throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
                    }
                    $this->syncDatasetDirectory(
                        'paper_dataset_manifest_backup_cleanup_directory_sync',
                        'paper_dataset_manifest_transition_cleanup_failed',
                    );
                    if ($this->pathStatIfPresent($this->manifestBackupPath) !== null) {
                        throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
                    }
                }
                $this->terminalTransitionAwaitingAuthentication = true;

                return;
            }

            if ($candidatePresent) {
                $this->removeFixedFile(
                    $this->manifestCandidatePath,
                    'paper_dataset_manifest_transition_cleanup_failed',
                    'paper_dataset_manifest_candidate_cleanup_directory_sync',
                );
            }
            if ($backupPresent) {
                $this->removeFixedFile(
                    $this->manifestBackupPath,
                    'paper_dataset_manifest_transition_cleanup_failed',
                    'paper_dataset_manifest_backup_cleanup_directory_sync',
                );
            }
            $this->assertManifestPublicationArtifactsCleared();
            $this->removeFixedFile(
                $this->manifestTransitionPath,
                'paper_dataset_manifest_transition_cleanup_failed',
                'paper_dataset_manifest_transition_directory_sync_failed',
            );
        } catch (\Throwable $failure) {
            $this->usable = false;
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_manifest_transition_invalid'
            ) {
                throw $failure;
            }
            if ($recoveryError !== null) {
                throw new \RuntimeException($recoveryError, 0, $failure);
            }

            throw new \RuntimeException('paper_dataset_manifest_transition_invalid', 0, $failure);
        }
    }

    private function cleanupAuthenticatedTerminalTransition(): void
    {
        if (!$this->terminalTransitionAwaitingAuthentication) {
            return;
        }

        try {
            $this->removeFixedFile(
                $this->manifestBackupPath,
                'paper_dataset_manifest_transition_cleanup_failed',
                'paper_dataset_manifest_backup_cleanup_directory_sync',
            );
            $this->assertManifestPublicationArtifactsCleared();
            $this->removeFixedFile(
                $this->manifestTransitionPath,
                'paper_dataset_manifest_transition_cleanup_failed',
                'paper_dataset_manifest_transition_directory_sync_failed',
            );
            $this->terminalTransitionAwaitingAuthentication = false;
        } catch (\Throwable $failure) {
            $this->usable = false;

            throw new \RuntimeException('paper_dataset_manifest_transition_invalid', 0, $failure);
        }
    }

    /** @param array<string, mixed>|null $storedIdentity */
    private function rollForwardManifestTransition(
        #[\SensitiveParameter] ?string $oldManifest,
        #[\SensitiveParameter] string $newManifest,
        ?array $storedIdentity,
        bool $candidatePresent,
        bool $priorStagePresent,
    ): void {
        if ($oldManifest !== null && !$priorStagePresent) {
            $this->createFixedMarker(
                $this->manifestBackupPath,
                $this->manifestBackupStagingPath,
                $oldManifest,
                'paper_dataset_manifest_backup_failed',
                'paper_dataset_manifest_backup_directory_sync',
                'paper_dataset_manifest_backup_publish',
                'paper_dataset_manifest_transition_invalid',
            );
        }
        if (!$candidatePresent) {
            $this->createFixedMarker(
                $this->manifestCandidatePath,
                $this->manifestCandidateStagingPath,
                $newManifest,
                'paper_dataset_manifest_flush_failed',
                'paper_dataset_manifest_candidate_directory_sync',
                'paper_dataset_manifest_candidate_publish',
                'paper_dataset_manifest_transition_invalid',
            );
        }

        $candidateHandle = $this->openRegularFile(
            $this->manifestCandidatePath,
            'r+b',
            'paper_dataset_manifest_open_failed',
        );
        $backupHandle = null;
        try {
            $candidateIdentity = $this->assertManifestContentSnapshot(
                $candidateHandle,
                $this->manifestCandidatePath,
                strlen($newManifest),
                hash('sha256', $newManifest),
            );
            if ($oldManifest !== null) {
                $backupHandle = $this->openRegularFile(
                    $this->manifestBackupPath,
                    'rb',
                    'paper_dataset_manifest_backup_failed',
                );
                $this->assertManifestContentSnapshot(
                    $backupHandle,
                    $this->manifestBackupPath,
                    strlen($oldManifest),
                    hash('sha256', $oldManifest),
                );
            }
            $this->assertPathUnchanged($this->manifestPath, $storedIdentity);
            try {
                if (!$this->filesystem->move(
                    $this->manifestCandidatePath,
                    $this->manifestPath,
                    'paper_dataset_manifest_publish',
                )) {
                    throw new \RuntimeException('paper_dataset_manifest_rename_failed');
                }
                $this->assertManifestContentSnapshot(
                    $candidateHandle,
                    $this->manifestPath,
                    strlen($newManifest),
                    hash('sha256', $newManifest),
                    $candidateIdentity,
                );
                if ($backupHandle !== null && $oldManifest !== null) {
                    $this->assertManifestContentSnapshot(
                        $backupHandle,
                        $this->manifestBackupPath,
                        strlen($oldManifest),
                        hash('sha256', $oldManifest),
                    );
                }
                $this->syncDatasetDirectory();
            } catch (\Throwable $failure) {
                $this->validateAmbiguousManifestEvidence(
                    $candidateHandle,
                    $candidateIdentity,
                    $newManifest,
                    $backupHandle,
                    $oldManifest,
                    $failure,
                );

                throw $failure;
            }
        } finally {
            fclose($candidateHandle);
            if (\is_resource($backupHandle)) {
                fclose($backupHandle);
            }
        }
    }

    private function assertFixedManifestArtifact(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $expected,
    ): void {
        $actual = $this->readBoundedFixedFile(
            $path,
            PaperDatasetFormatLimits::MAX_MANIFEST_BYTES,
            'paper_dataset_manifest_transition_read_failed',
        );
        if (!hash_equals($expected, $actual)) {
            throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
        }
    }

    private function encodeManifestTransition(
        #[\SensitiveParameter] ?string $oldManifest,
        #[\SensitiveParameter] string $newManifest,
    ): string {
        try {
            return json_encode([
                'version' => 1,
                'dataset_id' => $this->identityManifest->datasetId,
                'old_manifest_base64' => $oldManifest === null ? null : base64_encode($oldManifest),
                'old_manifest_sha256' => $oldManifest === null ? null : hash('sha256', $oldManifest),
                'new_manifest_base64' => base64_encode($newManifest),
                'new_manifest_sha256' => hash('sha256', $newManifest),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } catch (\JsonException $failure) {
            throw new \RuntimeException('paper_dataset_manifest_transition_invalid', 0, $failure);
        }
    }

    /**
     * @return array{old_manifest: string|null, new_manifest: string}
     */
    private function decodeManifestTransition(#[\SensitiveParameter] string $contents): array
    {
        try {
            $decoded = json_decode(
                $contents,
                true,
                32,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (\JsonException $failure) {
            throw new \RuntimeException('paper_dataset_manifest_transition_invalid', 0, $failure);
        }
        $expectedKeys = [
            'version',
            'dataset_id',
            'old_manifest_base64',
            'old_manifest_sha256',
            'new_manifest_base64',
            'new_manifest_sha256',
        ];
        if (!\is_array($decoded) || array_is_list($decoded) || array_keys($decoded) !== $expectedKeys
            || $decoded['version'] !== 1
            || !\is_string($decoded['dataset_id'])
            || !hash_equals($this->identityManifest->datasetId, $decoded['dataset_id'])
            || ($decoded['old_manifest_base64'] !== null && !\is_string($decoded['old_manifest_base64']))
            || ($decoded['old_manifest_sha256'] !== null && !\is_string($decoded['old_manifest_sha256']))
            || (($decoded['old_manifest_base64'] === null) !== ($decoded['old_manifest_sha256'] === null))
            || !\is_string($decoded['new_manifest_base64'])
            || !\is_string($decoded['new_manifest_sha256'])
            || preg_match('/\A[0-9a-f]{64}\z/D', $decoded['new_manifest_sha256']) !== 1
            || ($decoded['old_manifest_sha256'] !== null
                && preg_match('/\A[0-9a-f]{64}\z/D', $decoded['old_manifest_sha256']) !== 1)
        ) {
            throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
        }
        $oldManifest = $decoded['old_manifest_base64'] === null
            ? null
            : base64_decode($decoded['old_manifest_base64'], true);
        $newManifest = base64_decode($decoded['new_manifest_base64'], true);
        if ($newManifest === false
            || $newManifest === ''
            || strlen($newManifest) > PaperDatasetFormatLimits::MAX_MANIFEST_BYTES
            || !hash_equals($decoded['new_manifest_sha256'], hash('sha256', $newManifest))
            || ($oldManifest !== null && (
                $oldManifest === ''
                || strlen($oldManifest) > PaperDatasetFormatLimits::MAX_MANIFEST_BYTES
                || !hash_equals((string) $decoded['old_manifest_sha256'], hash('sha256', $oldManifest))
            ))
            || !hash_equals($this->encodeManifestTransition($oldManifest, $newManifest), $contents)
        ) {
            throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
        }

        try {
            $new = $this->codec->decode($newManifest);
            $this->assertSameDataset($this->identityManifest, $new);
            if (!hash_equals($this->codec->encode($new), $newManifest)) {
                throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
            }
            if ($oldManifest !== null) {
                $old = $this->codec->decode($oldManifest);
                if ($this->identityManifest->state === PaperDatasetState::INCOMPLETE
                    && $new->state === PaperDatasetState::INCOMPLETE
                ) {
                    $this->assertSameDatasetIdentity($this->identityManifest, $old);
                    $this->assertRecordingToIncompleteProvenanceTransition($old, $new);
                } else {
                    $this->assertSameDataset($this->identityManifest, $old);
                }
                if ($old->state !== PaperDatasetState::RECORDING
                    || !hash_equals($this->codec->encode($old), $oldManifest)
                ) {
                    throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
                }
            } elseif ($new->state !== PaperDatasetState::RECORDING) {
                throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
            }
        } catch (\Throwable $failure) {
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_manifest_transition_invalid'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_manifest_transition_invalid', 0, $failure);
        }

        return ['old_manifest' => $oldManifest, 'new_manifest' => $newManifest];
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
        $statistics = $this->assertManifestHandleSnapshot(
            $handle,
            $expectedBytes,
            $expectedChecksum,
            $expectedIdentity,
        );
        $this->assertHandleMatchesPath(
            $handle,
            $path,
            'paper_dataset_manifest_snapshot_validation',
        );

        return $statistics;
    }

    /**
     * @param resource                     $handle
     * @param array<string, mixed>|null $expectedIdentity
     *
     * @return array<string, mixed>
     */
    private function assertManifestHandleSnapshot(
        $handle,
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
        ) {
            throw new \RuntimeException('paper_dataset_file_changed');
        }
        $snapshot = $this->checksumPrefix(
            $handle,
            $expectedBytes,
            'paper_dataset_manifest_snapshot_validation',
        );
        if (!hash_equals($expectedChecksum, $snapshot)) {
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
        return $finalStatistics;
    }

    /** @param callable(): void $postPublicationValidation */
    private function writeFinalManifestAtomically(
        PaperDatasetManifest $manifest,
        callable $postPublicationValidation,
    ): void
    {
        try {
            $this->writeManifestAtomically($manifest, $postPublicationValidation);
        } catch (\Throwable $failure) {
            $this->usable = false;
            if (!$this->failureChainContains($failure, [
                'paper_dataset_manifest_candidate_changed',
                'paper_dataset_manifest_backup_changed',
                'paper_dataset_directory_changed',
            ])) {
                try {
                    $stored = $this->readStoredManifest();
                    $this->assertSameDataset($this->identityManifest, $stored);
                    if ($stored == $manifest) {
                        $this->currentManifest = $stored;
                    }
                } catch (\Throwable) {
                    // Keep the last unambiguous in-memory manifest while remaining poisoned.
                }
            }

            throw $failure;
        }

        $this->currentManifest = $manifest;
    }

    /** @param list<string> $messages */
    private function failureChainContains(\Throwable $failure, array $messages): bool
    {
        do {
            if ($failure instanceof \RuntimeException
                && \in_array($failure->getMessage(), $messages, true)
            ) {
                return true;
            }
            $failure = $failure->getPrevious();
        } while ($failure !== null);

        return false;
    }

    private function syncDatasetDirectory(
        string $operation = 'paper_dataset_manifest_directory_sync_failed',
        ?string $failureMessage = null,
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
                throw new \RuntimeException($failureMessage ?? 'paper_dataset_manifest_directory_sync_failed');
            }
            $this->assertPinnedDirectories();
        } finally {
            fclose($handle);
        }
    }

    private function createFixedMarker(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $stagingPath,
        #[\SensitiveParameter] string $contents,
        string $flushError,
        string $syncError,
        string $publishOperation,
        string $collisionError = 'paper_dataset_append_intent_invalid',
    ): void {
        $this->recoverFixedArtifactStaging(
            $path,
            $stagingPath,
            $collisionError,
            $collisionError,
            $syncError,
        );
        if ($this->pathStatIfPresent($path) !== null) {
            throw new \RuntimeException($collisionError);
        }
        $handle = $this->filesystem->createPrivateFile($stagingPath, $flushError);
        if ($handle === false) {
            throw new \RuntimeException($flushError);
        }
        try {
            $this->writeAll($handle, $contents, $flushError);
            $this->flushHandle($handle, $flushError);
            $identity = $this->assertManifestContentSnapshot(
                $handle,
                $stagingPath,
                strlen($contents),
                hash('sha256', $contents),
            );
            $this->assertPinnedDirectories();
            $this->assertPathUnchanged($path, null);
            try {
                $moved = $this->filesystem->move($stagingPath, $path, $publishOperation);
            } catch (\Throwable $failure) {
                if ($this->isDirectoryChangedFailure($failure)) {
                    throw $failure;
                }

                throw new \RuntimeException($flushError, 0, $failure);
            }
            if (!$moved) {
                throw new \RuntimeException($flushError);
            }
            $this->assertPinnedDirectories();
            $this->assertManifestContentSnapshot(
                $handle,
                $path,
                strlen($contents),
                hash('sha256', $contents),
                $identity,
            );
            try {
                $this->syncDatasetDirectory($syncError, $syncError);
            } catch (\Throwable $failure) {
                if ($this->isDirectoryChangedFailure($failure)) {
                    throw $failure;
                }
                if ($failure instanceof \RuntimeException
                    && $failure->getMessage() === $syncError
                ) {
                    throw $failure;
                }

                throw new \RuntimeException($syncError, 0, $failure);
            }
        } finally {
            fclose($handle);
        }
    }

    private function recoverPendingManifestArtifactStaging(): void
    {
        $artifacts = [
            [$this->manifestTransitionPath, $this->manifestTransitionStagingPath],
            [$this->manifestBackupPath, $this->manifestBackupStagingPath],
            [$this->manifestCandidatePath, $this->manifestCandidateStagingPath],
        ];
        $stagingPathsToRemove = [];
        foreach ($artifacts as [$path, $stagingPath]) {
            $authoritativePresent = $this->pathStatIfPresent($path) !== null;
            $stagingPresent = $this->pathStatIfPresent($stagingPath) !== null;
            if ($authoritativePresent && $stagingPresent) {
                throw new \RuntimeException('paper_dataset_manifest_transition_invalid');
            }
            if ($stagingPresent) {
                $stagingPathsToRemove[] = $stagingPath;
            }
        }
        foreach ($stagingPathsToRemove as $stagingPath) {
            $this->removeFixedFile(
                $stagingPath,
                'paper_dataset_manifest_transition_cleanup_failed',
                'paper_dataset_manifest_transition_directory_sync_failed',
            );
        }
    }

    private function assertManifestPublicationArtifactsCleared(): void
    {
        foreach ([
            $this->manifestCandidatePath,
            $this->manifestCandidateStagingPath,
            $this->manifestBackupPath,
            $this->manifestBackupStagingPath,
        ] as $path) {
            if ($this->pathStatIfPresent($path) !== null) {
                throw new \RuntimeException('paper_dataset_manifest_transition_cleanup_failed');
            }
        }
    }

    private function recoverFixedArtifactStaging(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $stagingPath,
        string $collisionError,
        string $cleanupError,
        string $syncOperation,
    ): void {
        $authoritativePresent = $this->pathStatIfPresent($path) !== null;
        $stagingPresent = $this->pathStatIfPresent($stagingPath) !== null;
        if ($authoritativePresent && $stagingPresent) {
            throw new \RuntimeException($collisionError);
        }
        if ($stagingPresent) {
            $this->removeFixedFile($stagingPath, $cleanupError, $syncOperation);
        }
    }

    private function removeAppendIntent(): void
    {
        $this->removeFixedFile(
            $this->appendIntentPath,
            'paper_dataset_append_intent_cleanup_failed',
            'paper_dataset_append_intent_directory_sync_failed',
        );
    }

    private function removeFixedFile(
        #[\SensitiveParameter] string $path,
        string $cleanupError,
        string $syncOperation,
    ): void {
        $expected = $this->pathStatIfPresent($path);
        if ($expected === null) {
            return;
        }
        $this->assertPathUnchanged($path, $expected);
        if (!@unlink($path)) {
            throw new \RuntimeException($cleanupError);
        }
        $this->syncDatasetDirectory($syncOperation, $cleanupError);
        if ($this->pathStatIfPresent($path) !== null) {
            throw new \RuntimeException($cleanupError);
        }
    }

    private function readBoundedFixedFile(
        #[\SensitiveParameter] string $path,
        int $maximumBytes,
        string $operation,
    ): string {
        $handle = $this->openRegularFile($path, 'rb', $operation);
        try {
            $statistics = $this->filesystem->stat($handle, $operation);
            if ($statistics === false
                || !$this->isPrivateRegularFile($statistics)
                || !isset($statistics['size'])
                || !\is_int($statistics['size'])
                || $statistics['size'] <= 0
                || $statistics['size'] > $maximumBytes
            ) {
                throw new \RuntimeException($operation);
            }
            $contents = $this->readExactBytes($handle, $statistics['size'], $operation);
            $position = ftell($handle);
            if ($position === false
                || $position !== $statistics['size']
                || !$this->filesystem->seek($handle, 0, SEEK_SET, $operation)
            ) {
                throw new \RuntimeException($operation);
            }
            $rehash = $this->checksumPrefix($handle, $statistics['size'], $operation);
            $final = $this->filesystem->stat($handle, $operation);
            if (!hash_equals(hash('sha256', $contents), $rehash)
                || $final === false
                || !$this->isPrivateRegularFile($final)
                || !isset($final['size'])
                || !\is_int($final['size'])
                || $final['size'] !== $statistics['size']
                || !$this->sameFile($statistics, $final)
            ) {
                throw new \RuntimeException($operation);
            }
            $this->assertHandleMatchesPath($handle, $path, $operation);

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function readExactBytes($handle, int $bytes, string $operation): string
    {
        $remaining = $bytes;
        $contents = '';
        while ($remaining > 0) {
            $chunk = $this->filesystem->read($handle, min(8192, $remaining), $operation);
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException($operation);
            }
            $contents .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $contents;
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

    private function cohortHasNoArtifacts(): bool
    {
        foreach ([
            $this->eventsPath,
            $this->manifestPath,
            $this->lockPath,
            $this->appendIntentPath,
            $this->appendIntentStagingPath,
            $this->manifestTransitionPath,
            $this->manifestTransitionStagingPath,
            $this->manifestCandidatePath,
            $this->manifestCandidateStagingPath,
            $this->manifestBackupPath,
            $this->manifestBackupStagingPath,
        ] as $path) {
            if ($this->pathEntryAppearsPresent($path)) {
                $this->pathStat($path, 'paper_dataset_file_validation_failed');

                return false;
            }
        }

        return true;
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
        return $this->readBoundedFixedFile(
            $this->manifestPath,
            PaperDatasetFormatLimits::MAX_MANIFEST_BYTES,
            'paper_dataset_manifest_unreadable',
        );
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
            if ($this->pathEntryAppearsPresent($path) || dirname($path) !== $this->datasetDirectory) {
                throw new \RuntimeException('paper_dataset_file_validation_failed');
            }
            $parentBefore = $this->filesystem->pathStat(
                $this->datasetDirectory,
                'paper_dataset_directory_validation',
            );
            if ($parentBefore === false
                || !$this->isPrivateDirectory($parentBefore)
                || !$this->sameFile($parentBefore, $this->datasetDirectoryIdentity)
            ) {
                throw new \RuntimeException('paper_dataset_file_validation_failed');
            }
            $retry = $this->filesystem->pathStat($path, 'paper_dataset_file_validation_failed');
            if ($retry !== false) {
                throw new \RuntimeException('paper_dataset_file_validation_failed');
            }
            $parentAfter = $this->filesystem->pathStat(
                $this->datasetDirectory,
                'paper_dataset_directory_validation',
            );
            if ($parentAfter === false
                || !$this->isPrivateDirectory($parentAfter)
                || !$this->sameFile($parentBefore, $parentAfter)
                || !$this->sameFile($parentAfter, $this->datasetDirectoryIdentity)
            ) {
                throw new \RuntimeException('paper_dataset_file_validation_failed');
            }

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

    private function pathEntryAppearsPresent(#[\SensitiveParameter] string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
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
        $this->assertSameDatasetIdentity($requested, $stored);
        if ($stored->state !== PaperDatasetState::INCOMPLETE
            && ($requested->quality !== $stored->quality
                || $requested->modelName !== $stored->modelName
                || $requested->modelVersion !== $stored->modelVersion)
        ) {
            throw new \RuntimeException('paper_dataset_manifest_identity_mismatch');
        }
    }

    private function assertSameDatasetIdentity(
        PaperDatasetManifest $requested,
        PaperDatasetManifest $stored,
    ): void {
        if ($requested->schemaVersion !== $stored->schemaVersion
            || $requested->recorderVersion !== $stored->recorderVersion
            || $requested->datasetId !== $stored->datasetId
            || $requested->venue !== $stored->venue
            || $requested->symbols !== $stored->symbols
        ) {
            throw new \RuntimeException('paper_dataset_manifest_identity_mismatch');
        }
    }

    private function assertRecordingToIncompleteProvenanceTransition(
        PaperDatasetManifest $old,
        PaperDatasetManifest $new,
    ): void {
        if ($old->state !== PaperDatasetState::RECORDING
            || $new->state !== PaperDatasetState::INCOMPLETE
            || $new->quality !== PaperMarketDataQuality::INCOMPLETE
            || $new->modelName !== null
            || $new->modelVersion !== null
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

    private function storedManifestOrRecoveryEvidenceExists(
        #[\SensitiveParameter] string $root,
        PaperDatasetManifest $manifest,
    ): bool
    {
        if ($root === '' || str_contains($root, "\0")) {
            throw new \RuntimeException('paper_dataset_root_invalid');
        }
        $this->assertNoSymlinkComponents($root);
        $datasetDirectory = rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $manifest->datasetId;
        $manifestPath = $datasetDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->assertNoSymlinkComponents($manifestPath);
        if (is_file($manifestPath)) {
            return true;
        }

        foreach ([
            $datasetDirectory . DIRECTORY_SEPARATOR . 'events.ndjson',
            $datasetDirectory . DIRECTORY_SEPARATOR . '.dataset.lock',
            $datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-transition.json',
            $datasetDirectory . DIRECTORY_SEPARATOR . '.manifest-backup-fixed',
        ] as $recoveryPath) {
            $this->assertNoSymlinkComponents($recoveryPath);
            if (!is_file($recoveryPath)) {
                return false;
            }
        }

        return true;
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
