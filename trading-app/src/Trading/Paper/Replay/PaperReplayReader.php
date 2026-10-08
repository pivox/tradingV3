<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetFormatLimits;
use App\Trading\Paper\Dataset\PaperDatasetLineReader;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalEventCoverage;

final class PaperReplayReader
{
    /**
     * About twice a 24 h OKX public capture (~3.7 M events). Replay keeps one compact
     * order key per event in memory (~150 bytes, measured) and the verified events in a
     * private temporary spill file (~1.3 KB each) instead of decoded events in memory
     * (~2.9 KB each), so this bound stays well inside
     * PaperCertificationCampaignRunner::CHILD_MEMORY_LIMIT.
     */
    public const DEFAULT_EVENT_LIMIT = 8_000_000;

    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const SYMLINK_FILE_TYPE = 0120000;
    private const FILE_TYPE_MASK = 0170000;

    private ?int $currentEventIndex = null;

    private ?PaperReplayEventIndex $activeIndex = null;

    private int $activeStartIndex = 0;
    private readonly PaperDatasetRecorderFilesystem $filesystem;
    private readonly PaperDatasetLineReader $lineReader;

    public function __construct(
        private readonly PaperDatasetVerifier $verifier,
        private readonly PaperReplayCheckpointStore $checkpointStore,
        private readonly PaperReplayClock $clock,
        private readonly int $eventLimit = self::DEFAULT_EVENT_LIMIT,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
    ) {
        if ($eventLimit <= 0) {
            throw new \InvalidArgumentException('paper_replay_event_limit_invalid');
        }

        $this->filesystem = $filesystem ?? new PaperDatasetRecorderFilesystem();
        $this->lineReader = new PaperDatasetLineReader($this->filesystem);
    }

    public function currentEventIndex(): ?int
    {
        return $this->currentEventIndex;
    }

    public function eventLimit(): int
    {
        return $this->eventLimit;
    }

    /**
     * The events a resumed read() skipped (positions 0..resume-1, in replay order),
     * re-materialized from the same verified load while that read() is active.
     *
     * @return \Generator<int, PaperMarketEvent>
     */
    public function replayedPrefix(): \Generator
    {
        $events = $this->activeIndex ?? throw new \LogicException('paper_replay_prefix_unavailable');
        $count = $this->activeStartIndex;
        for ($index = 0; $index < $count; ++$index) {
            if ($this->activeIndex !== $events) {
                throw new \LogicException('paper_replay_prefix_unavailable');
            }
            yield $index => $events->event($index);
        }
    }

    /**
     * @param bool $receiptVerified the caller validated a campaign verification receipt for
     *        this dataset and $expectedManifest: the full verification pass is not repeated,
     *        the manifest file must still equal $expectedManifest and the events checksum is
     *        still recomputed while loading
     * @param PaperReplayOrder|null $order replay order and observation instants (default:
     *        exchange time); a Hyperliquid historical dataset always keeps its own order
     *
     * @return \Generator<int, PaperMarketEvent>
     */
    public function read(
        #[\SensitiveParameter] string $datasetDirectory,
        string $consumerId,
        ?PaperReplayCheckpoint $checkpoint = null,
        ?PaperDatasetManifest $expectedManifest = null,
        bool $loadDatasetCheckpoint = true,
        bool $receiptVerified = false,
        ?PaperReplayOrder $order = null,
    ): \Generator {
        yield from $this->replay(
            $datasetDirectory,
            $consumerId,
            $checkpoint,
            $expectedManifest,
            true,
            $loadDatasetCheckpoint,
            $receiptVerified,
            $order,
        );
    }

