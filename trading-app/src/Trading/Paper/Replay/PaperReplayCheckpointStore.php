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
        $directory = $this->checkpointDirectory($datasetDirectory, create: true);
        $path = $directory . DIRECTORY_SEPARATOR . $checkpoint->consumerId . '.json';
        $directoryPin = $this->openPinnedDirectory($directory);

        try {
            $this->assertPinnedDirectory($directoryPin, $directory);
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

            $published = false;
            try {
                $contents = CanonicalJson::encode($checkpoint->toArray()) . "\n";
                $this->writeAll($handle, $contents);
                if (!$this->filesystem->flush($handle, 'paper_replay_checkpoint_flush')
                    || !$this->filesystem->sync($handle, 'paper_replay_checkpoint_sync')
                ) {
                    throw new \RuntimeException('paper_replay_checkpoint_write_failed');
                }
                $temporaryIdentity = $this->assertHandleMatchesPath($handle, $temporaryPath);
                $this->assertPinnedDirectory($directoryPin, $directory);
                $this->assertDestinationIsNotSymlink($path);
                if (!$this->filesystem->move($temporaryPath, $path, 'paper_replay_checkpoint_publish')) {
                    throw new \RuntimeException('paper_replay_checkpoint_write_failed');
                }
                $published = true;
                $this->assertHandleMatchesPath($handle, $path, $temporaryIdentity);
                $this->syncPinnedDirectory($directoryPin, $directory);
            } catch (\Throwable $failure) {
                if (!$published && !is_link($temporaryPath) && is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
                if ($failure instanceof \RuntimeException
                    && $failure->getMessage() === 'paper_replay_checkpoint_symlink_rejected'
                ) {
                    throw $failure;
                }

                throw new \RuntimeException('paper_replay_checkpoint_write_failed');
            } finally {
                fclose($handle);
            }
        } finally {
            fclose($directoryPin['handle']);
        }
    }

    public function load(
        #[\SensitiveParameter] string $datasetDirectory,
        string $consumerId,
    ): ?PaperReplayCheckpoint {
        PaperReplayCheckpoint::assertConsumerId($consumerId);
        $directory = $this->checkpointDirectory($datasetDirectory, create: false);
        if ($directory === null) {
            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $consumerId . '.json';
        $statistics = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_load');
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

        $contents = $this->readSnapshot($path, $statistics);
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
    }

    private function checkpointDirectory(
        #[\SensitiveParameter] string $datasetDirectory,
        bool $create,
    ): ?string {
        $this->assertNoSymlinkComponents($datasetDirectory);
        $resolved = realpath($datasetDirectory);
        if ($resolved === false || !is_dir($resolved)) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }

        $directory = $resolved . DIRECTORY_SEPARATOR . 'checkpoints';
        $statistics = $this->filesystem->pathStat($directory, 'paper_replay_checkpoint_directory');
        if ($statistics === false) {
            if (file_exists($directory) || is_link($directory)) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
            }
            if (!$create) {
                return null;
            }
            if (!$this->filesystem->createDirectory($directory, 0700)) {
                throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
            }
            $statistics = $this->filesystem->pathStat($directory, 'paper_replay_checkpoint_directory');
        }
        if ($statistics === false) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
        if (($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE) {
            throw new \RuntimeException('paper_replay_checkpoint_symlink_rejected');
        }
        if (($statistics['mode'] & self::FILE_TYPE_MASK) !== self::DIRECTORY_FILE_TYPE) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }

        return $directory;
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
     * @param array<string, mixed> $expectedStatistics
     */
    private function readSnapshot(
        #[\SensitiveParameter] string $path,
        array $expectedStatistics,
    ): string {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_replay_checkpoint_invalid');
        }
        try {
            $opened = $this->filesystem->stat($handle, 'paper_replay_checkpoint_load');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($expectedStatistics, $opened)
                || !isset($opened['size'])
                || !\is_int($opened['size'])
            ) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }
            $contents = stream_get_contents($handle, $opened['size']);
            if ($contents === false || strlen($contents) !== $opened['size']) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }
            $current = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_load');
            if ($current === false || !$this->sameFile($opened, $current)) {
                throw new \RuntimeException('paper_replay_checkpoint_invalid');
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /** @return array{handle: resource, identity: array{dev: int, ino: int}} */
    private function openPinnedDirectory(#[\SensitiveParameter] string $directory): array
    {
        $handle = $this->filesystem->openDirectory($directory, 'paper_replay_checkpoint_directory_sync');
        if ($handle === false) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
        try {
            $statistics = $this->filesystem->stat($handle, 'paper_replay_checkpoint_directory_validation');
            if ($statistics === false
                || !$this->isDirectory($statistics)
                || !isset($statistics['dev'], $statistics['ino'])
                || !\is_int($statistics['dev'])
                || !\is_int($statistics['ino'])
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
            || !$this->isDirectory($opened)
            || !$this->isDirectory($current)
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw new \RuntimeException('paper_replay_checkpoint_directory_invalid');
        }
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}} $pin */
    private function syncPinnedDirectory(array $pin, #[\SensitiveParameter] string $directory): void
    {
        $this->assertPinnedDirectory($pin, $directory);
        if (!$this->filesystem->sync($pin['handle'], 'paper_replay_checkpoint_directory_sync')) {
            throw new \RuntimeException('paper_replay_checkpoint_write_failed');
        }
        $this->assertPinnedDirectory($pin, $directory);
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
        $opened = $this->filesystem->stat($handle, 'paper_replay_checkpoint_validation');
        $current = $this->filesystem->pathStat($path, 'paper_replay_checkpoint_validation');
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
            throw new \RuntimeException('paper_replay_checkpoint_write_failed');
        }

        return ['dev' => $opened['dev'], 'ino' => $opened['ino']];
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
    private function isDirectory(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE;
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
