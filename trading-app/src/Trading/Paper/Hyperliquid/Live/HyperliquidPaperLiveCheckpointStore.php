<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;

final class HyperliquidPaperLiveCheckpointStore
{
    private const CHECKPOINT_FILENAME = 'hyperliquid-live.json';
    private const TEMPORARY_PATTERN = '/\A\.hyperliquid-live-[a-f0-9]{32}\.tmp\z/D';
    private const FILE_TYPE_MASK = 0170000;
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;

    private readonly PaperDatasetRecorderFilesystem $filesystem;
    private readonly string $checkpointDirectory;
    private readonly string $checkpointPath;

    private ?string $datasetId = null;
    private ?PaperMarketDataNetwork $network = null;
    private ?string $configurationSha256 = null;

    public function __construct(
        #[\SensitiveParameter] string $datasetDirectory,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
    ) {
        $this->filesystem = $filesystem ?? new PaperDatasetRecorderFilesystem();
        $this->assertNoSymlinkComponents($datasetDirectory);
        $resolved = realpath($datasetDirectory);
        if ($resolved === false) {
            throw self::invalid();
        }
        $statistics = $this->statistics($resolved);
        if (!$this->isDirectory($statistics)) {
            throw self::invalid();
        }
        $this->checkpointDirectory = $resolved . '/checkpoints';
        $this->ensureCheckpointDirectory();
        $this->checkpointPath = $this->checkpointDirectory . '/' . self::CHECKPOINT_FILENAME;
        $this->cleanupTemporaryFiles();
    }