    public function assertCanResume(
        #[\SensitiveParameter] string $datasetDirectory,
        string $consumerId,
        PaperReplayCheckpoint $checkpoint,
        PaperDatasetManifest $expectedManifest,
        bool $receiptVerified = false,
        ?PaperReplayOrder $order = null,
    ): void {
        $currentEventIndex = $this->currentEventIndex;
        $validation = $this->replay(
            $datasetDirectory,
            $consumerId,
            $checkpoint,
            $expectedManifest,
            false,
            false,
            $receiptVerified,
            $order,
        );
        try {
            $validation->valid();
        } finally {
            unset($validation);
            $this->currentEventIndex = $currentEventIndex;
        }
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function replay(
        #[\SensitiveParameter] string $datasetDirectory,
        string $consumerId,
        ?PaperReplayCheckpoint $checkpoint,
        ?PaperDatasetManifest $expectedManifest,
        bool $advanceClock,
        bool $loadDatasetCheckpoint,
        bool $receiptVerified = false,
        ?PaperReplayOrder $order = null,
    ): \Generator {
        if ($advanceClock) {
            $this->currentEventIndex = null;
        }
        $datasetPin = $this->openPinnedDatasetDirectory($datasetDirectory);
        $datasetDirectory = $datasetPin['path'];
        $events = null;
        try {
            $this->assertPinnedDatasetDirectory(
                $datasetPin,
                'paper_replay_dataset_before_verify',
            );
            try {
                if ($receiptVerified) {
                    if ($expectedManifest === null) {
                        throw new \LogicException('paper_replay_receipt_manifest_required');
                    }
                    $manifest = $this->pinnedManifest($datasetDirectory);
                } else {
                    $manifest = $this->verifier->verify($datasetDirectory, $this->eventLimit);
                }
            } catch (\RuntimeException $failure) {
                if ($failure->getMessage() === 'paper_dataset_event_limit_exceeded') {
                    throw new \RuntimeException('paper_replay_event_limit_exceeded');
                }

                throw $failure;
            }
            if ($expectedManifest !== null
                && CanonicalJson::encode($manifest->toArray()) !== CanonicalJson::encode($expectedManifest->toArray())
            ) {
                throw new \RuntimeException('paper_replay_dataset_identity_changed');
            }
            $this->assertPinnedDatasetDirectory(
                $datasetPin,
                'paper_replay_dataset_after_verify',
            );
            if ($manifest->eventCount > $this->eventLimit) {
                throw new \RuntimeException('paper_replay_event_limit_exceeded');
            }

            $indexed = $this->indexEvents(
                $datasetDirectory,
                $manifest,
                $datasetPin,
                $receiptVerified,
                $order ?? PaperReplayOrder::exchangeTime(),
            );
            $events = $indexed['index'];
            $order = $indexed['order'];
            $this->assertPinnedDatasetDirectory(
                $datasetPin,
                'paper_replay_dataset_after_events_load',
            );
            if ($indexed['hyperliquid_interval_invalid']) {
                throw new \RuntimeException('paper_replay_hyperliquid_interval_invalid');
            }
            $events->sort();
            $this->assertPinnedDatasetDirectory(
                $datasetPin,
                'paper_replay_dataset_after_sort',
            );

            if ($checkpoint === null && $loadDatasetCheckpoint) {
                $checkpoint = $this->checkpointStore->load(
                    $datasetDirectory,
                    $consumerId,
                    $datasetPin['identity'],
                    true,
                );
            }
            $this->assertPinnedDatasetDirectory(
                $datasetPin,
                'paper_replay_dataset_after_checkpoint_load',
            );
            $startIndex = $this->resumeIndex($events, $manifest, $consumerId, $checkpoint);
            $this->assertPinnedDatasetDirectory(
                $datasetPin,
                'paper_replay_dataset_after_resume',
            );
            if ($advanceClock && $startIndex > 0) {
                $this->restoreObservationWatermark($events, $startIndex, $order);
            }
            if ($advanceClock) {
                $this->activeIndex = $events;
                $this->activeStartIndex = $startIndex;
            }
            $count = $events->count();
            $strictInitialObservation = $startIndex === 0;
            for ($index = $startIndex; $index < $count; ++$index) {
                $this->assertPinnedDatasetDirectory(
                    $datasetPin,
                    'paper_replay_dataset_before_yield',
                );
                $event = $events->event($index);
                if ($advanceClock) {
                    $this->advanceClockToObservation($event, $strictInitialObservation, $order);
                    $strictInitialObservation = false;
                    $this->currentEventIndex = $index;
                }

                yield $index => $event;

                $this->assertPinnedDatasetDirectory(
                    $datasetPin,
                    'paper_replay_dataset_after_yield',
                );
            }
        } finally {
            if ($events !== null && $this->activeIndex === $events) {
                $this->activeIndex = null;
                $this->activeStartIndex = 0;
            }
            $events?->close();
            fclose($datasetPin['handle']);
        }
    }

    private function advanceClockToObservation(
        PaperMarketEvent $event,
        bool $strictInitialObservation,
        PaperReplayOrder $order,
    ): void {
        $observedAt = $order->observationTimestamp($event);
        if ($strictInitialObservation || $observedAt > $this->clock->now()) {
            $this->clock->advanceTo($observedAt);
        }
    }

    private function restoreObservationWatermark(
        PaperReplayEventIndex $events,
        int $endExclusive,
        PaperReplayOrder $order,
    ): void {
        $watermark = $this->clock->now();
        for ($index = 0; $index < $endExclusive; ++$index) {
            $observedAt = $order->observationTimestamp($events->event($index));
            if ($observedAt > $watermark) {
                $watermark = $observedAt;
            }
        }
        if ($watermark > $this->clock->now()) {
            $this->clock->advanceTo($watermark);
        }
    }

    /**
     * Validates and hashes every events line exactly like the former full load, but
     * keeps each validated event in a private spill file and only its order key in memory.
     * With a campaign receipt ($receiptVerified) the payload redaction scan is not repeated:
     * the bytes are accepted only if their checksum equals the receipt-pinned fingerprint.
     *
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $datasetPin
     *
     * @return array{index: PaperReplayEventIndex, hyperliquid_interval_invalid: bool, order: PaperReplayOrder}
     */
    private function indexEvents(
        #[\SensitiveParameter] string $datasetDirectory,
        PaperDatasetManifest $manifest,
        array $datasetPin,
        bool $receiptVerified,
        PaperReplayOrder $order,
    ): array {
        $this->assertPinnedDatasetDirectory($datasetPin, 'paper_replay_dataset_before_events_open');
        $path = $datasetDirectory . DIRECTORY_SEPARATOR . 'events.ndjson';
        $before = $this->filesystem->pathStat($path, 'paper_replay_events_validation');
        if ($before === false) {
            throw new \RuntimeException('paper_dataset_file_unreadable');
        }
        if ($this->isSymlink($before)) {
            throw new \RuntimeException('paper_dataset_symlink_rejected');
        }
        if (!$this->isPrivateRegularFile($before)) {
            throw new \RuntimeException('paper_dataset_file_unreadable');
        }
        $this->assertPinnedDatasetDirectory($datasetPin, 'paper_replay_dataset_after_events_lstat');
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_file_unreadable');
        }
        try {
            $spill = $this->openSpill();
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }

        $hyperliquidHistorical = $manifest->venue === PaperMarketDataVenue::HYPERLIQUID
            && $manifest->quality === PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK;
        if ($hyperliquidHistorical) {
            // Modelled historical candles keep their own (timestamp, symbol, interval) order.
            $order = PaperReplayOrder::exchangeTime();
        }
        $events = new PaperReplayEventIndex($spill, $hyperliquidHistorical, $this->filesystem, $order);
        $intervalInvalid = false;
        $checksum = hash_init('sha256');
        try {
            $opened = $this->filesystem->stat($handle, 'paper_replay_events_validation');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($before, $opened)
            ) {
                throw new \RuntimeException('paper_replay_events_changed');
            }
            $this->assertPinnedDatasetDirectory($datasetPin, 'paper_replay_dataset_after_events_open');
            while (($line = $this->lineReader->read(
                $handle,
                'paper_replay_events_read_failed',
                'paper_replay_event_invalid',
            )) !== false) {
                hash_update($checksum, $line);
                if (trim($line) === '') {
                    continue;
                }
                if ($events->count() >= $this->eventLimit) {
                    throw new \RuntimeException('paper_replay_event_limit_exceeded');
                }

                $raw = substr($line, 0, -1);
                try {
                    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    if (!\is_array($data) || array_is_list($data)) {
                        throw new \InvalidArgumentException();
                    }
                    /** @var array<string, mixed> $data */
                    // With a campaign receipt the redaction scan already passed on these exact
                    // bytes: the checksum of this same pass is compared with the receipt-pinned
                    // fingerprint below, before the index is sorted or any event is yielded.
                    $event = PaperMarketEvent::fromArray($data, $receiptVerified);
                    if (CanonicalJson::encode($event->toArray()) !== $raw) {
                        throw new \InvalidArgumentException();
                    }
                } catch (\Throwable) {
                    throw new \RuntimeException('paper_replay_event_invalid');
                }

                $interval = null;
                if ($hyperliquidHistorical) {
                    // Deferred like the former post-load sort: every line is still
                    // validated and hashed before this stable reason is raised.
                    try {
                        $interval = HyperliquidHistoricalEventCoverage::parse($event)->intervalMilliseconds;
                    } catch (\Throwable) {
                        $intervalInvalid = true;
                        $interval = 0;
                    }
                }
                $events->append($event, $interval);
            }
            if (!feof($handle)) {
                throw new \RuntimeException('paper_replay_events_read_failed');
            }
            $position = ftell($handle);
            $current = $this->filesystem->pathStat($path, 'paper_replay_events_validation');
            if ($current === false) {
                throw new \RuntimeException('paper_replay_events_changed');
            }
            if ($this->isSymlink($current)) {
                throw new \RuntimeException('paper_dataset_symlink_rejected');
            }
            if (!$this->isPrivateRegularFile($current)
                || !isset($opened['size'], $current['size'])
                || !\is_int($opened['size'])
                || !\is_int($current['size'])
                || $position === false
                || $position !== $opened['size']
                || $opened['size'] !== $current['size']
                || !$this->sameFile($opened, $current)
            ) {
                throw new \RuntimeException('paper_replay_events_changed');
            }
            $final = $this->filesystem->stat($handle, 'paper_replay_events_validation');
            if ($final === false
                || !$this->isPrivateRegularFile($final)
                || !isset($final['size'])
                || !\is_int($final['size'])
                || $final['size'] !== $opened['size']
                || !$this->sameFile($opened, $final)
            ) {
                throw new \RuntimeException('paper_replay_events_changed');
            }
            $this->assertPinnedDatasetDirectory($datasetPin, 'paper_replay_dataset_after_events_read');
        } finally {
            fclose($handle);
        }

