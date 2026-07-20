<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigInteger;

final class PaperDatasetVerifier
{
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const FILE_TYPE_MASK = 0170000;

    public function __construct(
        private readonly PaperDatasetManifestCodec $codec = new PaperDatasetManifestCodec(),
        private readonly PaperDatasetRecorderFilesystem $filesystem = new PaperDatasetRecorderFilesystem(),
    ) {
    }

    public function verify(#[\SensitiveParameter] string $datasetDirectory): PaperDatasetManifest
    {
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
            );
            $assertDirectories();

            $manifest = $this->codec->decode($manifestSnapshot['contents']);
            if ($manifest->state !== PaperDatasetState::COMPLETE) {
                throw new \RuntimeException('paper_dataset_not_complete');
            }

            $facts = $this->scan($eventsPath, $manifest);
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

            return $manifest;
        } finally {
            if ($datasetPin !== null) {
                fclose($datasetPin['handle']);
            }
            fclose($rootPin['handle']);
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
    ): array {
        $handle = $this->openRegularFile($path, 'rb', $readError, $validationOperation);
        try {
            $statistics = $this->filesystem->stat($handle, $validationOperation);
            $contents = stream_get_contents($handle);
            $position = ftell($handle);
            if ($statistics === false
                || !isset($statistics['size'])
                || !\is_int($statistics['size'])
                || $contents === false
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
                || !$this->isDirectory($statistics)
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
        if ($opened === false || !$this->isDirectory($opened)) {
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
            || !$this->isDirectory($statistics)
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
     *   events_checksum: string,
     *   events_bytes: int,
     *   events_identity: array{dev: int, ino: int}
     * }
     */
    private function scan(#[\SensitiveParameter] string $eventsPath, PaperDatasetManifest $manifest): array
    {
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
        $checksumContext = hash_init('sha256');

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
            while (($line = fgets($handle)) !== false) {
                hash_update($checksumContext, $line);
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

        return [
            'event_count' => $count,
            'last_event_id' => $lastEventId,
            'start_exchange_timestamp' => $start,
            'end_exchange_timestamp' => $end,
            'channels' => $channels,
            'sequence_gaps' => $sequenceGaps,
            'events_checksum' => $parsedChecksum,
            'events_bytes' => $statistics['size'],
            'events_identity' => [
                'dev' => $statistics['dev'],
                'ino' => $statistics['ino'],
            ],
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
