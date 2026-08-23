<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Capture\PaperPublicLiveSourceFactoryInterface;
use App\Trading\Paper\Dataset\PaperDatasetFormatLimits;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfigFactory;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperFundingRateClientInterface;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class HyperliquidPaperPublicLiveSourceFactory implements PaperPublicLiveSourceFactoryInterface
{
    private const MANIFEST_FILENAME = 'manifest.json';
    private const MANIFEST_ERROR = 'hyperliquid_paper_public_live_manifest_invalid';
    private const FILE_TYPE_MASK = 0170000;
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const SYMLINK_FILE_TYPE = 0120000;

    public function __construct(
        private HyperliquidPaperPublicConfigFactory $configFactory,
        private HyperliquidPaperPublicWebSocketTransportFactoryInterface $transportFactory,
        private ClockInterface $clock,
        private PaperDatasetManifestCodec $manifestCodec,
        private PaperDatasetRecorderFilesystem $filesystem,
        private ?HyperliquidPaperInstrumentMetadataClientInterface $metadataClient = null,
        private ?HyperliquidPaperFundingRateClientInterface $fundingClient = null,
    ) {
    }

    public function create(
        #[\SensitiveParameter] string $datasetDirectory,
        ?LoopInterface $loop = null,
    ): HyperliquidPaperPublicLiveSource {
        [$resolved, $manifest, $directoryHandle, $directoryIdentity]
            = $this->readManifest($datasetDirectory);
        try {
            $config = $this->configFactory->create($manifest->network->value);
            $subscriptions = new HyperliquidPaperPublicSubscriptionSet();
            $configurationSha256 = HyperliquidPaperLivePolicy::configurationSha256(
                $config->network,
            );
            $checkpointStore = new HyperliquidPaperLiveCheckpointStore(
                $resolved,
                $this->filesystem,
            );
            $this->assertPinnedDirectory(
                $resolved,
                $directoryHandle,
                $directoryIdentity,
            );
            $checkpoint = $checkpointStore->loadOrCreate(
                $manifest->datasetId,
                $config->network,
                $configurationSha256,
            );
            $this->assertPinnedDirectory(
                $resolved,
                $directoryHandle,
                $directoryIdentity,
            );
            $sessionLoop = $loop ?? Loop::get();
            $transport = $this->transportFactory->create($sessionLoop, $config);

            return new HyperliquidPaperPublicLiveSource(
                transport: $transport,
                metadataClient: $this->metadataClient,
                fundingClient: $this->fundingClient,
                config: $config,
                clock: $this->clock,
                checkpointStore: $checkpointStore,
                checkpoint: $checkpoint,
                loop: $sessionLoop,
                subscriptions: $subscriptions,
                decoder: new HyperliquidPaperPublicFrameDecoder($subscriptions),
                queue: new HyperliquidPaperPublicFrameQueue(),
            );
        } finally {
            fclose($directoryHandle);
        }
    }

    /**
     * @return array{
     *     string,
     *     PaperDatasetManifest,
     *     resource,
     *     array{dev: int, ino: int}
     * }
     */
    private function readManifest(#[\SensitiveParameter] string $directory): array
    {
        try {
            if ($directory === '' || str_contains($directory, "\0")) {
                throw new \InvalidArgumentException();
            }
            $this->assertNoSymlinkComponents($directory);
            $resolved = realpath($directory);
            if ($resolved === false) {
                throw new \InvalidArgumentException();
            }
            $directoryBefore = $this->statistics($resolved);
            if (!$this->isPrivateDirectory($directoryBefore)
                || !isset($directoryBefore['dev'], $directoryBefore['ino'])
                || !\is_int($directoryBefore['dev'])
                || !\is_int($directoryBefore['ino'])
            ) {
                throw new \InvalidArgumentException();
            }
            $directoryHandle = $this->filesystem->openDirectory(
                $resolved,
                'hyperliquid_live_dataset_open',
            );
            if ($directoryHandle === false) {
                throw new \InvalidArgumentException();
            }
            try {
                $directoryOpened = $this->filesystem->stat(
                    $directoryHandle,
                    'hyperliquid_live_dataset_validate',
                );
                if (!$this->isPrivateDirectory($directoryOpened)
                    || !$this->sameFile($directoryBefore, $directoryOpened)
                ) {
                    throw new \InvalidArgumentException();
                }
                $identity = [
                    'dev' => $directoryBefore['dev'],
                    'ino' => $directoryBefore['ino'],
                ];
                $manifestPath = $resolved . '/' . self::MANIFEST_FILENAME;
                $this->assertNoSymlinkComponents($manifestPath);
                $contents = $this->readManifestFile($manifestPath);
                $manifest = $this->manifestCodec->decode($contents);
                if (!hash_equals($this->manifestCodec->encode($manifest), $contents)
                    || $manifest->state !== PaperDatasetState::RECORDING
                    || $manifest->venue !== PaperMarketDataVenue::HYPERLIQUID
                    || $manifest->quality
                        !== PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES
                    || $manifest->symbols !== [
                        'BTCUSDT' => 'BTC',
                        'ETHUSDT' => 'ETH',
                    ]
                    || basename($resolved) !== $manifest->datasetId
                    || !str_ends_with(
                        $manifest->datasetId,
                        '-' . $manifest->network->value,
                    )
                ) {
                    throw new \InvalidArgumentException();
                }
                $this->assertPinnedDirectory(
                    $resolved,
                    $directoryHandle,
                    $identity,
                );

                return [$resolved, $manifest, $directoryHandle, $identity];
            } catch (\Throwable $exception) {
                fclose($directoryHandle);

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

    private function readManifestFile(string $path): string
    {
        $before = $this->statistics($path);
        if (!$this->isPrivateRegularFile($before)
            || !isset($before['size'])
            || !\is_int($before['size'])
            || $before['size'] < 1
            || $before['size'] > PaperDatasetFormatLimits::MAX_MANIFEST_BYTES
        ) {
            throw new \InvalidArgumentException();
        }
        $handle = $this->filesystem->openDirectory(
            $path,
            'hyperliquid_live_manifest_open',
        );
        if ($handle === false) {
            throw new \InvalidArgumentException();
        }
        try {
            $opened = $this->filesystem->stat(
                $handle,
                'hyperliquid_live_manifest_validate',
            );
            if (!$this->isPrivateRegularFile($opened)
                || !$this->sameFile($before, $opened)
                || $opened['size'] !== $before['size']
            ) {
                throw new \InvalidArgumentException();
            }
            $contents = '';
            while (\strlen($contents) < $opened['size']) {
                $chunk = $this->filesystem->read(
                    $handle,
                    min(8192, $opened['size'] - \strlen($contents)),
                    'hyperliquid_live_manifest_read',
                );
                if ($chunk === false || $chunk === '') {
                    throw new \InvalidArgumentException();
                }
                $contents .= $chunk;
            }
            if ($this->filesystem->read(
                $handle,
                1,
                'hyperliquid_live_manifest_read',
            ) !== '') {
                throw new \InvalidArgumentException();
            }
            $after = $this->statistics($path);
            $openedAfter = $this->filesystem->stat(
                $handle,
                'hyperliquid_live_manifest_validate',
            );
            if (!$this->sameFile($opened, $after)
                || !$this->sameFile($opened, $openedAfter)
            ) {
                throw new \InvalidArgumentException();
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @param array{dev: int, ino: int} $identity
     */
    private function assertPinnedDirectory(
        string $path,
        $handle,
        array $identity,
    ): void {
        $opened = $this->filesystem->stat($handle, 'hyperliquid_live_dataset_validate');
        $current = $this->statistics($path);
        if (!$this->isPrivateDirectory($opened)
            || !$this->isPrivateDirectory($current)
            || !$this->sameFile($identity, $opened)
            || !$this->sameFile($identity, $current)
        ) {
            throw new \RuntimeException(self::MANIFEST_ERROR);
        }
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw new \InvalidArgumentException();
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
                && \is_int($statistics['mode'])
                && ($statistics['mode'] & self::FILE_TYPE_MASK)
                    === self::SYMLINK_FILE_TYPE
            ) {
                throw new \InvalidArgumentException();
            }
        }
    }

    /** @return array<string, mixed>|false */
    private function statistics(string $path): array|false
    {
        return $this->filesystem->pathStat($path, 'hyperliquid_live_path_validate');
    }

    /** @param array<string, mixed>|false $statistics */
    private function isPrivateDirectory(array|false $statistics): bool
    {
        return $statistics !== false
            && isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE
            && ($statistics['mode'] & 0777) === 0700;
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
     * @param array<string, mixed>|false $right
     */
    private function sameFile(array $left, array|false $right): bool
    {
        return $right !== false
            && isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }
}