        $eventsChecksum = hash_final($checksum);
        if ($manifest->eventsFileSha256 === null
            || !hash_equals($manifest->eventsFileSha256, $eventsChecksum)
        ) {
            throw new \RuntimeException('paper_dataset_checksum_mismatch');
        }

        return ['index' => $events, 'hyperliquid_interval_invalid' => $intervalInvalid, 'order' => $order];
    }

    /**
     * Private 0600 file in sys_get_temp_dir(), unlinked right after creation so that
     * no process can reopen it and even a killed replay leaves nothing behind.
     *
     * @return resource
     */
    private function openSpill()
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'paper-replay-spill-' . bin2hex(random_bytes(16));
        $spill = $this->filesystem->createPrivateFile($path, 'paper_replay_spill_create');
        if ($spill === false) {
            throw new \RuntimeException('paper_replay_spill_failed');
        }
        $unlinked = @unlink($path);
        $statistics = $this->filesystem->stat($spill, 'paper_replay_spill_create');
        if (!$unlinked
            || $statistics === false
            || !$this->isPrivateRegularFile($statistics)
            || ($statistics['nlink'] ?? null) !== 0
            || ($statistics['size'] ?? null) !== 0
        ) {
            fclose($spill);

            throw new \RuntimeException('paper_replay_spill_failed');
        }

        return $spill;
    }

    private function pinnedManifest(#[\SensitiveParameter] string $datasetDirectory): PaperDatasetManifest
    {
        $path = $datasetDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $statistics = $this->filesystem->pathStat($path, 'paper_replay_manifest_validation');
        if ($statistics === false || $this->isSymlink($statistics) || !$this->isPrivateRegularFile($statistics)) {
            throw new \RuntimeException('paper_dataset_manifest_unreadable');
        }
        $contents = @file_get_contents($path, false, null, 0, PaperDatasetFormatLimits::MAX_MANIFEST_BYTES + 1);
        if (!\is_string($contents) || $contents === '' || \strlen($contents) > PaperDatasetFormatLimits::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('paper_dataset_manifest_unreadable');
        }
        $manifest = (new PaperDatasetManifestCodec())->decode($contents);
        if ($manifest->state !== PaperDatasetState::COMPLETE) {
            throw new \RuntimeException('paper_dataset_not_complete');
        }

        return $manifest;
    }

    /** @return array{handle: resource, identity: array{dev: int, ino: int}, path: string} */
    private function openPinnedDatasetDirectory(#[\SensitiveParameter] string $path): array
    {
        $this->assertNoSymlinkComponents($path);
        $before = $this->filesystem->pathStat($path, 'paper_replay_dataset_directory_validation');
        if ($before === false || !$this->isPrivateDirectory($before)) {
            throw new \RuntimeException('paper_dataset_directory_invalid');
        }
        $handle = $this->filesystem->openDirectory($path, 'paper_replay_dataset_directory_validation');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_directory_invalid');
        }

        try {
            $opened = $this->filesystem->stat($handle, 'paper_replay_dataset_directory_validation');
            if ($opened === false
                || !$this->isPrivateDirectory($opened)
                || !$this->sameFile($before, $opened)
                || !isset($opened['dev'], $opened['ino'])
                || !\is_int($opened['dev'])
                || !\is_int($opened['ino'])
            ) {
                throw new \RuntimeException('paper_dataset_directory_changed');
            }
            $resolved = realpath($path);
            if ($resolved === false) {
                throw new \RuntimeException('paper_dataset_directory_changed');
            }
            $pin = [
                'handle' => $handle,
                'identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']],
                'path' => $resolved,
            ];
            $this->assertPinnedDatasetDirectory($pin, 'paper_replay_dataset_directory_validation');

            return $pin;
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $pin */
    private function assertPinnedDatasetDirectory(array $pin, string $operation): void
    {
        $opened = $this->filesystem->stat($pin['handle'], $operation);
        $current = $this->filesystem->pathStat($pin['path'], $operation);
        if ($current !== false && $this->isSymlink($current)) {
            throw new \RuntimeException('paper_dataset_symlink_rejected');
        }
        if ($opened === false
            || $current === false
            || !$this->isPrivateDirectory($opened)
            || !$this->isPrivateDirectory($current)
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw new \RuntimeException('paper_dataset_directory_changed');
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

    private function resumeIndex(
        PaperReplayEventIndex $events,
        PaperDatasetManifest $manifest,
        string $consumerId,
        ?PaperReplayCheckpoint $checkpoint,
    ): int {
        PaperReplayCheckpoint::assertConsumerId($consumerId);
        if ($checkpoint === null) {
            return 0;
        }
        if (!hash_equals($manifest->datasetId, $checkpoint->datasetId)) {
            throw new \RuntimeException('paper_replay_checkpoint_dataset_mismatch');
        }
        if (!hash_equals($consumerId, $checkpoint->consumerId)) {
            throw new \RuntimeException('paper_replay_checkpoint_consumer_mismatch');
        }
        if ($manifest->network !== $checkpoint->network) {
            throw new \RuntimeException('paper_replay_checkpoint_network_mismatch');
        }
        if ($manifest->eventsFileSha256 === null
            || !hash_equals($manifest->eventsFileSha256, $checkpoint->eventsFileSha256)
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_checksum_mismatch');
        }

        $foundIndex = $events->positionOf($checkpoint->eventId);
        if ($foundIndex === null) {
            throw new \RuntimeException('paper_replay_checkpoint_event_not_found');
        }

        $event = $events->event($foundIndex);
        if ($foundIndex !== $checkpoint->eventIndex
            || $event->exchangeTimestamp != $checkpoint->exchangeTimestamp
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_event_mismatch');
        }

        return $foundIndex + 1;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateRegularFile(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE_TYPE
            && ($statistics['mode'] & 0777) === 0600;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateDirectory(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE
            && ($statistics['mode'] & 0777) === 0700;
    }

    /** @param array<string, mixed> $statistics */
    private function isSymlink(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE;
    }

    /** @param array<string, mixed> $left
     *  @param array<string, mixed> $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && \is_int($left['dev'])
            && \is_int($left['ino'])
            && \is_int($right['dev'])
            && \is_int($right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }
}
