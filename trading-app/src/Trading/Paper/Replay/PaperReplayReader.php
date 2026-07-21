<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetFormatLimits;
use App\Trading\Paper\Dataset\PaperDatasetLineReader;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigInteger;

final class PaperReplayReader
{
    public const DEFAULT_EVENT_LIMIT = 1_000_000;

    private const REGULAR_FILE_TYPE = 0100000;
    private const SYMLINK_FILE_TYPE = 0120000;
    private const FILE_TYPE_MASK = 0170000;

    private ?int $currentEventIndex = null;
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

    /** @return \Generator<int, PaperMarketEvent> */
    public function read(
        #[\SensitiveParameter] string $datasetDirectory,
        string $consumerId,
        ?PaperReplayCheckpoint $checkpoint = null,
    ): \Generator {
        $this->currentEventIndex = null;
        $manifest = $this->verifier->verify($datasetDirectory);
        if ($manifest->eventCount > $this->eventLimit) {
            throw new \RuntimeException('paper_replay_event_limit_exceeded');
        }

        $events = $this->readEvents($datasetDirectory, $manifest);
        usort($events, self::compare(...));

        $checkpoint ??= $this->checkpointStore->load($datasetDirectory, $consumerId);
        $startIndex = $this->resumeIndex($events, $manifest, $consumerId, $checkpoint);
        $count = count($events);
        for ($index = $startIndex; $index < $count; ++$index) {
            $event = $events[$index]['event'];
            $this->clock->advanceTo($event->exchangeTimestamp);
            $this->currentEventIndex = $index;

            yield $index => $event;
        }
    }

    /**
     * @return list<array{event: PaperMarketEvent, input_index: int}>
     */
    private function readEvents(
        #[\SensitiveParameter] string $datasetDirectory,
        PaperDatasetManifest $manifest,
    ): array {
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
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_dataset_file_unreadable');
        }

        $events = [];
        $checksum = hash_init('sha256');
        try {
            $opened = $this->filesystem->stat($handle, 'paper_replay_events_validation');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($before, $opened)
            ) {
                throw new \RuntimeException('paper_replay_events_changed');
            }
            while (($line = $this->lineReader->read(
                $handle,
                'paper_replay_events_read_failed',
                'paper_replay_event_invalid',
            )) !== false) {
                hash_update($checksum, $line);
                if (trim($line) === '') {
                    continue;
                }
                if (count($events) >= $this->eventLimit) {
                    throw new \RuntimeException('paper_replay_event_limit_exceeded');
                }

                $raw = substr($line, 0, -1);
                try {
                    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    if (!\is_array($data) || array_is_list($data)) {
                        throw new \InvalidArgumentException();
                    }
                    /** @var array<string, mixed> $data */
                    $event = PaperMarketEvent::fromArray($data);
                    if (CanonicalJson::encode($event->toArray()) !== $raw) {
                        throw new \InvalidArgumentException();
                    }
                } catch (\Throwable) {
                    throw new \RuntimeException('paper_replay_event_invalid');
                }

                $events[] = ['event' => $event, 'input_index' => count($events)];
            }
            if (!feof($handle)) {
                throw new \RuntimeException('paper_replay_events_read_failed');
            }
            $current = $this->filesystem->pathStat($path, 'paper_replay_events_validation');
            if ($current === false) {
                throw new \RuntimeException('paper_replay_events_changed');
            }
            if ($this->isSymlink($current)) {
                throw new \RuntimeException('paper_dataset_symlink_rejected');
            }
            if (!$this->isPrivateRegularFile($current) || !$this->sameFile($opened, $current)) {
                throw new \RuntimeException('paper_replay_events_changed');
            }
        } finally {
            fclose($handle);
        }

        $eventsChecksum = hash_final($checksum);
        if ($manifest->eventsFileSha256 === null
            || !hash_equals($manifest->eventsFileSha256, $eventsChecksum)
        ) {
            throw new \RuntimeException('paper_dataset_checksum_mismatch');
        }

        return $events;
    }

    /**
     * @param array{event: PaperMarketEvent, input_index: int} $left
     * @param array{event: PaperMarketEvent, input_index: int} $right
     */
    private static function compare(array $left, array $right): int
    {
        $leftEvent = $left['event'];
        $rightEvent = $right['event'];

        $comparison = $leftEvent->exchangeTimestamp <=> $rightEvent->exchangeTimestamp;
        if ($comparison !== 0) {
            return $comparison;
        }

        $comparison = strcmp($leftEvent->channel->value, $rightEvent->channel->value);
        if ($comparison !== 0) {
            return $comparison;
        }

        if ($leftEvent->sequence === null || $rightEvent->sequence === null) {
            if ($leftEvent->sequence !== $rightEvent->sequence) {
                return $leftEvent->sequence === null ? 1 : -1;
            }
        } else {
            $comparison = BigInteger::of($leftEvent->sequence)->compareTo(BigInteger::of($rightEvent->sequence));
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        $comparison = strcmp($leftEvent->eventId, $rightEvent->eventId);
        if ($comparison !== 0) {
            return $comparison;
        }

        return $left['input_index'] <=> $right['input_index'];
    }

    /**
     * @param list<array{event: PaperMarketEvent, input_index: int}> $events
     */
    private function resumeIndex(
        array $events,
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
        if ($manifest->eventsFileSha256 === null
            || !hash_equals($manifest->eventsFileSha256, $checkpoint->eventsFileSha256)
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_checksum_mismatch');
        }

        $foundIndex = null;
        foreach ($events as $index => $entry) {
            if (hash_equals($checkpoint->eventId, $entry['event']->eventId)) {
                $foundIndex = $index;
                break;
            }
        }
        if ($foundIndex === null) {
            throw new \RuntimeException('paper_replay_checkpoint_event_not_found');
        }

        $event = $events[$foundIndex]['event'];
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
