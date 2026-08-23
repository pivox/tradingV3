<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalEventCoverage;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalRequestIdentity;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalTimeGrid;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPrudentBookModel;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;

final class PaperDatasetVerifier
{
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const FILE_TYPE_MASK = 0170000;

    private readonly PaperDatasetLineReader $lineReader;
    private readonly PaperDatasetSnapshotLimits $snapshotLimits;

    public function __construct(
        private readonly PaperDatasetManifestCodec $codec = new PaperDatasetManifestCodec(),
        private readonly PaperDatasetRecorderFilesystem $filesystem = new PaperDatasetRecorderFilesystem(),
        ?PaperDatasetSnapshotLimits $snapshotLimits = null,
    ) {
        $this->lineReader = new PaperDatasetLineReader($this->filesystem);
        $this->snapshotLimits = $snapshotLimits ?? new PaperDatasetSnapshotLimits();
    }

    public function verify(
        #[\SensitiveParameter] string $datasetDirectory,
        ?int $eventLimit = null,
    ): PaperDatasetManifest {
        return $this->verifySnapshot($datasetDirectory, $eventLimit, false)['manifest'];
    }

    public function verifyForBaseline(
        #[\SensitiveParameter] string $datasetDirectory,
        ?int $eventLimit = null,
    ): PaperDatasetManifest {
        $verified = $this->verifySnapshot($datasetDirectory, $eventLimit, false);
        $this->assertBaselineManifest($verified['manifest']);

        return $verified['manifest'];
    }

    public function verifyBaselineSnapshot(
        #[\SensitiveParameter] string $datasetDirectory,
    ): VerifiedPaperDatasetSnapshot {
        $verified = $this->verifySnapshot($datasetDirectory, null, true);
        $this->assertBaselineManifest($verified['manifest']);
        if ($verified['events'] === null) {
            throw new \LogicException('paper_dataset_snapshot_events_unavailable');
        }

        return new VerifiedPaperDatasetSnapshot($verified['manifest'], $verified['events']);
    }

