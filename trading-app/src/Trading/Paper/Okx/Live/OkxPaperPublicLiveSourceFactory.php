<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\Dataset\PaperDatasetFormatLimits;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperFundingRateClientInterface;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class OkxPaperPublicLiveSourceFactory
{
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const SYMLINK_FILE_TYPE = 0120000;
    private const FILE_TYPE_MASK = 0170000;
    private const MANIFEST_FILENAME = 'manifest.json';
    private const MANIFEST_ERROR = 'okx_paper_public_live_manifest_invalid';

    public function __construct(
        private OkxPaperPublicRestClientInterface $restClient,
        private OkxPaperPublicWebSocketTransportFactoryInterface $transportFactory,
        private OkxPaperPublicConfig $config,
        private ClockInterface $clock,
        private PaperDatasetManifestCodec $manifestCodec,
        private PaperDatasetRecorderFilesystem $filesystem,
        private ?OkxPaperInstrumentMetadataClientInterface $metadataClient = null,
        private ?OkxPaperFundingRateClientInterface $fundingClient = null,
    ) {
    }

    public function create(
        #[\SensitiveParameter] string $datasetDirectory,
        ?LoopInterface $loop = null,
    ): OkxPaperPublicLiveSource {
        [$resolvedDirectory, $manifest, $directoryPin] = $this->readManifest($datasetDirectory);

        try {
            $instruments = new OkxPaperInstrumentMap();
            $subscriptions = new OkxPaperPublicSubscriptionSet($instruments);
            $configurationSha256 = $this->configurationSha256($instruments, $subscriptions);
            $checkpointStore = new OkxPaperLiveCheckpointStore(
                $resolvedDirectory,
                $this->filesystem,
                $this->clock,
            );
            $this->assertPinnedDirectory($directoryPin);
            $checkpoint = $checkpointStore->loadOrCreate(
                $manifest->datasetId,
                $configurationSha256,
            );
            $this->assertPinnedDirectory($directoryPin);
            $sessionLoop = $loop ?? Loop::get();
            $publicTransport = $this->transportFactory->create($sessionLoop);
            $businessTransport = $this->transportFactory->create($sessionLoop);
            $decoder = new OkxPaperPublicFrameDecoder($subscriptions);
            $publicQueue = new OkxPaperPublicFrameQueue();
            $businessQueue = new OkxPaperPublicFrameQueue();

            return new OkxPaperPublicLiveSource(
                restClient: $this->restClient,
                publicTransport: $publicTransport,
                businessTransport: $businessTransport,
                config: $this->config,
                clock: $this->clock,
                checkpointStore: $checkpointStore,
                checkpoint: $checkpoint,
                loop: $sessionLoop,
                instruments: $instruments,
                subscriptions: $subscriptions,
                decoder: $decoder,
                publicQueue: $publicQueue,
                businessQueue: $businessQueue,
                metadataClient: $this->metadataClient,
                fundingClient: $this->fundingClient,
            );
        } finally {
            fclose($directoryPin['handle']);
        }
    }

    /**
     * @return array{
     *     string,
     *     PaperDatasetManifest,
     *     array{handle: resource, identity: array{dev: int, ino: int}, path: string}
     * }
     */
    private function readManifest(
        #[\SensitiveParameter] string $datasetDirectory,
    ): array {
        try {
            if ($datasetDirectory === '' || str_contains($datasetDirectory, "\0")) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
            $this->assertNoSymlinkComponents($datasetDirectory);
            $resolvedDirectory = realpath($datasetDirectory);
            if ($resolvedDirectory === false) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
            $directoryPin = $this->openPinnedDirectory($resolvedDirectory);

            try {
                $manifestPath = $resolvedDirectory . '/' . self::MANIFEST_FILENAME;
                $contents = $this->readPinnedManifest($manifestPath, $directoryPin);
                $manifest = $this->manifestCodec->decode($contents);
                if (!hash_equals($this->manifestCodec->encode($manifest), $contents)
                    || $manifest->state !== PaperDatasetState::RECORDING
                    || $manifest->venue !== PaperMarketDataVenue::OKX
                    || $manifest->symbols !== [
                        'BTCUSDT' => 'BTC-USDT-SWAP',
                        'ETHUSDT' => 'ETH-USDT-SWAP',
                    ]
                    || basename($resolvedDirectory) !== $manifest->datasetId
                ) {
                    throw new \RuntimeException(self::MANIFEST_ERROR);
                }
                $this->assertPinnedDirectory($directoryPin);

                return [$resolvedDirectory, $manifest, $directoryPin];
            } catch (\Throwable $exception) {
                fclose($directoryPin['handle']);

                throw $exception;
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof \RuntimeException
                && $exception->getMessage() === self::MANIFEST_ERROR
            ) {
                throw $exception;
            }

            throw new \RuntimeException(self::MANIFEST_ERROR, 0, $exception);
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $directoryPin
     */
    private function readPinnedManifest(
        #[\SensitiveParameter] string $manifestPath,
        array $directoryPin,
    ): string {
        $this->assertNoSymlinkComponents($manifestPath);
        $before = $this->privateRegularFileStatistics($manifestPath);
        $handle = $this->filesystem->openDirectory(
            $manifestPath,
            'okx_paper_public_live_manifest_open',
        );
        if ($handle === false) {
            throw new \RuntimeException(self::MANIFEST_ERROR);
        }

        try {
            $opened = $this->filesystem->stat(
                $handle,
                'okx_paper_public_live_manifest_validation',
            );
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameFile($before, $opened)
                || !isset($opened['size'])
                || !\is_int($opened['size'])
                || $opened['size'] <= 0
                || $opened['size'] > PaperDatasetFormatLimits::MAX_MANIFEST_BYTES
            ) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }

            $contents = $this->readExactBytes($handle, $opened['size']);
            if (!$this->filesystem->seek(
                $handle,
                0,
                \SEEK_SET,
                'okx_paper_public_live_manifest_seek',
            )) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
            $checksum = $this->filesystem->checksum(
                $handle,
                'okx_paper_public_live_manifest_checksum',
            );
            $final = $this->filesystem->stat(
                $handle,
                'okx_paper_public_live_manifest_validation',
            );
            $current = $this->privateRegularFileStatistics($manifestPath);
            if ($checksum['bytes'] !== $opened['size']
                || !hash_equals(hash('sha256', $contents), $checksum['checksum'])
                || $final === false
                || !$this->isPrivateRegularFile($final)
                || !$this->sameFile($opened, $final)
                || !$this->sameFile($opened, $current)
            ) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
            $this->assertPinnedDirectory($directoryPin);

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function readExactBytes($handle, int $bytes): string
    {
        $remaining = $bytes;
        $contents = '';
        while ($remaining > 0) {
            $chunk = $this->filesystem->read(
                $handle,
                min(8192, $remaining),
                'okx_paper_public_live_manifest_read',
            );
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
            $contents .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $contents;
    }

    /**
     * @return array{handle: resource, identity: array{dev: int, ino: int}, path: string}
     */
    private function openPinnedDirectory(#[\SensitiveParameter] string $directory): array
    {
        $before = $this->privateDirectoryStatistics($directory);
        $handle = $this->filesystem->openDirectory(
            $directory,
            'okx_paper_public_live_dataset_directory_open',
        );
        if ($handle === false) {
            throw new \RuntimeException(self::MANIFEST_ERROR);
        }

        try {
            $opened = $this->filesystem->stat(
                $handle,
                'okx_paper_public_live_dataset_directory_validation',
            );
            if ($opened === false
                || !$this->isPrivateDirectory($opened)
                || !$this->sameFile($before, $opened)
                || !isset($opened['dev'], $opened['ino'])
                || !\is_int($opened['dev'])
                || !\is_int($opened['ino'])
            ) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
            $pin = [
                'handle' => $handle,
                'identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']],
                'path' => $directory,
            ];
            $this->assertPinnedDirectory($pin);

            return $pin;
        } catch (\Throwable $exception) {
            fclose($handle);

            throw $exception;
        }
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $pin
     */
    private function assertPinnedDirectory(array $pin): void
    {
        $opened = $this->filesystem->stat(
            $pin['handle'],
            'okx_paper_public_live_dataset_directory_validation',
        );
        $current = $this->privateDirectoryStatistics($pin['path']);
        if ($opened === false
            || !$this->isPrivateDirectory($opened)
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw new \RuntimeException(self::MANIFEST_ERROR);
        }
    }

    /** @return array<string, mixed> */
    private function privateDirectoryStatistics(#[\SensitiveParameter] string $directory): array
    {
        $statistics = $this->filesystem->pathStat(
            $directory,
            'okx_paper_public_live_dataset_directory_validation',
        );
        if ($statistics === false || !$this->isPrivateDirectory($statistics)) {
            throw new \RuntimeException(self::MANIFEST_ERROR);
        }

        return $statistics;
    }

    /** @return array<string, mixed> */
    private function privateRegularFileStatistics(#[\SensitiveParameter] string $path): array
    {
        $statistics = $this->filesystem->pathStat(
            $path,
            'okx_paper_public_live_manifest_validation',
        );
        if ($statistics === false || !$this->isPrivateRegularFile($statistics)) {
            throw new \RuntimeException(self::MANIFEST_ERROR);
        }

        return $statistics;
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

    private function assertNoSymlinkComponents(#[\SensitiveParameter] string $path): void
    {
        if (!str_starts_with($path, \DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
            $path = $workingDirectory . \DIRECTORY_SEPARATOR . $path;
        }

        $current = \DIRECTORY_SEPARATOR;
        foreach (explode(\DIRECTORY_SEPARATOR, $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                $current = dirname($current);

                continue;
            }

            $current = rtrim($current, \DIRECTORY_SEPARATOR)
                . \DIRECTORY_SEPARATOR
                . $component;
            $statistics = $this->filesystem->pathStat(
                $current,
                'okx_paper_public_live_path_validation',
            );
            if ($statistics !== false
                && isset($statistics['mode'])
                && \is_int($statistics['mode'])
                && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE
            ) {
                throw new \RuntimeException(self::MANIFEST_ERROR);
            }
        }
    }

    private function configurationSha256(
        OkxPaperInstrumentMap $instruments,
        OkxPaperPublicSubscriptionSet $subscriptions,
    ): string {
        $policy = (new \ReflectionClass(OkxPaperLivePolicy::class))->getConstants();
        ksort($policy, \SORT_STRING);

        return hash('sha256', CanonicalJson::encode([
            'business_web_socket_uri' => $this->config->businessWebSocketUri,
            'native_instrument_ids' => $instruments->nativeInstrumentIds(),
            'policy' => $policy,
            'public_subscriptions' => $subscriptions->publicArguments(),
            'public_web_socket_uri' => $this->config->webSocketUri,
            'business_subscriptions' => $subscriptions->businessArguments(),
            'rest_base_uri' => $this->config->restBaseUri,
            'venue' => PaperMarketDataVenue::OKX->value,
        ]));
    }
}
