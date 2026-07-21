<?php

declare(strict_types=1);

namespace App\Trading\Paper\Replay;

use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\CanonicalJson;

final class PaperReplayCheckpointStore
{
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const SYMLINK_FILE_TYPE = 0120000;
    private const FILE_TYPE_MASK = 0170000;
    private const MAX_CHECKPOINT_BYTES = 16_384;

    public function __construct(
        private readonly PaperDatasetRecorderFilesystem $filesystem = new PaperDatasetRecorderFilesystem(),
    ) {
    }

    public function save(
        #[\SensitiveParameter] string $datasetDirectory,
        #[\SensitiveParameter] PaperReplayCheckpoint $checkpoint,
    ): void {
        PaperReplayCheckpoint::assertConsumerId($checkpoint->consumerId);
        $datasetPin = $this->openPinnedDatasetDirectory($datasetDirectory, requirePrivate: true);
        try {
            $this->savePinnedDataset($datasetPin, $checkpoint);
            $this->assertPinnedDatasetDirectory($datasetPin);
        } finally {
            fclose($datasetPin['handle']);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, requirePrivate: bool} $datasetPin
     */
    private function savePinnedDataset(array $datasetPin, PaperReplayCheckpoint $checkpoint): void
    {
        $this->assertPinnedDatasetDirectory($datasetPin);
        $datasetDirectory = $datasetPin['path'];
        $checkpointDirectory = $this->checkpointDirectory($datasetPin, create: true);
        if ($checkpointDirectory === null) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
        $directory = $checkpointDirectory['path'];
        $path = $directory . DIRECTORY_SEPARATOR . $checkpoint->consumerId . '.json';
        $directoryPin = $this->openPinnedDirectory($directory, $checkpointDirectory['identity']);
        $lock = null;

        try {
            $this->assertPinnedDirectory($directoryPin, $directory);
            $lock = $this->acquireConsumerLock($directoryPin, $directory, $checkpoint->consumerId);
            $this->assertPinnedFile($lock, $lock['path'], 'paper_replay_checkpoint_lock_failed');
            $previous = $this->load(
                $datasetDirectory,
                $checkpoint->consumerId,
                $datasetPin['identity'],
                $datasetPin['requirePrivate'],
            );
            $this->assertPinnedDirectory($directoryPin, $directory);
            $this->assertPinnedFile($lock, $lock['path'], 'paper_replay_checkpoint_lock_failed');
            if ($previous !== null && $checkpoint->eventIndex < $previous->eventIndex) {
                throw new \RuntimeException('paper_replay_checkpoint_regression');
            }
            if ($previous !== null
                && (!hash_equals($previous->datasetId, $checkpoint->datasetId)
                    || !hash_equals($previous->eventsFileSha256, $checkpoint->eventsFileSha256))
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_mismatch');
            }
            if ($previous !== null && $checkpoint->eventIndex === $previous->eventIndex) {
                if ($checkpoint->toArray() !== $previous->toArray()) {
                    throw new \RuntimeException('paper_replay_checkpoint_regression');
                }

                $this->completeIdempotentPublication(
                    $directoryPin,
                    $lock,
                    $datasetDirectory,
                    $directory,
                    $checkpoint,
                    $datasetPin['identity'],
                    $datasetPin['requirePrivate'],
                );

                return;
            }

            $this->publishCheckpoint(
                $directoryPin,
                $lock,
                $directory,
                $path,
                $checkpoint,
                $previous,
            );
        } finally {
            if ($lock !== null) {
                @flock($lock['handle'], LOCK_UN);
                fclose($lock['handle']);
            }
            fclose($directoryPin['handle']);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}}               $directoryPin
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $lock
     * @param array{dev: int, ino: int}                                                  $expectedDatasetIdentity
     */
    private function completeIdempotentPublication(
        array $directoryPin,
        array $lock,
        #[\SensitiveParameter] string $datasetDirectory,
        #[\SensitiveParameter] string $directory,
        PaperReplayCheckpoint $checkpoint,
        array $expectedDatasetIdentity,
        bool $requirePrivateDataset,
    ): void {
        try {
            if (!$this->filesystem->sync(
                $directoryPin['handle'],
                'paper_replay_checkpoint_directory_sync',
            )) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_uncertain');
            }
            $this->assertPinnedDirectory($directoryPin, $directory);
            $this->assertPinnedFile($lock, $lock['path'], 'paper_replay_checkpoint_lock_failed');
            $visible = $this->load(
                $datasetDirectory,
                $checkpoint->consumerId,
                $expectedDatasetIdentity,
                $requirePrivateDataset,
            );
            $this->assertPinnedDirectory($directoryPin, $directory);
            $this->assertPinnedFile($lock, $lock['path'], 'paper_replay_checkpoint_lock_failed');
            if ($visible === null || $visible->toArray() !== $checkpoint->toArray()) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_uncertain');
            }
        } catch (\Throwable) {
            throw new \RuntimeException('paper_replay_checkpoint_publication_uncertain');
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}}              $directoryPin
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $lock
     */
    private function publishCheckpoint(
        array $directoryPin,
        array $lock,
        #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $path,
        PaperReplayCheckpoint $checkpoint,
        ?PaperReplayCheckpoint $previous,
    ): void {
        $this->assertDestinationIsNotSymlink($path);
        try {
            $temporaryPath = $this->temporaryPath($directory);
        } catch (\Throwable) {
            throw new \RuntimeException('paper_replay_checkpoint_write_failed');
        }
        $handle = $this->filesystem->createPrivateFile($temporaryPath, 'paper_replay_checkpoint_create');
        if ($handle === false) {
            throw new \RuntimeException('paper_replay_checkpoint_write_failed');
        }

        $renamed = false;
        $publicationUncertain = false;
        $temporaryIdentity = null;
        $contents = CanonicalJson::encode($checkpoint->toArray()) . "\n";
        try {
            $this->writeAll($handle, $contents);
            if (!$this->filesystem->flush($handle, 'paper_replay_checkpoint_flush')
                || !$this->filesystem->sync($handle, 'paper_replay_checkpoint_sync')
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_write_failed');
            }
            $temporaryIdentity = $this->assertHandleMatchesPath($handle, $temporaryPath);
            $this->assertPinnedDirectory($directoryPin, $directory);
            $this->assertPinnedFile($lock, $lock['path'], 'paper_replay_checkpoint_lock_failed');
            $this->assertDestinationIsNotSymlink($path);
            $this->assertHandleMatchesPath($handle, $temporaryPath, $temporaryIdentity);
            try {
                $moved = $this->filesystem->move(
                    $temporaryPath,
                    $path,
                    'paper_replay_checkpoint_publish',
                );
            } catch (\Throwable $failure) {
                $publicationUncertain = true;

                throw $failure;
            }
            if (!$moved) {
                throw new \RuntimeException('paper_replay_checkpoint_write_failed');
            }
            $renamed = true;

            $this->assertPinnedDirectory($directoryPin, $directory);
            $this->assertPinnedFile($lock, $lock['path'], 'paper_replay_checkpoint_lock_failed');
            if (!$this->publishedCheckpointMatches($handle, $path, $temporaryIdentity, $contents)) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_failed');
            }
            if (!$this->filesystem->sync(
                $directoryPin['handle'],
                'paper_replay_checkpoint_directory_sync',
            )) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_uncertain');
            }
            $this->assertPinnedDirectory($directoryPin, $directory);
            $this->assertPinnedFile($lock, $lock['path'], 'paper_replay_checkpoint_lock_failed');
            if (!$this->publishedCheckpointMatches($handle, $path, $temporaryIdentity, $contents)) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_failed');
            }
        } catch (\Throwable $failure) {
            if ($publicationUncertain) {
                try {
                    $this->removePathEntrySafely($temporaryPath);
                } catch (\Throwable) {
                }

                throw new \RuntimeException('paper_replay_checkpoint_publication_uncertain');
            }
            if (!$renamed) {
                $this->removePathEntrySafely($temporaryPath);
                if ($failure instanceof \RuntimeException
                    && in_array($failure->getMessage(), [
                        'paper_replay_checkpoint_symlink_rejected',
                        'paper_replay_checkpoint_regression',
                    ], true)
                ) {
                    throw $failure;
                }

                throw new \RuntimeException('paper_replay_checkpoint_write_failed');
            }

            if ($temporaryIdentity !== null
                && $this->publishedCheckpointMatches($handle, $path, $temporaryIdentity, $contents)
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_uncertain');
            }
            if ($this->restorePreviousCheckpoint($directoryPin, $directory, $path, $previous)) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_failed');
            }
            if ($this->discardVisibleDestination($directory, $path)) {
                throw new \RuntimeException('paper_replay_checkpoint_publication_failed');
            }

            throw new \RuntimeException('paper_replay_checkpoint_publication_uncertain');
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}} $directoryPin
     */
    private function restorePreviousCheckpoint(
        array $directoryPin,
        #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $path,
        ?PaperReplayCheckpoint $previous,
    ): bool {
        try {
            $this->assertPinnedDirectory($directoryPin, $directory);
            if ($previous === null) {
                if (!$this->removePathEntrySafely($path)
                    || !$this->filesystem->sync(
                        $directoryPin['handle'],
                        'paper_replay_checkpoint_directory_sync',
                    )
                ) {
                    return false;
                }
                $this->assertPinnedDirectory($directoryPin, $directory);
                $remaining = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_validation');

                return $remaining === false && !file_exists($path) && !is_link($path);
            }

            try {
                $temporaryPath = $this->temporaryPath($directory);
            } catch (\Throwable) {
                return false;
            }
            $handle = $this->filesystem->createPrivateFile($temporaryPath, 'paper_replay_checkpoint_restore');
            if ($handle === false) {
                return false;
            }
            try {
                $contents = CanonicalJson::encode($previous->toArray()) . "\n";
                $this->writeAll($handle, $contents);
                if (!$this->filesystem->flush($handle, 'paper_replay_checkpoint_restore')
                    || !$this->filesystem->sync($handle, 'paper_replay_checkpoint_restore')
                ) {
                    return false;
                }
                $temporaryIdentity = $this->assertHandleMatchesPath($handle, $temporaryPath);
                $this->assertPinnedDirectory($directoryPin, $directory);
                if (!$this->filesystem->move($temporaryPath, $path, 'paper_replay_checkpoint_restore')) {
                    return false;
                }
                if (!$this->publishedCheckpointMatches($handle, $path, $temporaryIdentity, $contents)
                    || !$this->filesystem->sync(
                        $directoryPin['handle'],
                        'paper_replay_checkpoint_directory_sync',
                    )
                ) {
                    return false;
                }
                $this->assertPinnedDirectory($directoryPin, $directory);

                return $this->publishedCheckpointMatches($handle, $path, $temporaryIdentity, $contents);
            } finally {
                $this->removePathEntrySafely($temporaryPath);
                fclose($handle);
            }
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array{dev: int, ino: int}|null $expectedDatasetIdentity */
    public function load(
        #[\SensitiveParameter] string $datasetDirectory,
        string $consumerId,
        ?array $expectedDatasetIdentity = null,
        bool $requirePrivateDataset = false,
    ): ?PaperReplayCheckpoint {
        PaperReplayCheckpoint::assertConsumerId($consumerId);
        $datasetPin = $this->openPinnedDatasetDirectory(
            $datasetDirectory,
            $expectedDatasetIdentity,
            $requirePrivateDataset,
        );
        try {
            $checkpoint = $this->loadPinnedDataset($datasetPin, $consumerId);
            $this->assertPinnedDatasetDirectory($datasetPin);

            return $checkpoint;
        } finally {
            fclose($datasetPin['handle']);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, requirePrivate: bool} $datasetPin
     */
    private function loadPinnedDataset(array $datasetPin, string $consumerId): ?PaperReplayCheckpoint
    {
        $this->assertPinnedDatasetDirectory($datasetPin);
        $checkpointDirectory = $this->checkpointDirectory($datasetPin, create: false);
        if ($checkpointDirectory === null) {
            return null;
        }
        $directory = $checkpointDirectory['path'];

        $directoryPin = $this->openPinnedDirectory($directory, $checkpointDirectory['identity']);
        try {
            $this->assertPinnedDirectory($directoryPin, $directory);
            $path = $directory . DIRECTORY_SEPARATOR . $consumerId . '.json';
            $statistics = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_load');
            $this->assertPinnedDirectory($directoryPin, $directory);
            if ($statistics === false) {
                if (file_exists($path) || is_link($path)) {
                    throw new \RuntimeException('paper_replay_checkpoint_invalid');
                }

                return null;
            }
            if (($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE) {
                throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
            }
            if (!$this->isPrivateRegularFile($statistics)
                || !isset($statistics['size'])
                || !\is_int($statistics['size'])
                || $statistics['size'] <= 0
                || $statistics['size'] > self::MAX_CHECKPOINT_BYTES
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }

            $contents = $this->readSnapshot($directoryPin, $directory, $path, $statistics);
            $this->assertPinnedDirectory($directoryPin, $directory);
            try {
                $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                if (!\is_array($data) || array_is_list($data)) {
                    throw new \InvalidArgumentException();
                }
                /** @var array<string, mixed> $data */
                $checkpoint = PaperReplayCheckpoint::fromArray($data);
                if (CanonicalJson::encode($checkpoint->toArray()) . "\n" !== $contents) {
                    throw new \InvalidArgumentException();
                }
            } catch (\Throwable) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }

            if (!hash_equals($consumerId, $checkpoint->consumerId)) {
                throw new \RuntimeException('paper_replay_checkpoint_mismatch');
            }

            return $checkpoint;
        } finally {
            fclose($directoryPin['handle']);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, requirePrivate: bool} $datasetPin
     *
     * @return array{path: string, identity: array{dev: int, ino: int}}|null
     */
    private function checkpointDirectory(
        array $datasetPin,
        bool $create,
    ): ?array {
        $this->assertPinnedDatasetDirectory($datasetPin);
        $resolved = $datasetPin['path'];
        $directory = $resolved . DIRECTORY_SEPARATOR . 'checkpoints';
        $statistics = $this->filesystem->pathStat($directory, 'paper_replay_checkpoint_directory');
        $this->assertPinnedDatasetDirectory($datasetPin);
        if ($statistics === false) {
            if (!$create) {
                if (file_exists($directory) || is_link($directory)) {
                    throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
                }

                return null;
            }
            $this->assertPinnedDatasetDirectory($datasetPin);
            $this->filesystem->createDirectory($directory, 0700);
            $this->assertPinnedDatasetDirectory($datasetPin);
            $statistics = $this->filesystem->pathStat($directory, 'paper_replay_checkpoint_directory');
            $this->assertPinnedDatasetDirectory($datasetPin);
            if ($statistics === false || !$this->isPrivateDirectory($statistics)) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
            }
        }
        if (($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE) {
            throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
        }
        if (!$this->isPrivateDirectory($statistics)) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
        if (!isset($statistics['dev'], $statistics['ino'])
            || !\is_int($statistics['dev'])
            || !\is_int($statistics['ino'])
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
        $identity = ['dev' => $statistics['dev'], 'ino' => $statistics['ino']];
        if ($create) {
            $this->syncCheckpointDirectoryParent($datasetPin, $directory, $identity);
        }
        $this->assertPinnedDatasetDirectory($datasetPin);

        return ['path' => $directory, 'identity' => $identity];
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, requirePrivate: bool} $datasetPin
     * @param array{dev: int, ino: int}                                                    $expectedDirectoryIdentity
     */
    private function syncCheckpointDirectoryParent(
        array $datasetPin,
        #[\SensitiveParameter] string $directory,
        array $expectedDirectoryIdentity,
    ): void {
        $directoryPin = $this->openPinnedDirectory($directory, $expectedDirectoryIdentity);
        try {
            $this->assertPinnedDatasetDirectory($datasetPin);
            $this->assertPinnedDirectory($directoryPin, $directory);
            if (!$this->filesystem->sync(
                $datasetPin['handle'],
                'paper_replay_checkpoint_directory_parent_sync',
            )) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
            }
            $this->assertPinnedDatasetDirectory($datasetPin);
            $this->assertPinnedDirectory($directoryPin, $directory);
        } finally {
            fclose($directoryPin['handle']);
        }
    }

    private function assertDestinationIsNotSymlink(#[\SensitiveParameter] string $path): void
    {
        $statistics = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_destination');
        if ($statistics !== false
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
        }
        if ($statistics !== false
            && ($statistics['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE_TYPE
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_write_failed');
        }
    }

    private function temporaryPath(#[\SensitiveParameter] string $directory): string
    {
        return $directory . DIRECTORY_SEPARATOR . '.paper-replay-checkpoint-' . bin2hex(random_bytes(16));
    }

    /** @param resource $handle */
    private function writeAll($handle, #[\SensitiveParameter] string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = $this->filesystem->write(
                $handle,
                substr($contents, $offset),
                'paper_replay_checkpoint_write',
            );
            if ($written === false || $written <= 0) {
                throw new \RuntimeException('paper_replay_checkpoint_write_failed');
            }
            $offset += $written;
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}} $directoryPin
     * @param array<string, mixed>                                        $expectedStatistics
     */
    private function readSnapshot(
        array $directoryPin,
        #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $path,
        array $expectedStatistics,
    ): string {
        $this->assertPinnedDirectory($directoryPin, $directory);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_replay_checkpoint_invalid');
        }
        try {
            $this->assertPinnedDirectory($directoryPin, $directory);
            $opened = $this->filesystem->stat($handle, 'paper_replay_checkpoint_load');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($expectedStatistics, $opened)
                || !isset($opened['size'])
                || !\is_int($opened['size'])
                || $opened['size'] <= 0
                || $opened['size'] > self::MAX_CHECKPOINT_BYTES
                || !isset($expectedStatistics['size'])
                || !\is_int($expectedStatistics['size'])
                || $opened['size'] !== $expectedStatistics['size']
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }
            $contents = '';
            while (strlen($contents) < $opened['size']) {
                $remaining = $opened['size'] - strlen($contents);
                $chunk = $this->filesystem->read(
                    $handle,
                    min(8192, $remaining),
                    'paper_replay_checkpoint_load',
                );
                if ($chunk === false || $chunk === '') {
                    throw new \RuntimeException('paper_replay_checkpoint_invalid');
                }
                $contents .= $chunk;
            }
            $extra = $this->filesystem->read($handle, 1, 'paper_replay_checkpoint_load');
            if ($extra === false || $extra !== '') {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }
            $afterRead = $this->filesystem->stat($handle, 'paper_replay_checkpoint_load');
            if (!$this->sameCheckpointSnapshot($opened, $afterRead)) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }
            $this->assertPinnedDirectory($directoryPin, $directory);
            $current = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_load');
            $this->assertPinnedDirectory($directoryPin, $directory);
            if (!$this->sameCheckpointSnapshot($opened, $current)) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }
            $final = $this->filesystem->stat($handle, 'paper_replay_checkpoint_load');
            if (!$this->sameCheckpointSnapshot($opened, $final)) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }
            $this->assertPinnedDirectory($directoryPin, $directory);

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}} $directoryPin
     *
     * @return array{handle: resource, identity: array{dev: int, ino: int}, path: string}
     */
    private function acquireConsumerLock(
        array $directoryPin,
        #[\SensitiveParameter] string $directory,
        string $consumerId,
    ): array {
        $path = $directory . DIRECTORY_SEPARATOR . '.paper-replay-checkpoint-' . $consumerId . '.lock';
        $this->assertPinnedDirectory($directoryPin, $directory);
        $statistics = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_lock_validation');
        if ($statistics !== false && $this->isSymlink($statistics)) {
            throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
        }
        if ($statistics !== false && !$this->isPrivateRegularFile($statistics)) {
            throw new \RuntimeException('paper_replay_checkpoint_lock_failed');
        }

        $handle = $statistics === false
            ? $this->filesystem->createPrivateFile($path, 'paper_replay_checkpoint_lock_create')
            : @fopen($path, 'r+b');
        if ($handle === false && $statistics === false) {
            $statistics = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_lock_validation');
            if ($statistics !== false && $this->isSymlink($statistics)) {
                throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
            }
            if ($statistics !== false && $this->isPrivateRegularFile($statistics)) {
                $handle = @fopen($path, 'r+b');
            }
        }
        if ($handle === false) {
            throw new \RuntimeException('paper_replay_checkpoint_lock_failed');
        }

        try {
            $identity = $this->assertFileHandleMatchesPath(
                $handle,
                $path,
                null,
                'paper_replay_checkpoint_lock_failed',
                'paper_replay_checkpoint_lock_validation',
            );
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('paper_replay_checkpoint_lock_failed');
            }
            $pin = ['handle' => $handle, 'identity' => $identity, 'path' => $path];
            $this->assertPinnedDirectory($directoryPin, $directory);
            $this->assertPinnedFile($pin, $path, 'paper_replay_checkpoint_lock_failed');

            return $pin;
        } catch (\Throwable $failure) {
            fclose($handle);
            if ($failure instanceof \RuntimeException
                && $failure->getMessage() === 'paper_replay_checkpoint_symlink_rejected'
            ) {
                throw $failure;
            }

            throw new \RuntimeException('paper_replay_checkpoint_lock_failed');
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $pin
     */
    private function assertPinnedFile(array $pin, #[\SensitiveParameter] string $path, string $error): void
    {
        $this->assertFileHandleMatchesPath(
            $pin['handle'],
            $path,
            $pin['identity'],
            $error,
            'paper_replay_checkpoint_lock_validation',
        );
    }

    /**
     * @param resource                     $handle
     * @param array{dev: int, ino: int} $expectedIdentity
     */
    private function publishedCheckpointMatches(
        $handle,
        #[\SensitiveParameter] string $path,
        array $expectedIdentity,
        #[\SensitiveParameter] string $expectedContents,
    ): bool {
        try {
            $opened = $this->filesystem->stat($handle, 'paper_replay_checkpoint_publication_validation');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($expectedIdentity, $opened)
                || !isset($opened['size'])
                || !\is_int($opened['size'])
                || $opened['size'] !== strlen($expectedContents)
                || $opened['size'] <= 0
                || $opened['size'] > self::MAX_CHECKPOINT_BYTES
                || !$this->filesystem->seek(
                    $handle,
                    0,
                    SEEK_SET,
                    'paper_replay_checkpoint_publication_validation',
                )
            ) {
                return false;
            }
            $current = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_publication_validation');
            if (!$this->sameCheckpointSnapshot($opened, $current)) {
                return false;
            }

            $actual = '';
            while (strlen($actual) < $opened['size']) {
                $chunk = $this->filesystem->read(
                    $handle,
                    min(8192, $opened['size'] - strlen($actual)),
                    'paper_replay_checkpoint_publication_validation',
                );
                if ($chunk === false || $chunk === '') {
                    return false;
                }
                $actual .= $chunk;
            }
            $extra = $this->filesystem->read($handle, 1, 'paper_replay_checkpoint_publication_validation');
            if ($extra === false || $extra !== '' || !hash_equals($expectedContents, $actual)) {
                return false;
            }

            $afterRead = $this->filesystem->stat($handle, 'paper_replay_checkpoint_publication_validation');
            $afterPath = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_publication_validation');
            $final = $this->filesystem->stat($handle, 'paper_replay_checkpoint_publication_validation');

            return $this->sameCheckpointSnapshot($opened, $afterRead)
                && $this->sameCheckpointSnapshot($opened, $afterPath)
                && $this->sameCheckpointSnapshot($opened, $final);
        } catch (\Throwable) {
            return false;
        }
    }

    private function removePathEntrySafely(#[\SensitiveParameter] string $path): bool
    {
        $statistics = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_cleanup');
        if ($statistics === false) {
            return !file_exists($path) && !is_link($path);
        }
        if (!isset($statistics['mode']) || !\is_int($statistics['mode'])) {
            return false;
        }
        $type = $statistics['mode'] & self::FILE_TYPE_MASK;
        if (!in_array($type, [self::REGULAR_FILE_TYPE, self::SYMLINK_FILE_TYPE], true)
            || !@unlink($path)
        ) {
            return false;
        }
        $after = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_cleanup');

        return $after === false && !file_exists($path) && !is_link($path);
    }

    private function discardVisibleDestination(
        #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $path,
    ): bool {
        if (!$this->removePathEntrySafely($path)) {
            return false;
        }

        try {
            $pin = $this->openPinnedDirectory($directory);
            try {
                if (!$this->filesystem->sync(
                    $pin['handle'],
                    'paper_replay_checkpoint_directory_sync',
                )) {
                    return false;
                }
                $this->assertPinnedDirectory($pin, $directory);
                $current = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_cleanup');

                return $current === false && !file_exists($path) && !is_link($path);
            } finally {
                fclose($pin['handle']);
            }
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array{dev: int, ino: int}|null $expectedIdentity
     *
     * @return array{handle: resource, identity: array{dev: int, ino: int}, path: string, requirePrivate: bool}
     */
    private function openPinnedDatasetDirectory(
        #[\SensitiveParameter] string $directory,
        ?array $expectedIdentity = null,
        bool $requirePrivate = false,
    ): array {
        $this->assertNoSymlinkComponents($directory);
        $before = $this->filesystem->pathStat(
            $directory,
            'paper_replay_checkpoint_dataset_directory_validation',
        );
        if ($before !== false && $this->isSymlink($before)) {
            throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
        }
        if ($before === false || !$this->isAcceptedDatasetDirectory($before, $requirePrivate)) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }

        $handle = $this->filesystem->openDirectory(
            $directory,
            'paper_replay_checkpoint_dataset_directory_validation',
        );
        if ($handle === false) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }

        try {
            $opened = $this->filesystem->stat(
                $handle,
                'paper_replay_checkpoint_dataset_directory_validation',
            );
            if ($opened === false
                || !$this->isAcceptedDatasetDirectory($opened, $requirePrivate)
                || !$this->sameFile($before, $opened)
                || ($expectedIdentity !== null && !$this->sameFile($expectedIdentity, $opened))
                || !isset($opened['dev'], $opened['ino'])
                || !\is_int($opened['dev'])
                || !\is_int($opened['ino'])
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
            }
            $resolved = realpath($directory);
            if ($resolved === false) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
            }
            $pin = [
                'handle' => $handle,
                'identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']],
                'path' => $resolved,
                'requirePrivate' => $requirePrivate,
            ];
            $this->assertPinnedDatasetDirectory($pin);

            return $pin;
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, requirePrivate: bool} $pin */
    private function assertPinnedDatasetDirectory(array $pin): void
    {
        $opened = $this->filesystem->stat(
            $pin['handle'],
            'paper_replay_checkpoint_dataset_directory_validation',
        );
        $current = $this->filesystem->pathStat(
            $pin['path'],
            'paper_replay_checkpoint_dataset_directory_validation',
        );
        if ($current !== false && $this->isSymlink($current)) {
            throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
        }
        if ($opened === false
            || $current === false
            || !$this->isAcceptedDatasetDirectory($opened, $pin['requirePrivate'])
            || !$this->isAcceptedDatasetDirectory($current, $pin['requirePrivate'])
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
    }

    /**
     * @param array{dev: int, ino: int}|null $expectedIdentity
     *
     * @return array{handle: resource, identity: array{dev: int, ino: int}}
     */
    private function openPinnedDirectory(
        #[\SensitiveParameter] string $directory,
        ?array $expectedIdentity = null,
    ): array {
        $handle = $this->filesystem->openDirectory($directory, 'paper_replay_checkpoint_directory_sync');
        if ($handle === false) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
        try {
            $statistics = $this->filesystem->stat($handle, 'paper_replay_checkpoint_directory_validation');
            if ($statistics === false
                || !$this->isPrivateDirectory($statistics)
                || !isset($statistics['dev'], $statistics['ino'])
                || !\is_int($statistics['dev'])
                || !\is_int($statistics['ino'])
                || ($expectedIdentity !== null && !$this->sameFile($expectedIdentity, $statistics))
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
            }
            $pin = ['handle' => $handle, 'identity' => ['dev' => $statistics['dev'], 'ino' => $statistics['ino']]];
            $this->assertPinnedDirectory($pin, $directory);

            return $pin;
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}} $pin */
    private function assertPinnedDirectory(array $pin, #[\SensitiveParameter] string $directory): void
    {
        $opened = $this->filesystem->stat($pin['handle'], 'paper_replay_checkpoint_directory_validation');
        $current = $this->filesystem->pathStat($directory, 'paper_replay_checkpoint_directory_validation');
        if ($opened === false
            || $current === false
            || !$this->isPrivateDirectory($opened)
            || !$this->isPrivateDirectory($current)
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
    }

    /**
     * @param resource                          $handle
     * @param array{dev: int, ino: int}|null $expectedIdentity
     *
     * @return array{dev: int, ino: int}
     */
    private function assertHandleMatchesPath(
        $handle,
        #[\SensitiveParameter] string $path,
        ?array $expectedIdentity = null,
    ): array {
        return $this->assertFileHandleMatchesPath(
            $handle,
            $path,
            $expectedIdentity,
            'paper_replay_checkpoint_write_failed',
            'paper_replay_checkpoint_validation',
        );
    }

    /**
     * @param resource                          $handle
     * @param array{dev: int, ino: int}|null $expectedIdentity
     *
     * @return array{dev: int, ino: int}
     */
    private function assertFileHandleMatchesPath(
        $handle,
        #[\SensitiveParameter] string $path,
        ?array $expectedIdentity,
        string $error,
        string $operation,
    ): array {
        $opened = $this->filesystem->stat($handle, $operation);
        $current = $this->filesystem->pathStat($path, $operation);
        if ($current !== false && $this->isSymlink($current)) {
            throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
        }
        if ($opened === false
            || $current === false
            || !$this->isPrivateRegularFile($opened)
            || !$this->isPrivateRegularFile($current)
            || !$this->sameFile($opened, $current)
            || ($expectedIdentity !== null && !$this->sameFile($expectedIdentity, $opened))
            || !isset($opened['dev'], $opened['ino'])
            || !\is_int($opened['dev'])
            || !\is_int($opened['ino'])
        ) {
            throw new \RuntimeException($error);
        }

        return ['dev' => $opened['dev'], 'ino' => $opened['ino']];
    }

    /** @param array<string, mixed> $statistics */
    private function isSymlink(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateRegularFile(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE_TYPE
            && ($statistics['mode'] & 0777) === 0600
            && isset($statistics['nlink'])
            && \is_int($statistics['nlink'])
            && $statistics['nlink'] === 1;
    }

    /**
     * @param array<string, mixed>       $expected
     * @param array<string, mixed>|false $actual
     */
    private function sameCheckpointSnapshot(array $expected, array|false $actual): bool
    {
        return $actual !== false
            && $this->isPrivateRegularFile($actual)
            && $this->sameFile($expected, $actual)
            && isset($expected['size'], $actual['size'])
            && \is_int($expected['size'])
            && \is_int($actual['size'])
            && $expected['size'] > 0
            && $expected['size'] <= self::MAX_CHECKPOINT_BYTES
            && $actual['size'] === $expected['size'];
    }

    /** @param array<string, mixed> $statistics */
    private function isDirectory(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE;
    }

    /** @param array<string, mixed> $statistics */
    private function isAcceptedDatasetDirectory(array $statistics, bool $requirePrivate): bool
    {
        return $requirePrivate
            ? $this->isPrivateDirectory($statistics)
            : $this->isDirectory($statistics);
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateDirectory(array $statistics): bool
    {
        return $this->isDirectory($statistics)
            && isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & 0777) === 0700;
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

    private function assertNoSymlinkComponents(#[\SensitiveParameter] string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
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
                throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
            }
        }
    }
}