    /** @return array{manifest: PaperDatasetManifest, events: list<PaperMarketEvent>|null} */
    private function verifySnapshot(
        #[\SensitiveParameter] string $datasetDirectory,
        ?int $eventLimit,
        bool $collectEvents,
    ): array {
        $this->assertNoSymlinkComponents($datasetDirectory);
        $unresolvedRoot = dirname($datasetDirectory);
        $rootPin = $this->openPinnedDirectory($unresolvedRoot, 'paper_dataset_directory_invalid');
        $datasetPin = null;
        try {
            $datasetPin = $this->openPinnedDirectory($datasetDirectory, 'paper_dataset_directory_invalid');
            if (!is_dir($datasetDirectory) || !is_readable($datasetDirectory)) {
                throw new \RuntimeException('paper_dataset_directory_invalid');
            }
            $resolvedDatasetDirectory = realpath($datasetDirectory);
            if ($resolvedDatasetDirectory === false) {
                throw new \RuntimeException('paper_dataset_directory_invalid');
            }
            $datasetDirectory = $resolvedDatasetDirectory;
            $datasetRoot = dirname($datasetDirectory);
            $rootIdentity = $rootPin['identity'];
            $datasetIdentity = $datasetPin['identity'];
            $assertDirectories = function () use (
                $rootPin,
                $datasetPin,
                $datasetRoot,
                $datasetDirectory,
                $rootIdentity,
                $datasetIdentity,
            ): void {
                $this->assertDirectoryHandleMatchesPath(
                    $rootPin['handle'],
                    $datasetRoot,
                    $rootIdentity,
                );
                $this->assertDirectoryHandleMatchesPath(
                    $datasetPin['handle'],
                    $datasetDirectory,
                    $datasetIdentity,
                );
            };
            $assertDirectories();

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
            $manifestSnapshot = $this->readRegularFile(
                $manifestPath,
                'paper_dataset_manifest_unreadable',
                'paper_dataset_verifier_manifest_validation',
                PaperDatasetFormatLimits::MAX_MANIFEST_BYTES,
            );
            $assertDirectories();

            $manifest = $this->codec->decode($manifestSnapshot['contents']);
            if ($manifest->state !== PaperDatasetState::COMPLETE) {
                throw new \RuntimeException('paper_dataset_not_complete');
            }
            $this->assertHyperliquidHistoricalCoverageIdentity($manifest, false);
            if ($collectEvents && $manifest->eventCount > $this->snapshotLimits->maximumEvents) {
                throw new \RuntimeException('paper_dataset_snapshot_limit_exceeded');
            }

            $facts = $this->scan($eventsPath, $manifest, $eventLimit, $collectEvents);
            $assertDirectories();
            if ($manifest->eventsFileSha256 === null
                || !hash_equals($manifest->eventsFileSha256, $facts['events_checksum'])
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

            $assertDirectories();
            $this->assertRegularFileSnapshot(
                $manifestPath,
                $manifestSnapshot['bytes'],
                $manifestSnapshot['checksum'],
                $manifestSnapshot['identity'],
            );
            $assertDirectories();
            $this->assertRegularFileSnapshot(
                $eventsPath,
                $facts['events_bytes'],
                $facts['events_checksum'],
                $facts['events_identity'],
                'paper_dataset_verifier_events_final_rehash',
            );
            $assertDirectories();

            return ['manifest' => $manifest, 'events' => $facts['events']];
        } finally {
            if ($datasetPin !== null) {
                fclose($datasetPin['handle']);
            }
            fclose($rootPin['handle']);
        }
    }

    private function assertBaselineManifest(PaperDatasetManifest $manifest): void
    {
        if (!$manifest->hasCertifiableNetworkProvenance()) {
            throw new \RuntimeException('paper_dataset_network_provenance_uncertifiable');
        }
        if ($manifest->quality === PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK
            && ($manifest->modelName !== 'hl_candle_atr_top_v1' || $manifest->modelVersion !== '1.0.0')
        ) {
            throw new \RuntimeException('paper_dataset_hyperliquid_model_invalid');
        }
        $this->assertHyperliquidHistoricalCoverageIdentity($manifest, true);
    }

    private function assertHyperliquidHistoricalCoverageIdentity(
        PaperDatasetManifest $manifest,
        bool $required,
    ): void {
        if ($manifest->quality
            !== PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK
        ) {
            return;
        }
        $coverage = $manifest->historicalCoverage;
        if ($coverage === null) {
            if ($required) {
                throw new \RuntimeException('paper_dataset_hyperliquid_coverage_invalid');
            }

            return;
        }
        $expectedRequestSha256 = HyperliquidHistoricalRequestIdentity::sha256(
            $manifest->datasetId,
            $manifest->network,
            array_keys($manifest->symbols),
            $coverage->intervals,
            $coverage->from,
            $coverage->to,
            $coverage->maximumEvents,
            $coverage->maximumPages,
            $coverage->maximumResponseBytes,
            $coverage->maximumRetries,
        );
        if (!hash_equals($expectedRequestSha256, $coverage->requestSha256)) {
            throw new \RuntimeException('paper_dataset_hyperliquid_coverage_invalid');
        }
    }

    private function assertNoSymlinkComponents(#[\SensitiveParameter] string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw new \RuntimeException('paper_dataset_directory_invalid');
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

    /**
     * @return array{
     *   contents: string,
     *   bytes: int,
     *   checksum: string,
     *   identity: array{dev: int, ino: int}
     * }
     */
    private function readRegularFile(
        #[\SensitiveParameter] string $path,
        string $readError,
        string $validationOperation,
        int $maximumBytes,
    ): array {
        $handle = $this->openRegularFile($path, 'rb', $readError, $validationOperation);
        try {
            $statistics = $this->filesystem->stat($handle, $validationOperation);
            if ($statistics === false
                || !isset($statistics['size'])
                || !\is_int($statistics['size'])
                || $statistics['size'] <= 0
                || $statistics['size'] > $maximumBytes
            ) {
                throw new \RuntimeException($readError);
            }
            $contents = stream_get_contents($handle, $statistics['size']);
            $position = ftell($handle);
            if ($contents === false
                || strlen($contents) !== $statistics['size']
                || $position === false
                || $position !== $statistics['size']
                || !isset($statistics['dev'], $statistics['ino'])
                || !\is_int($statistics['dev'])
                || !\is_int($statistics['ino'])
            ) {
                throw new \RuntimeException($readError);
            }
            $this->assertHandleMatchesPath($handle, $path, $validationOperation);

            return [
                'contents' => $contents,
                'bytes' => $statistics['size'],
                'checksum' => hash('sha256', $contents),
                'identity' => ['dev' => $statistics['dev'], 'ino' => $statistics['ino']],
            ];
        } finally {
            fclose($handle);
        }
    }

    /** @param array{dev: int, ino: int} $expectedIdentity */
    private function assertRegularFileSnapshot(
        #[\SensitiveParameter] string $path,
        int $expectedBytes,
        string $expectedChecksum,
        array $expectedIdentity,
        string $rehashOperation = 'paper_dataset_verifier_manifest_rehash',
    ): void {
        try {
            $handle = $this->openRegularFile(
                $path,
                'rb',
                'paper_dataset_verifier_snapshot_changed',
                $rehashOperation,
            );
            try {
                $statistics = $this->filesystem->stat($handle, $rehashOperation);
                if ($statistics === false
                    || !$this->isPrivateRegularFile($statistics)
                    || !isset($statistics['size'])
                    || !\is_int($statistics['size'])
                    || $statistics['size'] !== $expectedBytes
                    || !$this->sameFile($expectedIdentity, $statistics)
                    || !$this->filesystem->seek(
                        $handle,
                        0,
                        SEEK_SET,
                        $rehashOperation,
                    )
                ) {
                    throw new \RuntimeException('paper_dataset_verifier_snapshot_changed');
                }
                $rehash = $this->filesystem->checksum($handle, $rehashOperation);
                if ($rehash['bytes'] !== $expectedBytes
                    || !hash_equals($expectedChecksum, $rehash['checksum'])
                ) {
                    throw new \RuntimeException('paper_dataset_verifier_snapshot_changed');
                }
                $finalStatistics = $this->filesystem->stat(
                    $handle,
                    $rehashOperation,
                );
                if ($finalStatistics === false
                    || !$this->isPrivateRegularFile($finalStatistics)
                    || !isset($finalStatistics['size'])
                    || !\is_int($finalStatistics['size'])
                    || $finalStatistics['size'] !== $expectedBytes
                    || !$this->sameFile($statistics, $finalStatistics)
                ) {
                    throw new \RuntimeException('paper_dataset_verifier_snapshot_changed');
                }
                $this->assertHandleMatchesPath(
                    $handle,
                    $path,
                    $rehashOperation,
                );
            } finally {
                fclose($handle);
            }
        } catch (\Throwable $failure) {
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_dataset_verifier_snapshot_changed'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_dataset_verifier_snapshot_changed', 0, $failure);
        }
    }

    /** @return resource */
    private function openRegularFile(
        #[\SensitiveParameter] string $path,
        string $mode,
        string $openError,
        string $validationOperation,
    )
    {
        $before = $this->pathStat($path, $openError);
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException($openError);
        }

        try {
            $opened = $this->assertHandleMatchesPath($handle, $path, $validationOperation);
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
        string $validationOperation,
    ): array
    {
        $opened = $this->filesystem->stat($handle, $validationOperation);
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
        if (!$this->isRegularFile($statistics)) {
            throw new \RuntimeException('paper_dataset_file_unreadable');
        }
        if (!$this->isPrivateRegularFile($statistics)) {
            throw new \RuntimeException('paper_dataset_file_validation_failed');
        }

        return $statistics;
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

    /** @return array{handle: resource, identity: array{dev: int, ino: int}} */
    private function openPinnedDirectory(#[\SensitiveParameter] string $path, string $error): array
    {
        $this->assertNoSymlinkComponents($path);
        $handle = $this->filesystem->openDirectory($path, 'paper_dataset_directory_validation');
        if ($handle === false) {
            throw new \RuntimeException($error);
        }
        try {
            $statistics = $this->filesystem->stat($handle, 'paper_dataset_directory_validation');
            if ($statistics === false
                || !$this->isPrivateDirectory($statistics)
                || !isset($statistics['dev'], $statistics['ino'])
                || !\is_int($statistics['dev'])
                || !\is_int($statistics['ino'])
            ) {
                throw new \RuntimeException($error);
            }
            $identity = ['dev' => $statistics['dev'], 'ino' => $statistics['ino']];
            $this->assertDirectoryHandleMatchesPath($handle, $path, $identity);

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
        #[\SensitiveParameter] string $path,
        array $expected,
    ): void {
        $opened = $this->filesystem->stat($handle, 'paper_dataset_directory_validation');
        if ($opened === false || !$this->isPrivateDirectory($opened)) {
            throw new \RuntimeException('paper_dataset_directory_changed');
        }
        $current = $this->pinDirectoryIdentity($path, 'paper_dataset_directory_changed');
        if (!$this->sameFile($expected, $opened) || !$this->sameFile($expected, $current)) {
            throw new \RuntimeException('paper_dataset_directory_changed');
        }
    }

    /** @return array{dev: int, ino: int} */
    private function pinDirectoryIdentity(#[\SensitiveParameter] string $path, string $error): array
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

    /**
     * @return array{
     *   event_count: int,
     *   last_event_id: string|null,
     *   start_exchange_timestamp: \DateTimeImmutable|null,
     *   end_exchange_timestamp: \DateTimeImmutable|null,
     *   channels: list<string>,
     *   sequence_gaps: array<string, int>,
     *   events: list<PaperMarketEvent>|null,
     *   events_checksum: string,
     *   events_bytes: int,
     *   events_identity: array{dev: int, ino: int}
     * }
     */
    private function scan(
        #[\SensitiveParameter] string $eventsPath,
        #[\SensitiveParameter] PaperDatasetManifest $manifest,
        ?int $eventLimit,
        bool $collectEvents,
    ): array {
        /** @var array<string, true> $identities */
        $identities = [];
        /** @var array<string, BigInteger> $lastSequences */
        $lastSequences = [];
        /** @var array<string, int> $sequenceGaps */
        $sequenceGaps = [];
        /** @var list<string> $channels */
        $channels = [];
        /** @var list<PaperMarketEvent>|null $events */
        $events = $collectEvents ? [] : null;
        $snapshotNodes = 0;
        $snapshotKeys = 0;
        $snapshotBytes = 0;
        $count = 0;
        $lastEventId = null;
        $start = null;
        $end = null;
        $checksumContext = hash_init('sha256');
        /** @var array<string, array<string, array<int, int>>> $historicalCandles */
        $historicalCandles = [];
        /** @var array<string, array<string, array<int, PaperMarketEvent>>> $historicalCandleEvents */
        $historicalCandleEvents = [];
        /** @var list<array{symbol: string, coverage: HyperliquidHistoricalEventCoverage, event: PaperMarketEvent}> $historicalBooks */
        $historicalBooks = [];
        $liveOrdinals = $this->isHyperliquidLive($manifest)
            ? new HyperliquidPaperSourceOrdinal()
            : null;
        /** @var array<string, int> $liveSnapshotEpochs */
        $liveSnapshotEpochs = [];
        /** @var array<string, int> $liveMetadataEpochs */
        $liveMetadataEpochs = [];
        /** @var array<string, int> $liveCandleFrontiers */
        $liveCandleFrontiers = [];
        /** @var list<string> $liveEventIds */
        $liveEventIds = [];
        /** @var list<array{identity_hash: string, assignment_digest: string}> $liveTradeIdentityHistory */
        $liveTradeIdentityHistory = [];
        /** @var array<string, int> $okxMetadataEpochs */
        $okxMetadataEpochs = [];
        /** @var array<string, array{source_epoch: int, observed_at_ms: string}> $okxFundingEpochs */
        $okxFundingEpochs = [];
        /** @var array<string, int> $okxInitialSnapshotEpochs */
        $okxInitialSnapshotEpochs = [];

        $handle = $this->openRegularFile(
            $eventsPath,
            'rb',
            'paper_dataset_file_unreadable',
            'paper_dataset_verifier_events_validation',
        );
        try {
            $statistics = $this->filesystem->stat($handle, 'paper_dataset_verifier_events_validation');
            if ($statistics === false
                || !isset($statistics['size'], $statistics['dev'], $statistics['ino'])
                || !\is_int($statistics['size'])
                || !\is_int($statistics['dev'])
                || !\is_int($statistics['ino'])
            ) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
            if ($collectEvents && $statistics['size'] > $this->snapshotLimits->maximumBytes) {
                throw new \RuntimeException('paper_dataset_snapshot_limit_exceeded');
            }
            while (($line = $this->lineReader->read(
                $handle,
                'paper_dataset_verifier_events_read_failed',
                'paper_dataset_event_invalid',
            )) !== false) {
                if ($collectEvents) {
                    $lineBytes = \strlen($line);
                    if ($lineBytes > $this->snapshotLimits->maximumBytes
                        || $snapshotBytes > $this->snapshotLimits->maximumBytes - $lineBytes
                    ) {
                        throw new \RuntimeException('paper_dataset_snapshot_limit_exceeded');
                    }
                    $snapshotBytes += $lineBytes;
                }
                hash_update($checksumContext, $line);
                if (trim($line) === '') {
                    if ($collectEvents) {
                        throw new \RuntimeException('paper_dataset_snapshot_blank_line_invalid');
                    }
                    continue;
                }
                if ($eventLimit !== null && $count >= $eventLimit) {
                    throw new \RuntimeException('paper_dataset_event_limit_exceeded');
                }
                if ($collectEvents && $count >= $this->snapshotLimits->maximumEvents) {
                    throw new \RuntimeException('paper_dataset_snapshot_limit_exceeded');
                }

                $raw = substr($line, 0, -1);
                $event = $this->decodeEvent($raw);
                if (CanonicalJson::encode($event->toArray()) !== $raw) {
                    throw new \RuntimeException('paper_dataset_event_not_canonical');
                }
                if ($event->sourceVenue !== $manifest->venue) {
                    throw new \RuntimeException('paper_dataset_event_venue_mismatch');
                }
                if ($event->sourceNetwork !== $manifest->network) {
                    throw new \RuntimeException('paper_dataset_event_network_mismatch');
                }
                if (!array_key_exists($event->symbol, $manifest->symbols)) {
                    throw new \RuntimeException('paper_dataset_event_symbol_mismatch');
                }
                if ($collectEvents) {
                    $complexity = $event->canonicalComplexity();
                    if ($complexity['nodes'] > $this->snapshotLimits->maximumNodes
                        || $snapshotNodes > $this->snapshotLimits->maximumNodes - $complexity['nodes']
                        || $complexity['keys'] > $this->snapshotLimits->maximumKeys
                        || $snapshotKeys > $this->snapshotLimits->maximumKeys - $complexity['keys']
                    ) {
                        throw new \RuntimeException('paper_dataset_snapshot_limit_exceeded');
                    }
                    $snapshotNodes += $complexity['nodes'];
                    $snapshotKeys += $complexity['keys'];
                }
                $historicalCoverage = $this->assertHyperliquidHistoricalEvent($event, $manifest);
                if ($liveOrdinals instanceof HyperliquidPaperSourceOrdinal) {
                    $this->assertHyperliquidLiveEvent(
                        $event,
                        $liveOrdinals,
                        $liveSnapshotEpochs,
                        $liveMetadataEpochs,
                        $liveCandleFrontiers,
                        $liveTradeIdentityHistory,
                    );
                    $liveEventIds[] = $event->eventId;
                }
                if ($this->isOkxLive($manifest)) {
                    if ($event->channel === PaperMarketDataChannel::INSTRUMENT_METADATA) {
                        $this->assertOkxInstrumentMetadata(
                            $event,
                            $okxInitialSnapshotEpochs,
                            $okxMetadataEpochs,
                        );
                    } elseif ($event->channel === PaperMarketDataChannel::FUNDING_RATE) {
                        $this->assertOkxFundingRate($event, $okxFundingEpochs);
                    } elseif ($event->channel === PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                        && ($event->payload['reason'] ?? null) === 'initial'
                    ) {
                        $epoch = $this->livePositiveInt($event->payload['source_epoch'] ?? null);
                        if ($okxMetadataEpochs !== []
                            && ($okxMetadataEpochs[$event->symbol] ?? null) !== $epoch
                        ) {
                            throw new \RuntimeException('paper_dataset_okx_instrument_metadata_invalid');
                        }
                        $okxInitialSnapshotEpochs[$event->symbol] = $epoch;
                    }
                }
                if ($historicalCoverage !== null) {
                    if ($historicalCoverage->modelledBook) {
                        $historicalBooks[] = [
                            'symbol' => $event->symbol,
                            'coverage' => $historicalCoverage,
                            'event' => $event,
                        ];
                    } else {
                        $starts = &$historicalCandles[$event->symbol][$historicalCoverage->interval];
                        if (isset($starts[$historicalCoverage->startMilliseconds])) {
                            throw new \RuntimeException(
                                'paper_dataset_hyperliquid_coverage_incomplete',
                            );
                        }
                        $starts[$historicalCoverage->startMilliseconds]
                            = $historicalCoverage->closeMilliseconds;
                        unset($starts);
                        $historicalCandleEvents[$event->symbol][$historicalCoverage->interval][
                            $historicalCoverage->startMilliseconds
                        ] = $event;
                    }
                }
                if (isset($identities[$event->eventId])) {
                    throw new \RuntimeException('paper_dataset_duplicate_identity');
                }
                $identities[$event->eventId] = true;

                $sequenceParts = [$event->sourceVenue->value, $event->symbol, $event->channel->value];
                if ($event->schemaVersion === PaperMarketEvent::SCHEMA_VERSION) {
                    array_unshift($sequenceParts, $event->sourceNetwork->value);
                }
                $sequenceKey = implode('/', $sequenceParts);
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
                if ($events !== null) {
                    $events[] = $event;
                }
                $lastEventId = $event->eventId;
                $channels[] = $event->channel->value;
                $start = $start === null || $event->exchangeTimestamp < $start ? $event->exchangeTimestamp : $start;
                $end = $end === null || $event->exchangeTimestamp > $end ? $event->exchangeTimestamp : $end;
            }
            if (!feof($handle)) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
            $position = ftell($handle);
            if ($position === false || $position !== $statistics['size']) {
                throw new \RuntimeException('paper_dataset_events_read_failed');
            }
            $parsedChecksum = hash_final($checksumContext);
            if (!$this->filesystem->seek($handle, 0, SEEK_SET, 'paper_dataset_verifier_events_rehash')) {
                throw new \RuntimeException('paper_dataset_verifier_snapshot_changed');
            }
            $rehash = $this->filesystem->checksum($handle, 'paper_dataset_verifier_events_rehash');
            if ($rehash['bytes'] !== $statistics['size']
                || !hash_equals($parsedChecksum, $rehash['checksum'])
            ) {
                throw new \RuntimeException('paper_dataset_verifier_snapshot_changed');
            }
            $finalStatistics = $this->filesystem->stat($handle, 'paper_dataset_verifier_events_validation');
            if ($finalStatistics === false
                || !$this->isPrivateRegularFile($finalStatistics)
                || !isset($finalStatistics['size'])
                || !\is_int($finalStatistics['size'])
                || $finalStatistics['size'] !== $statistics['size']
                || !$this->sameFile($statistics, $finalStatistics)
            ) {
                throw new \RuntimeException('paper_dataset_verifier_snapshot_changed');
            }
            $this->assertHandleMatchesPath($handle, $eventsPath, 'paper_dataset_verifier_events_validation');
        } finally {
            fclose($handle);
        }

        $channels = array_values(array_unique($channels));
        sort($channels, SORT_STRING);
        ksort($sequenceGaps, SORT_STRING);
        $this->assertHyperliquidHistoricalCoverage(
            $manifest,
            $historicalCandles,
            $historicalCandleEvents,
            $historicalBooks,
        );
        if ($liveOrdinals instanceof HyperliquidPaperSourceOrdinal) {
            $this->assertHyperliquidLiveCheckpoint(
                dirname($eventsPath),
                $manifest,
                $liveOrdinals,
                $liveSnapshotEpochs,
                $liveCandleFrontiers,
                $liveEventIds,
                $liveTradeIdentityHistory,
            );
        }
        if ($okxMetadataEpochs !== []) {
            $metadataSymbols = array_keys($okxMetadataEpochs);
            $manifestSymbols = array_keys($manifest->symbols);
            sort($metadataSymbols, \SORT_STRING);
            sort($manifestSymbols, \SORT_STRING);
            if ($metadataSymbols !== $manifestSymbols) {
                throw new \RuntimeException('paper_dataset_okx_instrument_metadata_invalid');
            }
        }
        if ($okxFundingEpochs !== []) {
            $fundingSymbols = array_keys($okxFundingEpochs);
            $manifestSymbols = array_keys($manifest->symbols);
            sort($fundingSymbols, \SORT_STRING);
            sort($manifestSymbols, \SORT_STRING);
            if ($fundingSymbols !== $manifestSymbols) {
                throw new \RuntimeException('paper_dataset_okx_funding_rate_invalid');
            }
        }

        return [
            'event_count' => $count,
            'last_event_id' => $lastEventId,
            'start_exchange_timestamp' => $start,
            'end_exchange_timestamp' => $end,
            'channels' => $channels,
            'sequence_gaps' => $sequenceGaps,
            'events' => $events,
            'events_checksum' => $parsedChecksum,
            'events_bytes' => $statistics['size'],
            'events_identity' => [
                'dev' => $statistics['dev'],
                'ino' => $statistics['ino'],
            ],
        ];
    }

    private function isHyperliquidLive(PaperDatasetManifest $manifest): bool
    {
        return $manifest->venue === PaperMarketDataVenue::HYPERLIQUID
            && $manifest->quality
                === PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES;
    }

    private function isOkxLive(PaperDatasetManifest $manifest): bool
    {
        return $manifest->venue === PaperMarketDataVenue::OKX
            && $manifest->quality
                === PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES;
    }

    /**
     * @param array<string, int> $snapshotEpochs
     * @param array<string, int> $metadataEpochs
     */
    private function assertOkxInstrumentMetadata(
        PaperMarketEvent $event,
        array $snapshotEpochs,
        array &$metadataEpochs,
    ): void {
        try {
            $payload = $event->payload;
            $metadataSchemaVersion = $payload['metadata_schema_version'] ?? null;
            $expectedKeys = [
                'base_asset', 'contract_multiplier', 'contract_value',
                'contract_value_unit', 'instrument_type', 'maximum_limit_quantity',
                'maximum_market_quantity', 'metadata_schema_version',
                'minimum_quantity', 'native_symbol', 'origin', 'price_tick',
                'quantity_step', 'quantity_unit', 'quote_asset',
                'settlement_asset', 'source_epoch', 'status',
            ];
            if ($metadataSchemaVersion === 'paper-instrument-metadata.v2') {
                $expectedKeys[] = 'maximum_leverage';
            }
            $actualKeys = array_keys($payload);
            sort($actualKeys, \SORT_STRING);
            sort($expectedKeys, \SORT_STRING);
            $contract = match ($event->symbol) {
                'BTCUSDT' => ['native_symbol' => 'BTC-USDT-SWAP', 'base_asset' => 'BTC'],
                'ETHUSDT' => ['native_symbol' => 'ETH-USDT-SWAP', 'base_asset' => 'ETH'],
                default => throw new \InvalidArgumentException(),
            };
            $epoch = $this->livePositiveInt($payload['source_epoch'] ?? null);
            if ($actualKeys !== $expectedKeys
                || !\in_array($metadataSchemaVersion, [
                    'paper-instrument-metadata.v1',
                    'paper-instrument-metadata.v2',
                ], true)
                || ($payload['native_symbol'] ?? null) !== $contract['native_symbol']
                || ($payload['instrument_type'] ?? null) !== 'perpetual'
                || ($payload['base_asset'] ?? null) !== $contract['base_asset']
                || ($payload['quote_asset'] ?? null) !== 'USDT'
                || ($payload['settlement_asset'] ?? null) !== 'USDT'
                || ($payload['status'] ?? null) !== 'live'
                || ($payload['quantity_unit'] ?? null) !== 'contracts'
                || ($payload['contract_value_unit'] ?? null) !== $contract['base_asset']
                || ($payload['origin'] ?? null) !== 'rest_public_instruments'
                || isset($metadataEpochs[$event->symbol])
                || isset($snapshotEpochs[$event->symbol])
                    && $epoch <= $snapshotEpochs[$event->symbol]
            ) {
                throw new \InvalidArgumentException();
            }
            foreach ([
                'quantity_step', 'minimum_quantity', 'maximum_market_quantity',
                'maximum_limit_quantity', 'contract_value', 'contract_multiplier',
                'price_tick',
            ] as $field) {
                $value = $payload[$field] ?? null;
                if (!\is_string($value)
                    || \strlen($value) > 128
                    || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
                    || !BigDecimal::of($value)->isGreaterThan(0)
                    || (string) BigDecimal::of($value)->stripTrailingZeros() !== $value
                ) {
                    throw new \InvalidArgumentException();
                }
            }
            if ($metadataSchemaVersion === 'paper-instrument-metadata.v2') {
                $value = $payload['maximum_leverage'] ?? null;
                if (!\is_string($value)
                    || \strlen($value) > 128
                    || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
                    || !BigDecimal::of($value)->isGreaterThan(0)
                    || (string) BigDecimal::of($value)->stripTrailingZeros() !== $value
                ) {
                    throw new \InvalidArgumentException();
                }
            }
            $metadataEpochs[$event->symbol] = $epoch;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'paper_dataset_okx_instrument_metadata_invalid',
                0,
                $exception,
            );
        }
    }

    /** @param array<string, array{source_epoch: int, observed_at_ms: string}> $fundingEpochs */
    private function assertOkxFundingRate(PaperMarketEvent $event, array &$fundingEpochs): void
    {
        try {
            $payload = $event->payload;
            $expected = [
                'formula_type', 'funding_interval_seconds', 'funding_rate',
                'funding_schema_version', 'funding_time_ms', 'instrument_type',
                'method', 'native_symbol', 'next_funding_time_ms', 'observed_at_ms', 'origin',
                'settlement_state', 'source_epoch',
            ];
            $actual = array_keys($payload);
            sort($actual, \SORT_STRING);
            $native = match ($event->symbol) {
                'BTCUSDT' => 'BTC-USDT-SWAP',
                'ETHUSDT' => 'ETH-USDT-SWAP',
                default => throw new \InvalidArgumentException(),
            };
            $rate = $payload['funding_rate'] ?? null;
            $observed = $payload['observed_at_ms'] ?? null;
            $funding = $payload['funding_time_ms'] ?? null;
            $next = $payload['next_funding_time_ms'] ?? null;
            $interval = $payload['funding_interval_seconds'] ?? null;
            $epoch = $this->livePositiveInt($payload['source_epoch'] ?? null);
            $previous = $fundingEpochs[$event->symbol] ?? null;
            if ($actual !== $expected
                || ($payload['funding_schema_version'] ?? null) !== 'paper-funding-rate.v1'
                || ($payload['native_symbol'] ?? null) !== $native
                || ($payload['instrument_type'] ?? null) !== 'perpetual'
                || !\is_string($rate) || \strlen($rate) > 128
                || preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $rate) !== 1
                || (string) BigDecimal::of($rate)->stripTrailingZeros() !== $rate
                || BigDecimal::of($rate)->isLessThanOrEqualTo(-1)
                || BigDecimal::of($rate)->isGreaterThanOrEqualTo(1)
                || !\is_string($observed) || preg_match('/\A[1-9][0-9]{12}\z/D', $observed) !== 1
                || !\is_string($funding) || preg_match('/\A[1-9][0-9]{12}\z/D', $funding) !== 1
                || !\is_string($next) || preg_match('/\A[1-9][0-9]{12}\z/D', $next) !== 1
                || !\is_int($interval) || $interval < 1
                || !BigDecimal::of($next)->minus($funding)->isEqualTo($interval * 1000)
                || BigDecimal::of($observed)->isGreaterThanOrEqualTo($funding)
                || $this->millisecondsTimestamp($observed) > $event->receivedTimestamp
                || $event->exchangeTimestamp != $event->receivedTimestamp
                || ($payload['method'] ?? null) !== 'current_period'
                || ($payload['formula_type'] ?? null) !== 'withRate'
                || !\in_array($payload['settlement_state'] ?? null, ['processing', 'settled'], true)
                || ($payload['origin'] ?? null) !== 'rest_public_funding_rate'
                || $previous !== null
                    && ($epoch < $previous['source_epoch']
                        || BigDecimal::of($observed)->isLessThanOrEqualTo($previous['observed_at_ms']))
            ) {
                throw new \InvalidArgumentException();
            }
            $fundingEpochs[$event->symbol] = [
                'source_epoch' => $epoch,
                'observed_at_ms' => $observed,
            ];
        } catch (\Throwable $exception) {
            throw new \RuntimeException('paper_dataset_okx_funding_rate_invalid', 0, $exception);
        }
    }

    private function millisecondsTimestamp(string $milliseconds): \DateTimeImmutable
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            'U.u',
            substr($milliseconds, 0, -3) . '.' . substr($milliseconds, -3) . '000',
        );
        if (!$timestamp instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException();
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, int> $snapshotEpochs
     * @param array<string, int> $metadataEpochs
     * @param array<string, int> $candleFrontiers
     * @param list<array{identity_hash: string, assignment_digest: string}> $tradeIdentityHistory
     */
    private function assertHyperliquidLiveEvent(
        PaperMarketEvent $event,
        HyperliquidPaperSourceOrdinal $ordinals,
        array &$snapshotEpochs,
        array &$metadataEpochs,
        array &$candleFrontiers,
        array &$tradeIdentityHistory,
    ): void {
        try {
            if (!\in_array($event->channel, [
                PaperMarketDataChannel::PUBLIC_TRADE,
                PaperMarketDataChannel::TOP_OF_BOOK,
                PaperMarketDataChannel::INSTRUMENT_METADATA,
                PaperMarketDataChannel::CANDLE_1M,
                PaperMarketDataChannel::CANDLE_5M,
                PaperMarketDataChannel::CANDLE_15M,
                PaperMarketDataChannel::CANDLE_1H,
                PaperMarketDataChannel::CONNECTION_STATE,
                PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            ], true)) {
                throw new \InvalidArgumentException();
            }
            $payload = $event->payload;
            $coin = $payload['native_symbol'] ?? null;
            if (!\is_string($coin)
                || (new HyperliquidPaperInstrumentMap())->normalizedSymbol($coin)
                    !== $event->symbol
            ) {
                throw new \InvalidArgumentException();
            }

            $naturalIdentity = match ($event->channel) {
                PaperMarketDataChannel::PUBLIC_TRADE => implode('|', [
                    $event->sourceNetwork->value,
                    $coin,
                    $this->liveUnsignedString($payload['block_time'] ?? null),
                    $this->liveUnsignedString($payload['trade_id'] ?? null),
                ]),
                PaperMarketDataChannel::TOP_OF_BOOK => $this->liveBookIdentity(
                    $event,
                    $coin,
                    $payload,
                    $snapshotEpochs,
                ),
                PaperMarketDataChannel::INSTRUMENT_METADATA => $this->liveMetadataIdentity(
                    $event,
                    $coin,
                    $payload,
                    $snapshotEpochs,
                    $metadataEpochs,
                ),
                PaperMarketDataChannel::CANDLE_1M,
                PaperMarketDataChannel::CANDLE_5M,
                PaperMarketDataChannel::CANDLE_15M,
                PaperMarketDataChannel::CANDLE_1H => $this->liveCandleIdentity(
                    $event,
                    $coin,
                    $payload,
                    $snapshotEpochs,
                    $candleFrontiers,
                ),
                PaperMarketDataChannel::CONNECTION_STATE => implode('|', [
                    $event->sourceNetwork->value,
                    $coin,
                    'connection',
                    (string) $this->livePositiveInt(
                        $payload['connection_epoch'] ?? null,
                    ),
                    $this->liveString($payload['state'] ?? null),
                ]),
                PaperMarketDataChannel::SNAPSHOT_BOUNDARY => $this->liveSnapshotIdentity(
                    $event,
                    $coin,
                    $payload,
                    $snapshotEpochs,
                    $metadataEpochs,
                ),
            };
            if ($event->channel !== PaperMarketDataChannel::SNAPSHOT_BOUNDARY
                && $event->channel !== PaperMarketDataChannel::CONNECTION_STATE
                && $event->channel !== PaperMarketDataChannel::INSTRUMENT_METADATA
                && !isset($snapshotEpochs[$event->symbol])
            ) {
                throw new \InvalidArgumentException();
            }
            $scope = implode('/', [
                $event->sourceNetwork->value,
                $event->sourceVenue->value,
                $event->symbol,
                $event->channel->value,
            ]);
            $digest = HyperliquidPaperSourceOrdinal::assignmentDigest(
                $naturalIdentity,
                $event->exchangeTimestamp,
                $event->payload,
            );
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE) {
                $identityHash = hash('sha256', $naturalIdentity);
                foreach ($tradeIdentityHistory as $entry) {
                    if (hash_equals($entry['identity_hash'], $identityHash)) {
                        throw new \InvalidArgumentException();
                    }
                }
                $tradeIdentityHistory[] = [
                    'identity_hash' => $identityHash,
                    'assignment_digest' => $digest,
                ];
                if (\count($tradeIdentityHistory)
                    > HyperliquidPaperLiveCheckpoint::MAXIMUM_TRADE_IDENTITIES
                ) {
                    array_shift($tradeIdentityHistory);
                }
            }
            $ordinals->commit($scope, $naturalIdentity, $digest, $event);
        } catch (\Throwable) {
            throw new \RuntimeException('paper_dataset_hyperliquid_live_event_invalid');
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, int> $snapshotEpochs
     * @param array<string, int> $metadataEpochs
     */
    private function liveMetadataIdentity(
        PaperMarketEvent $event,
        string $coin,
        array $payload,
        array $snapshotEpochs,
        array &$metadataEpochs,
    ): string {
        $epoch = $this->livePositiveInt($payload['source_epoch'] ?? null);
        if (!\in_array($payload['metadata_schema_version'] ?? null, [
            'paper-instrument-metadata.v1',
            'paper-instrument-metadata.v2',
        ], true)
            || ($payload['origin'] ?? null) !== 'rest_meta'
            || isset($metadataEpochs[$event->symbol])
                && $epoch <= $metadataEpochs[$event->symbol]
            || isset($snapshotEpochs[$event->symbol])
                && $epoch <= $snapshotEpochs[$event->symbol]
        ) {
            throw new \InvalidArgumentException();
        }
        $metadataEpochs[$event->symbol] = $epoch;

        return implode('|', [
            $event->sourceNetwork->value,
            $coin,
            'instrument_metadata',
            (string) $epoch,
            hash('sha256', CanonicalJson::encode($payload)),
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, int> $snapshotEpochs
     */
    private function liveBookIdentity(
        PaperMarketEvent $event,
        string $coin,
        array $payload,
        array $snapshotEpochs,
    ): string {
        if (($payload['synthetic'] ?? null) !== false
            || ($payload['origin'] ?? null) !== 'ws_l2_book'
            || !isset($snapshotEpochs[$event->symbol])
            || $this->liveUnsignedString($payload['source_epoch'] ?? null)
                !== (string) $snapshotEpochs[$event->symbol]
        ) {
            throw new \InvalidArgumentException();
        }

        return implode('|', [
            $event->sourceNetwork->value,
            $coin,
            'book',
            $this->liveUnsignedString($payload['source_time'] ?? null),
            $this->liveSha256($payload['source_book_hash'] ?? null),
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, int> $snapshotEpochs
     * @param array<string, int> $candleFrontiers
     */
    private function liveCandleIdentity(
        PaperMarketEvent $event,
        string $coin,
        array $payload,
        array $snapshotEpochs,
        array &$candleFrontiers,
    ): string {
        if (($payload['confirmed'] ?? null) !== true
            || ($payload['origin'] ?? null) !== 'ws_candle'
            || !isset($snapshotEpochs[$event->symbol])
        ) {
            throw new \InvalidArgumentException();
        }
        $interval = $this->liveString($payload['interval'] ?? null);
        $start = $this->liveUnsignedString($payload['start_time'] ?? null);
        $close = $this->liveUnsignedString($payload['close_time'] ?? null);
        $stream = $coin . '/' . $interval;
        if (isset($candleFrontiers[$stream])
            && BigInteger::of($start)->isLessThanOrEqualTo($candleFrontiers[$stream])
        ) {
            throw new \InvalidArgumentException();
        }
        if (BigInteger::of($start)->isGreaterThan(\PHP_INT_MAX)) {
            throw new \InvalidArgumentException();
        }
        $candleFrontiers[$stream] = (int) $start;

        return implode('|', [
            $event->sourceNetwork->value,
            $coin,
            $interval,
            $start,
            $close,
        ]);
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, int> $snapshotEpochs
     * @param array<string, int> $metadataEpochs
     */
    private function liveSnapshotIdentity(
        PaperMarketEvent $event,
        string $coin,
        array $payload,
        array &$snapshotEpochs,
        array $metadataEpochs,
    ): string {
        $reason = $this->liveString($payload['reason'] ?? null);
        $epoch = $this->livePositiveInt($payload['source_epoch'] ?? null);
        $previous = $snapshotEpochs[$event->symbol] ?? null;
        if (($previous === null && ($reason !== 'initial' || $epoch !== 1))
            || ($previous !== null
                && ($reason !== 'reconnect' || $epoch <= $previous))
            || ($metadataEpochs !== []
                && ($metadataEpochs[$event->symbol] ?? null) !== $epoch)
        ) {
            throw new \InvalidArgumentException();
        }
        $snapshotEpochs[$event->symbol] = $epoch;

        return implode('|', [
            $event->sourceNetwork->value,
            $coin,
            'snapshot',
            (string) $epoch,
            $reason,
        ]);
    }

    /**
     * @param array<string, int> $snapshotEpochs
     * @param array<string, int> $candleFrontiers
     * @param list<string> $eventIds
     * @param list<array{identity_hash: string, assignment_digest: string}> $tradeIdentityHistory
     */
    private function assertHyperliquidLiveCheckpoint(
        string $datasetDirectory,
        PaperDatasetManifest $manifest,
        HyperliquidPaperSourceOrdinal $ordinals,
        array $snapshotEpochs,
        array $candleFrontiers,
        array $eventIds,
        array $tradeIdentityHistory,
    ): void {
        try {
            if (array_keys($snapshotEpochs) !== ['BTCUSDT', 'ETHUSDT']) {
                $ordered = array_keys($snapshotEpochs);
                sort($ordered, \SORT_STRING);
                if ($ordered !== ['BTCUSDT', 'ETHUSDT']) {
                    throw new \InvalidArgumentException();
                }
            }
            $path = $datasetDirectory . '/checkpoints/hyperliquid-live.json';
            $snapshot = $this->readRegularFile(
                $path,
                'paper_dataset_hyperliquid_live_checkpoint_invalid',
                'paper_dataset_hyperliquid_live_checkpoint_validation',
                HyperliquidPaperLiveCheckpoint::MAXIMUM_BYTES + 256,
            );
            $document = json_decode(
                $snapshot['contents'],
                true,
                512,
                \JSON_THROW_ON_ERROR,
            );
            if (!\is_array($document)
                || array_is_list($document)
                || array_keys($document) !== ['sha256', 'state']
                || !\is_string($document['sha256'] ?? null)
                || !\is_array($document['state'] ?? null)
                || array_is_list($document['state'])
                || !hash_equals(
                    hash('sha256', CanonicalJson::encode($document['state'])),
                    $document['sha256'],
                )
                || CanonicalJson::encode($document) . "\n" !== $snapshot['contents']
            ) {
                throw new \InvalidArgumentException();
            }
            $checkpoint = HyperliquidPaperLiveCheckpoint::fromArray($document['state']);
            $expectedAcknowledged = array_slice(
                $eventIds,
                -HyperliquidPaperLiveCheckpoint::MAXIMUM_ACKNOWLEDGED_IDENTITIES,
            );
            if (!hash_equals($manifest->datasetId, $checkpoint->datasetId)
                || $manifest->network !== $checkpoint->network
                || !hash_equals(
                    HyperliquidPaperLivePolicy::configurationSha256($manifest->network),
                    $checkpoint->configurationSha256,
                )
                || $checkpoint->phase !== 'complete'
                || !$checkpoint->continuity
                || $checkpoint->failureReason !== null
                || $checkpoint->pendingEvent !== null
                || $checkpoint->pendingContinuation !== null
                || $checkpoint->currentCandles !== []
                || !$checkpoint->healthyStop['requested']
                || CanonicalJson::encode($checkpoint->ordinalState)
                    !== CanonicalJson::encode($ordinals->snapshot())
                || $checkpoint->finalizedCandleFrontiers !== $candleFrontiers
                || $checkpoint->acknowledgedIdentities !== $expectedAcknowledged
                || $checkpoint->tradeIdentityHistory !== $tradeIdentityHistory
                || $checkpoint->sourceEpoch !== max($snapshotEpochs)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof \RuntimeException
                && $exception->getMessage()
                    === 'paper_dataset_hyperliquid_live_checkpoint_invalid'
            ) {
                throw $exception;
            }

            throw new \RuntimeException(
                'paper_dataset_hyperliquid_live_checkpoint_invalid',
                0,
                $exception,
            );
        }
    }

    private function liveUnsignedString(mixed $value): string
    {
        if (!\is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private function livePositiveInt(mixed $value): int
    {
        if (!\is_int($value) || $value < 1) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private function liveString(mixed $value): string
    {
        if (!\is_string($value) || $value === '') {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private function liveSha256(mixed $value): string
    {
        if (!\is_string($value)
            || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    private function assertHyperliquidHistoricalEvent(
        #[\SensitiveParameter] PaperMarketEvent $event,
        #[\SensitiveParameter] PaperDatasetManifest $manifest,
    ): ?HyperliquidHistoricalEventCoverage {
        if ($manifest->venue !== PaperMarketDataVenue::HYPERLIQUID
            || $manifest->quality
                !== PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK
        ) {
            return null;
        }
        if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE) {
            throw new \RuntimeException('paper_dataset_hyperliquid_historical_trade_forbidden');
        }
        if (!\in_array($event->channel, [
            PaperMarketDataChannel::CANDLE_1M,
            PaperMarketDataChannel::CANDLE_5M,
            PaperMarketDataChannel::CANDLE_15M,
            PaperMarketDataChannel::CANDLE_1H,
            PaperMarketDataChannel::TOP_OF_BOOK,
        ], true)) {
            throw new \RuntimeException('paper_dataset_hyperliquid_channel_invalid');
        }
        try {
            return HyperliquidHistoricalEventCoverage::parse($event);
        } catch (\Throwable) {
            throw new \RuntimeException('paper_dataset_hyperliquid_model_event_invalid');
        }
    }

    /**
     * @param array<string, array<string, array<int, int>>> $candles
     * @param array<string, array<string, array<int, PaperMarketEvent>>> $candleEvents
     * @param list<array{symbol: string, coverage: HyperliquidHistoricalEventCoverage, event: PaperMarketEvent}> $books
     */
    private function assertHyperliquidHistoricalCoverage(
        PaperDatasetManifest $manifest,
        #[\SensitiveParameter] array $candles,
        #[\SensitiveParameter] array $candleEvents,
        #[\SensitiveParameter] array $books,
    ): void {
        if ($manifest->venue !== PaperMarketDataVenue::HYPERLIQUID
            || $manifest->quality
                !== PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK
        ) {
            return;
        }

        foreach ($books as $book) {
            $coverage = $book['coverage'];
            if (($candles[$book['symbol']][$coverage->interval][$coverage->startMilliseconds] ?? null)
                !== $coverage->closeMilliseconds
            ) {
                throw new \RuntimeException('paper_dataset_hyperliquid_model_event_invalid');
            }
        }
        if ($manifest->historicalCoverage === null) {
            $this->assertHyperliquidHistoricalBooksMatchModel($candleEvents, $books);

            return;
        }

        $durations = [
            '1m' => 60_000,
            '5m' => 300_000,
            '15m' => 900_000,
            '1h' => 3_600_000,
        ];
        foreach (array_keys($manifest->symbols) as $symbol) {
            foreach ($durations as $interval => $duration) {
                try {
                    $first = HyperliquidHistoricalTimeGrid::firstGridStartMilliseconds(
                        $manifest->historicalCoverage->fromTimestamp(),
                        $duration,
                    );
                    $exclusiveTo = HyperliquidHistoricalTimeGrid::exclusiveToMilliseconds(
                        $manifest->historicalCoverage->toTimestamp(),
                    );
                    $exclusiveStartLimit =
                        HyperliquidHistoricalTimeGrid::exclusiveStartLimitMilliseconds(
                            $manifest->historicalCoverage->toTimestamp(),
                            $duration,
                        );
                    $expectedCount = HyperliquidHistoricalTimeGrid::expectedCount(
                        $first,
                        $exclusiveTo,
                        $duration,
                    );
                } catch (\Throwable) {
                    throw new \RuntimeException('paper_dataset_hyperliquid_coverage_incomplete');
                }
                $starts = $candles[$symbol][$interval] ?? [];
                if (\count($starts) !== $expectedCount) {
                    throw new \RuntimeException('paper_dataset_hyperliquid_coverage_incomplete');
                }
                foreach ($starts as $start => $close) {
                    if ($start < $first
                        || $start >= $exclusiveStartLimit
                        || ($start - $first) % $duration !== 0
                        || $start > \PHP_INT_MAX - ($duration - 1)
                        || $start + $duration - 1 !== $close
                    ) {
                        throw new \RuntimeException(
                            'paper_dataset_hyperliquid_coverage_incomplete',
                        );
                    }
                }
            }
        }

        foreach ($candles as $symbol => $intervals) {
            if (!array_key_exists($symbol, $manifest->symbols)) {
                throw new \RuntimeException('paper_dataset_hyperliquid_coverage_incomplete');
            }
            foreach (array_keys($intervals) as $interval) {
                if (!isset($durations[$interval])) {
                    throw new \RuntimeException('paper_dataset_hyperliquid_coverage_incomplete');
                }
            }
        }
        $this->assertHyperliquidHistoricalBooksMatchModel($candleEvents, $books);
    }

    /**
     * @param array<string, array<string, array<int, PaperMarketEvent>>> $candleEvents
     * @param list<array{symbol: string, coverage: HyperliquidHistoricalEventCoverage, event: PaperMarketEvent}> $books
     */
    private function assertHyperliquidHistoricalBooksMatchModel(
        #[\SensitiveParameter] array $candleEvents,
        #[\SensitiveParameter] array $books,
    ): void {
        /** @var array<string, array<string, array<int, PaperMarketEvent>>> $actualBooks */
        $actualBooks = [];
        foreach ($books as $book) {
            $coverage = $book['coverage'];
            if (isset($actualBooks[$book['symbol']][$coverage->interval][
                $coverage->startMilliseconds
            ])) {
                throw new \RuntimeException('paper_dataset_hyperliquid_model_event_invalid');
            }
            $actualBooks[$book['symbol']][$coverage->interval][
                $coverage->startMilliseconds
            ] = $book['event'];
        }

        foreach ($candleEvents as $symbol => $intervals) {
            foreach ($intervals as $interval => $eventsByStart) {
                ksort($eventsByStart, \SORT_NUMERIC);
                $model = new HyperliquidPrudentBookModel();
                foreach ($eventsByStart as $start => $event) {
                    try {
                        $candle = $this->hyperliquidCandleFromEvent($event, $interval);
                        $expected = $model->push($candle);
                    } catch (\Throwable) {
                        throw new \RuntimeException(
                            'paper_dataset_hyperliquid_model_event_invalid',
                        );
                    }
                    $actual = $actualBooks[$symbol][$interval][$start] ?? null;
                    if ($expected === null) {
                        if ($actual !== null) {
                            throw new \RuntimeException(
                                'paper_dataset_hyperliquid_model_event_invalid',
                            );
                        }

                        continue;
                    }
                    $expectedPayload = [
                            'bid_price' => $expected['bid'],
                            'bid_size' => $expected['size'],
                            'ask_price' => $expected['ask'],
                            'ask_size' => $expected['size'],
                            'model_name' => HyperliquidPrudentBookModel::NAME,
                            'model_version' => HyperliquidPrudentBookModel::VERSION,
                            'origin' => 'historical_candle_model',
                            'source_candle_start' => (string) $start,
                            'synthetic' => true,
                        ];
                    if ($actual === null
                        || CanonicalJson::encode($actual->payload)
                            !== CanonicalJson::encode($expectedPayload)
                    ) {
                        throw new \RuntimeException(
                            'paper_dataset_hyperliquid_model_event_invalid',
                        );
                    }
                    unset($actualBooks[$symbol][$interval][$start]);
                }
            }
        }
        foreach ($actualBooks as $intervals) {
            foreach ($intervals as $eventsByStart) {
                if ($eventsByStart !== []) {
                    throw new \RuntimeException(
                        'paper_dataset_hyperliquid_model_event_invalid',
                    );
                }
            }
        }
    }

    private function hyperliquidCandleFromEvent(
        #[\SensitiveParameter] PaperMarketEvent $event,
        string $interval,
    ): HyperliquidCandle {
        $payload = $event->payload;
        $nativeSymbol = $payload['native_symbol'] ?? null;
        if (!\is_string($nativeSymbol)
            || (new HyperliquidPaperInstrumentMap())->normalizedSymbol($nativeSymbol)
                !== $event->symbol
        ) {
            throw new \InvalidArgumentException();
        }

        return HyperliquidCandle::fromApiRow([
            'T' => $this->hyperliquidUnsignedInteger($payload['close_time'] ?? null),
            'c' => $payload['close'] ?? null,
            'h' => $payload['high'] ?? null,
            'i' => $interval,
            'l' => $payload['low'] ?? null,
            'n' => $this->hyperliquidUnsignedInteger($payload['trade_count'] ?? null),
            'o' => $payload['open'] ?? null,
            's' => $nativeSymbol,
            't' => $this->hyperliquidUnsignedInteger($payload['start_time'] ?? null),
            'v' => $payload['volume'] ?? null,
        ], $nativeSymbol, $interval);
    }

    private function hyperliquidUnsignedInteger(mixed $value): int
    {
        $maximum = (string) \PHP_INT_MAX;
        if (!\is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1
            || strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new \InvalidArgumentException();
        }

        return (int) $value;
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