    public function loadOrCreate(
        string $datasetId,
        PaperMarketDataNetwork $network,
        string $configurationSha256,
    ): HyperliquidPaperLiveCheckpoint {
        try {
            PaperDatasetManifest::assertDatasetId($datasetId);
            if (!$network->isCertifiable()
                || preg_match('/\A[a-f0-9]{64}\z/D', $configurationSha256) !== 1
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
        if (($this->datasetId !== null && !hash_equals($this->datasetId, $datasetId))
            || ($this->network !== null && $this->network !== $network)
            || ($this->configurationSha256 !== null
                && !hash_equals($this->configurationSha256, $configurationSha256))
        ) {
            throw self::invalid();
        }
        $this->datasetId = $datasetId;
        $this->network = $network;
        $this->configurationSha256 = $configurationSha256;

        $statistics = $this->statistics($this->checkpointPath);
        if ($statistics === false) {
            $checkpoint = HyperliquidPaperLiveCheckpoint::fresh(
                $datasetId,
                $network,
                $configurationSha256,
            );
            $this->persist($checkpoint);

            return $checkpoint;
        }

        $checkpoint = $this->read();
        if (!hash_equals($datasetId, $checkpoint->datasetId)
            || $checkpoint->network !== $network
            || !hash_equals($configurationSha256, $checkpoint->configurationSha256)
        ) {
            throw self::invalid();
        }

        return $checkpoint;
    }

    public function save(
        #[\SensitiveParameter] HyperliquidPaperLiveCheckpoint $checkpoint,
    ): HyperliquidPaperLiveCheckpoint {
        if ($this->datasetId === null
            || !hash_equals($this->datasetId, $checkpoint->datasetId)
            || $this->network !== $checkpoint->network
            || $this->configurationSha256 === null
            || !hash_equals(
                $this->configurationSha256,
                $checkpoint->configurationSha256,
            )
        ) {
            throw self::invalid();
        }
        $this->persist($checkpoint);

        return $checkpoint;
    }

    /** @param array<string, mixed> $continuation */
    public function savePending(
        HyperliquidPaperLiveCheckpoint $checkpoint,
        \App\Trading\Paper\MarketData\PaperMarketEvent $event,
        #[\SensitiveParameter] array $continuation,
    ): HyperliquidPaperLiveCheckpoint {
        return $this->save($checkpoint->withPending($event, $continuation));
    }

    public function acknowledge(
        HyperliquidPaperLiveCheckpoint $checkpoint,
        string $eventId,
    ): HyperliquidPaperLiveCheckpoint {
        return $this->save($checkpoint->acknowledge($eventId));
    }

    private function read(): HyperliquidPaperLiveCheckpoint
    {
        $statistics = $this->statistics($this->checkpointPath);
        if (!$this->isPrivateRegularFile($statistics)
            || !isset($statistics['size'])
            || !\is_int($statistics['size'])
            || $statistics['size'] < 1
            || $statistics['size'] > HyperliquidPaperLiveCheckpoint::MAXIMUM_BYTES + 256
        ) {
            throw self::invalid();
        }
        $handle = @fopen($this->checkpointPath, 'rb');
        if ($handle === false) {
            throw self::invalid();
        }
        try {
            $opened = $this->filesystem->stat($handle, 'hyperliquid_live_checkpoint_read');
            if (!$this->isPrivateRegularFile($opened)
                || !$this->sameFile($statistics, $opened)
                || $opened['size'] !== $statistics['size']
            ) {
                throw self::invalid();
            }
            $contents = '';
            while (\strlen($contents) < $opened['size']) {
                $chunk = $this->filesystem->read(
                    $handle,
                    min(8192, $opened['size'] - \strlen($contents)),
                    'hyperliquid_live_checkpoint_read',
                );
                if ($chunk === false || $chunk === '') {
                    throw self::invalid();
                }
                $contents .= $chunk;
            }
            if ($this->filesystem->read($handle, 1, 'hyperliquid_live_checkpoint_read') !== '') {
                throw self::invalid();
            }
        } finally {
            fclose($handle);
        }

        try {
            $document = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($document) || array_is_list($document)) {
                throw new \InvalidArgumentException();
            }
            self::assertExactKeys($document, ['sha256', 'state']);
            if (!\is_string($document['sha256'])
                || !\is_array($document['state'])
                || array_is_list($document['state'])
                || !hash_equals(
                    hash('sha256', CanonicalJson::encode($document['state'])),
                    $document['sha256'],
                )
                || CanonicalJson::encode($document) . "\n" !== $contents
            ) {
                throw new \InvalidArgumentException();
            }

            return HyperliquidPaperLiveCheckpoint::fromArray($document['state']);
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    private function persist(HyperliquidPaperLiveCheckpoint $checkpoint): void
    {
        try {
            $state = $checkpoint->toArray();
            $document = [
                'sha256' => hash('sha256', CanonicalJson::encode($state)),
                'state' => $state,
            ];
            $contents = CanonicalJson::encode($document) . "\n";
            if (\strlen($contents) > HyperliquidPaperLiveCheckpoint::MAXIMUM_BYTES + 256) {
                throw new \InvalidArgumentException();
            }
            $this->atomicWrite($contents);
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
    }

    private function atomicWrite(#[\SensitiveParameter] string $contents): void
    {
        $this->ensureCheckpointDirectory();
        $destination = $this->statistics($this->checkpointPath);
        if ($destination !== false && !$this->isPrivateRegularFile($destination)) {
            throw self::invalid();
        }
        try {
            $temporaryPath = $this->checkpointDirectory
                . '/.hyperliquid-live-' . bin2hex(random_bytes(16)) . '.tmp';
        } catch (\Throwable $exception) {
            throw self::invalid($exception);
        }
        $handle = $this->filesystem->createPrivateFile(
            $temporaryPath,
            'hyperliquid_live_checkpoint_create',
        );
        if ($handle === false) {
            throw self::invalid();
        }
        $published = false;
        try {
            $offset = 0;
            while ($offset < \strlen($contents)) {
                $written = $this->filesystem->write(
                    $handle,
                    substr($contents, $offset),
                    'hyperliquid_live_checkpoint_write',
                );
                if ($written === false || $written < 1) {
                    throw self::invalid();
                }
                $offset += $written;
            }
            if (!$this->filesystem->flush($handle, 'hyperliquid_live_checkpoint_flush')
                || !$this->filesystem->sync($handle, 'hyperliquid_live_checkpoint_sync')
                || !$this->filesystem->move(
                    $temporaryPath,
                    $this->checkpointPath,
                    'hyperliquid_live_checkpoint_publish',
                )
            ) {
                throw self::invalid();
            }
            $published = true;
            $directory = $this->filesystem->openDirectory(
                $this->checkpointDirectory,
                'hyperliquid_live_checkpoint_directory',
            );
            if ($directory === false) {
                throw self::invalid();
            }
            try {
                if (!$this->filesystem->sync(
                    $directory,
                    'hyperliquid_live_checkpoint_directory_sync',
                )) {
                    throw self::invalid();
                }
            } finally {
                fclose($directory);
            }
        } finally {
            $temporary = $this->statistics($temporaryPath);
            if (!$published && $this->isPrivateRegularFile($temporary)) {
                $this->filesystem->removeFile(
                    $temporaryPath,
                    $temporary,
                    'hyperliquid_live_checkpoint_cleanup',
                );
            }
            fclose($handle);
        }
        if (!$this->isPrivateRegularFile($this->statistics($this->checkpointPath))) {
            throw self::invalid();
        }
    }

    private function ensureCheckpointDirectory(): void
    {
        $statistics = $this->statistics($this->checkpointDirectory);
        if ($statistics === false) {
            if (!$this->filesystem->createDirectory($this->checkpointDirectory, 0700)) {
                throw self::invalid();
            }
            $statistics = $this->statistics($this->checkpointDirectory);
        }
        if (!$this->isDirectory($statistics)
            || !isset($statistics['mode'])
            || ($statistics['mode'] & 0777) !== 0700
        ) {
            throw self::invalid();
        }
    }

    private function cleanupTemporaryFiles(): void
    {
        $entries = scandir($this->checkpointDirectory);
        if ($entries === false) {
            throw self::invalid();
        }
        foreach ($entries as $entry) {
            if (preg_match(self::TEMPORARY_PATTERN, $entry) !== 1) {
                continue;
            }
            $path = $this->checkpointDirectory . '/' . $entry;
            $statistics = $this->statistics($path);
            if (!$this->isPrivateRegularFile($statistics)
                || !$this->filesystem->removeFile(
                    $path,
                    $statistics,
                    'hyperliquid_live_checkpoint_stale_cleanup',
                )
            ) {
                throw self::invalid();
            }
        }
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw self::invalid();
            }
            $path = $workingDirectory . '/' . $path;
        }
        $current = DIRECTORY_SEPARATOR;
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            $current = rtrim($current, DIRECTORY_SEPARATOR) . '/' . $component;
            $statistics = $this->statistics($current);
            if ($statistics !== false
                && isset($statistics['mode'])
                && ($statistics['mode'] & self::FILE_TYPE_MASK) === 0120000
            ) {
                throw self::invalid();
            }
        }
    }

    /** @return array<string, mixed>|false */
    private function statistics(string $path): array|false
    {
        return $this->filesystem->pathStat($path, 'hyperliquid_live_checkpoint_stat');
    }

    /** @param array<string, mixed>|false $statistics */
    private function isDirectory(array|false $statistics): bool
    {
        return $statistics !== false
            && isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE;
    }

    /** @param array<string, mixed>|false $statistics */
    private function isPrivateRegularFile(array|false $statistics): bool
    {
        return $statistics !== false
            && isset($statistics['mode'], $statistics['nlink'])
            && \is_int($statistics['mode'])
            && \is_int($statistics['nlink'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE_TYPE
            && ($statistics['mode'] & 0777) === 0600
            && $statistics['nlink'] === 1;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }

    /** @param array<string, mixed> $value
     *  @param list<string> $keys
     */
    private static function assertExactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, \SORT_STRING);
        sort($keys, \SORT_STRING);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException();
        }
    }

    private static function invalid(?\Throwable $previous = null): HyperliquidPaperLiveIntegrityException
    {
        return new HyperliquidPaperLiveIntegrityException(
            'hyperliquid_paper_live_checkpoint_invalid',
            0,
            $previous,
        );
    }
}
