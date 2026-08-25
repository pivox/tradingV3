<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Http;

use App\Kernel;
use App\Tests\Trading\Paper\Okx\Live\DeterministicLoop;
use App\Tests\Trading\Paper\Okx\Live\FakeOkxPaperPublicWebSocketTransport;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClient;
use App\Trading\Paper\Okx\Http\OkxPaperFundingRateClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRateLimiter;
use App\Trading\Paper\Okx\Live\OkxPaperLiveCheckpointStore;
use App\Trading\Paper\Okx\Live\OkxPaperLivePolicy;
use App\Trading\Paper\Okx\Live\OkxPaperPublicLiveSource;
use App\Trading\Paper\Okx\Live\OkxPaperPublicLiveSourceFactory;
use App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportFactoryInterface;
use App\Trading\Paper\Okx\Live\OkxPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\Okx\Live\PawlOkxPaperPublicWebSocketTransport;
use App\Trading\Paper\Okx\Live\PawlOkxPaperPublicWebSocketTransportFactory;
use App\Trading\Paper\Okx\Live\OkxPaperStreamFrontier;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use React\EventLoop\LoopInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\Policy\SlidingWindowLimiter;

#[CoversNothing]
final class OkxPaperPublicServiceWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testCredentialFreeDefaultsAndClientAreWiredWithoutPrivateServices(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $client = $container->get(OkxPaperPublicRestClientInterface::class);
        self::assertInstanceOf(OkxPaperPublicRestClient::class, $client);
        self::assertSame($client, $container->get(OkxPaperFundingRateClientInterface::class));

        $configProperty = new \ReflectionProperty(OkxPaperPublicRestClient::class, 'config');
        $config = $configProperty->getValue($client);
        self::assertInstanceOf(OkxPaperPublicConfig::class, $config);
        self::assertFalse($config->acquisitionEnabled);
        self::assertSame('https://www.okx.com', $config->restBaseUri);
        self::assertSame('wss://ws.okx.com:8443/ws/v5/public', $config->webSocketUri);
        self::assertSame('wss://ws.okx.com:8443/ws/v5/business', $config->businessWebSocketUri);
        self::assertSame(self::getContainer()->getParameter('kernel.project_dir') . '/var/paper-market-data', $config->dataRoot);
    }

    public function testPublicTransportFactoryAndLiveFactoryHaveOnlyExplicitPublicServiceBoundaries(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $transportFactory = $container->get(OkxPaperPublicWebSocketTransportFactoryInterface::class);
        self::assertInstanceOf(PawlOkxPaperPublicWebSocketTransportFactory::class, $transportFactory);
        self::assertFalse($container->has(OkxPaperPublicWebSocketTransportInterface::class));
        self::assertFalse($container->has(OkxPaperPublicLiveSource::class));
        self::assertFalse($container->has(OkxPaperLiveCheckpointStore::class));
        self::assertFalse($container->has(PawlOkxPaperPublicWebSocketTransport::class));

        $factory = new \ReflectionClass(OkxPaperPublicLiveSourceFactory::class);
        $constructor = $factory->getConstructor();
        self::assertInstanceOf(\ReflectionMethod::class, $constructor);
        $dependencyTypes = array_map(
            static function (\ReflectionParameter $parameter): string {
                $type = $parameter->getType();
                self::assertInstanceOf(\ReflectionNamedType::class, $type);

                return $type->getName();
            },
            $constructor->getParameters(),
        );
        self::assertSame([
            OkxPaperPublicRestClientInterface::class,
            OkxPaperPublicWebSocketTransportFactoryInterface::class,
            OkxPaperPublicConfig::class,
            \Symfony\Component\Clock\ClockInterface::class,
            PaperDatasetManifestCodec::class,
            PaperDatasetRecorderFilesystem::class,
            \App\Trading\Paper\Okx\Http\OkxPaperInstrumentMetadataClientInterface::class,
            OkxPaperFundingRateClientInterface::class,
        ], $dependencyTypes);
        foreach ($dependencyTypes as $dependencyType) {
            self::assertDoesNotMatchRegularExpression(
                '/(?:PrivateWebSocket|\\\\OkxRestClient|Gateway|Signer|Account|Mutation|\\\\Order(?:Client|Gateway|Service|Interface))/i',
                $dependencyType,
            );
        }

        $loop = new DeterministicLoop();
        $firstTransport = $transportFactory->create($loop);
        $secondTransport = $transportFactory->create($loop);
        self::assertInstanceOf(PawlOkxPaperPublicWebSocketTransport::class, $firstTransport);
        self::assertInstanceOf(PawlOkxPaperPublicWebSocketTransport::class, $secondTransport);
        self::assertNotSame($firstTransport, $secondTransport);
        $connector = new \ReflectionProperty(PawlOkxPaperPublicWebSocketTransport::class, 'connector');
        self::assertNotSame(
            $connector->getValue($firstTransport),
            $connector->getValue($secondTransport),
        );
    }

    public function testLiveFactoryBuildsFreshLoopScopedSourcesAndCanonicalCheckpoints(): void
    {
        $temporaryRoot = $this->temporaryDirectory('okx-paper-live-factory-');

        try {
            $directories = [
                $this->writeRecordingDataset($temporaryRoot, 'okx-live-factory-a'),
                $this->writeRecordingDataset($temporaryRoot, 'okx-live-factory-b'),
                $this->writeRecordingDataset($temporaryRoot, 'okx-live-factory-c'),
                $this->writeRecordingDataset($temporaryRoot, 'okx-live-factory-d'),
            ];
            $restClient = new Task10PublicRestClient();
            $transportFactory = new Task10PublicTransportFactory();
            $clock = new MockClock('2026-07-28T10:00:00.000000Z');
            $config = self::publicConfig($temporaryRoot);
            $factory = new OkxPaperPublicLiveSourceFactory(
                $restClient,
                $transportFactory,
                $config,
                $clock,
                new PaperDatasetManifestCodec(),
                new PaperDatasetRecorderFilesystem(),
            );
            $sharedLoop = new DeterministicLoop();
            $otherLoop = new DeterministicLoop();

            $sourceA = $factory->create($directories[0], $sharedLoop);
            $sourceB = $factory->create($directories[1], $sharedLoop);
            $sourceC = $factory->create($directories[2], $otherLoop);
            $sourceD = $factory->create($directories[3]);

            self::assertCount(8, $transportFactory->loops);
            self::assertSame(
                [$sharedLoop, $sharedLoop, $sharedLoop, $sharedLoop, $otherLoop, $otherLoop],
                array_slice($transportFactory->loops, 0, 6),
            );
            self::assertSame($transportFactory->loops[6], $transportFactory->loops[7]);

            $sources = [$sourceA, $sourceB, $sourceC, $sourceD];
            $expectedLoops = [
                $sharedLoop,
                $sharedLoop,
                $otherLoop,
                $transportFactory->loops[6],
            ];
            $objectIdsByBoundary = [
                'checkpointStore' => [],
                'checkpoint' => [],
                'subscriptions' => [],
                'decoder' => [],
                'publicQueue' => [],
                'businessQueue' => [],
            ];
            foreach ($sources as $sourceIndex => $source) {
                $transportOffset = $sourceIndex * 2;
                self::assertSame(
                    $transportFactory->transports[$transportOffset],
                    self::property($source, 'publicTransport'),
                );
                self::assertSame(
                    $transportFactory->transports[$transportOffset + 1],
                    self::property($source, 'businessTransport'),
                );
                self::assertSame($expectedLoops[$sourceIndex], self::property($source, 'loop'));
                self::assertSame($restClient, self::property($source, 'restClient'));
                self::assertSame($config, self::property($source, 'config'));
                self::assertSame($clock, self::property($source, 'clock'));
                self::assertSame(1000, self::property($source, 'initialHourlyCandleTarget'));

                foreach (array_keys($objectIdsByBoundary) as $property) {
                    $value = self::property($source, $property);
                    self::assertIsObject($value);
                    $objectIdsByBoundary[$property][] = spl_object_id($value);
                }
                $subscriptions = self::property($source, 'subscriptions');
                $decoder = self::property($source, 'decoder');
                self::assertSame($subscriptions, self::property($decoder, 'subscriptions'));

                $books = self::property($source, 'books');
                self::assertIsArray($books);
                self::assertSame(['BTC-USDT-SWAP', 'ETH-USDT-SWAP'], array_keys($books));
                self::assertNotSame($books['BTC-USDT-SWAP'], $books['ETH-USDT-SWAP']);

                $checkpoint = self::property($source, 'checkpoint');
                self::assertSame(
                    basename($directories[$sourceIndex]),
                    $checkpoint->datasetId,
                );
                self::assertSame(self::expectedConfigurationSha256($config), $checkpoint->configurationSha256);
                self::assertNull($checkpoint->pendingEvent);
            }
            foreach ($objectIdsByBoundary as $objectIds) {
                self::assertCount(count($objectIds), array_unique($objectIds));
            }
            self::assertCount(8, array_unique(array_map('spl_object_id', $transportFactory->transports)));
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    public function testLiveFactoryRejectsNonRecordingWrongVenueWrongSymbolsAndPathIdentity(): void
    {
        $temporaryRoot = $this->temporaryDirectory('okx-paper-live-manifest-');

        try {
            $cases = [
                $this->writeRecordingDataset(
                    $temporaryRoot,
                    'okx-live-not-recording',
                    state: PaperDatasetState::INCOMPLETE,
                ),
                $this->writeRecordingDataset(
                    $temporaryRoot,
                    'okx-live-wrong-venue',
                    venue: PaperMarketDataVenue::HYPERLIQUID,
                ),
                $this->writeRecordingDataset(
                    $temporaryRoot,
                    'okx-live-one-symbol',
                    symbols: ['BTCUSDT' => 'BTC-USDT-SWAP'],
                ),
                $this->writeRecordingDataset(
                    $temporaryRoot,
                    'okx-live-wrong-native-symbols',
                    symbols: [
                        'BTCUSDT' => 'ETH-USDT-SWAP',
                        'ETHUSDT' => 'BTC-USDT-SWAP',
                    ],
                ),
                $this->writeRecordingDataset(
                    $temporaryRoot,
                    'okx-live-path-name',
                    manifestDatasetId: 'okx-live-other-identity',
                ),
            ];
            $transportFactory = new Task10PublicTransportFactory();
            $factory = self::directFactory(
                $temporaryRoot,
                $transportFactory,
                new PaperDatasetRecorderFilesystem(),
            );

            foreach ($cases as $datasetDirectory) {
                try {
                    $factory->create($datasetDirectory, new DeterministicLoop());
                    self::fail('Invalid live dataset manifest must be rejected.');
                } catch (\RuntimeException $exception) {
                    self::assertSame(
                        'okx_paper_public_live_manifest_invalid',
                        $exception->getMessage(),
                    );
                }
            }
            self::assertSame([], $transportFactory->transports);
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    public function testLiveFactoryRejectsSymlinkedAndReplacedManifestPathsBeforeTransportCreation(): void
    {
        $temporaryRoot = $this->temporaryDirectory('okx-paper-live-path-guard-');

        try {
            $target = $this->writeRecordingDataset($temporaryRoot, 'okx-live-symlink-target');
            $symlink = $temporaryRoot . '/okx-live-symlink';
            if (!symlink($target, $symlink)) {
                self::markTestSkipped('Symbolic links are unavailable.');
            }
            $transportFactory = new Task10PublicTransportFactory();
            $factory = self::directFactory(
                $temporaryRoot,
                $transportFactory,
                new PaperDatasetRecorderFilesystem(),
            );
            try {
                $factory->create($symlink, new DeterministicLoop());
                self::fail('A symlinked dataset path must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame('okx_paper_public_live_manifest_invalid', $exception->getMessage());
            }

            $replacementDirectory = $this->writeRecordingDataset(
                $temporaryRoot,
                'okx-live-replaced-manifest',
            );
            $replacingFilesystem = new Task10ReplacingManifestFilesystem(
                $replacementDirectory . '/manifest.json',
            );
            $replacingFactory = self::directFactory(
                $temporaryRoot,
                $transportFactory,
                $replacingFilesystem,
            );
            try {
                $replacingFactory->create($replacementDirectory, new DeterministicLoop());
                self::fail('A replaced manifest must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame('okx_paper_public_live_manifest_invalid', $exception->getMessage());
            }

            self::assertTrue($replacingFilesystem->replaced);
            self::assertSame([], $transportFactory->transports);
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    public function testLiveFactoryRejectsDatasetDirectoryReplacementBeforeCheckpointBinding(): void
    {
        $temporaryRoot = $this->temporaryDirectory('okx-paper-live-directory-guard-');

        try {
            $datasetDirectory = $this->writeRecordingDataset(
                $temporaryRoot,
                'okx-live-replaced-directory',
            );
            $filesystem = new Task10ReplacingDatasetFilesystem($datasetDirectory);
            $transportFactory = new Task10PublicTransportFactory();
            $factory = self::directFactory($temporaryRoot, $transportFactory, $filesystem);

            try {
                $factory->create($datasetDirectory, new DeterministicLoop());
                self::fail('A dataset replaced before checkpoint binding must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'okx_paper_public_live_manifest_invalid',
                    $exception->getMessage(),
                );
            }

            self::assertTrue($filesystem->replaced);
            self::assertSame([], $transportFactory->transports);
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    public function testLiveFactoryDoesNotSharePersistedPendingCheckpointAcrossDatasets(): void
    {
        $temporaryRoot = $this->temporaryDirectory('okx-paper-live-pending-isolation-');

        try {
            $datasetA = $this->writeRecordingDataset($temporaryRoot, 'okx-live-pending-a');
            $datasetB = $this->writeRecordingDataset($temporaryRoot, 'okx-live-pending-b');
            $factory = self::directFactory(
                $temporaryRoot,
                new Task10PublicTransportFactory(),
                new PaperDatasetRecorderFilesystem(),
            );
            $sourceA = $factory->create($datasetA, new DeterministicLoop());
            $checkpointStoreA = self::property($sourceA, 'checkpointStore');
            self::assertInstanceOf(OkxPaperLiveCheckpointStore::class, $checkpointStoreA);
            $checkpointA = self::property($sourceA, 'checkpoint');
            $event = PaperMarketEvent::create(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
                venue: PaperMarketDataVenue::OKX,
                symbol: 'BTCUSDT',
                channel: PaperMarketDataChannel::PUBLIC_TRADE,
                exchangeTimestamp: new \DateTimeImmutable('2026-07-28T10:00:00.000000Z'),
                receivedTimestamp: new \DateTimeImmutable('2026-07-28T10:00:00.100000Z'),
                sequence: '1',
                payload: [
                    'native_symbol' => 'BTC-USDT-SWAP',
                    'trade_id' => '10001',
                    'price' => '65000.1',
                    'size_contracts' => '1',
                    'taker_side' => 'buy',
                    'aggregate_count' => null,
                    'source' => '0',
                    'source_seq_id' => null,
                    'origin' => 'ws_aggregated',
                ],
            );
            $naturalIdentity = 'trade|task10-pending';
            $ordinals = OkxPaperSourceOrdinal::restore([
                'schema_version' => 1,
                'scopes' => [],
            ]);
            $ordinals->commit(
                'okx/BTCUSDT/public_trade',
                $naturalIdentity,
                OkxPaperSourceOrdinal::assignmentDigest(
                    $naturalIdentity,
                    $event->exchangeTimestamp,
                    $event->payload,
                ),
                $event,
            );
            $pendingA = $checkpointStoreA->savePending(
                $checkpointA,
                $event,
                $ordinals->snapshot(),
                [
                    'stream' => 'BTCUSDT/ws/public_trade',
                    'frontier' => OkxPaperStreamFrontier::fromEvent($event)->toArray(),
                ],
            );
            self::assertSame($event->eventId, $pendingA->pendingEvent?->eventId);

            $sourceB = $factory->create($datasetB, new DeterministicLoop());
            $checkpointB = self::property($sourceB, 'checkpoint');
            self::assertNull($checkpointB->pendingEvent);
            self::assertStringContainsString(
                $event->eventId,
                (string) file_get_contents($datasetA . '/checkpoints/okx-live/checkpoint.json'),
            );
            self::assertStringNotContainsString(
                $event->eventId,
                (string) file_get_contents($datasetB . '/checkpoints/okx-live/checkpoint.json'),
            );
        } finally {
            $this->removeDirectory($temporaryRoot);
        }
    }

    public function testConfiguredSlidingWindowsUseThePlanHeadroomLimits(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $client = $container->get(OkxPaperPublicRestClientInterface::class);
        self::assertInstanceOf(OkxPaperPublicRestClient::class, $client);
        $rateLimiterProperty = new \ReflectionProperty(OkxPaperPublicRestClient::class, 'rateLimiter');
        $rateLimiter = $rateLimiterProperty->getValue($client);
        self::assertInstanceOf(OkxPaperPublicRateLimiter::class, $rateLimiter);

        $historyProperty = new \ReflectionProperty(OkxPaperPublicRateLimiter::class, 'historyLimiter');
        $history = $historyProperty->getValue($rateLimiter);
        self::assertInstanceOf(SlidingWindowLimiter::class, $history);
        $intervalProperty = new \ReflectionProperty(SlidingWindowLimiter::class, 'interval');
        self::assertSame(2, $intervalProperty->getValue($history));
        for ($attempt = 0; $attempt < 16; ++$attempt) {
            self::assertTrue($history->consume()->isAccepted());
        }
        self::assertFalse($history->consume()->isAccepted());

        $snapshotProperty = new \ReflectionProperty(OkxPaperPublicRateLimiter::class, 'snapshotLimiter');
        $snapshot = $snapshotProperty->getValue($rateLimiter);
        self::assertInstanceOf(SlidingWindowLimiter::class, $snapshot);
        self::assertSame(2, $intervalProperty->getValue($snapshot));
        for ($attempt = 0; $attempt < 32; ++$attempt) {
            self::assertTrue($snapshot->consume()->isAccepted());
        }
        self::assertFalse($snapshot->consume()->isAccepted());
    }

    private static function directFactory(
        string $dataRoot,
        Task10PublicTransportFactory $transportFactory,
        PaperDatasetRecorderFilesystem $filesystem,
    ): OkxPaperPublicLiveSourceFactory {
        return new OkxPaperPublicLiveSourceFactory(
            new Task10PublicRestClient(),
            $transportFactory,
            self::publicConfig($dataRoot),
            new MockClock('2026-07-28T10:00:00.000000Z'),
            new PaperDatasetManifestCodec(),
            $filesystem,
        );
    }

    private static function publicConfig(string $dataRoot): OkxPaperPublicConfig
    {
        return new OkxPaperPublicConfig(
            acquisitionEnabled: false,
            restBaseUri: OkxPaperPublicConfig::REST_BASE_URI,
            webSocketUri: OkxPaperPublicConfig::WEB_SOCKET_URI,
            dataRoot: $dataRoot,
            businessWebSocketUri: OkxPaperPublicConfig::BUSINESS_WEB_SOCKET_URI,
        );
    }

    private function temporaryDirectory(string $prefix): string
    {
        $temporary = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($temporary);
        self::assertTrue(unlink($temporary));
        self::assertTrue(mkdir($temporary, 0700));
        $resolved = realpath($temporary);
        self::assertIsString($resolved);

        return $resolved;
    }

    /**
     * @param array<string, string> $symbols
     */
    private function writeRecordingDataset(
        string $root,
        string $directoryName,
        PaperDatasetState $state = PaperDatasetState::RECORDING,
        PaperMarketDataVenue $venue = PaperMarketDataVenue::OKX,
        array $symbols = [
            'BTCUSDT' => 'BTC-USDT-SWAP',
            'ETHUSDT' => 'ETH-USDT-SWAP',
        ],
        ?string $manifestDatasetId = null,
    ): string {
        $datasetDirectory = $root . '/' . $directoryName;
        self::assertTrue(mkdir($datasetDirectory, 0700));
        $manifest = new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: $manifestDatasetId ?? $directoryName,
            venue: $venue,
            network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            symbols: $symbols,
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: $state === PaperDatasetState::INCOMPLETE
                ? PaperMarketDataQuality::INCOMPLETE
                : PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: $state === PaperDatasetState::INCOMPLETE
                ? str_repeat('a', 64)
                : null,
            state: $state,
            lastEventId: null,
        );
        $manifestPath = $datasetDirectory . '/manifest.json';
        self::assertNotFalse(file_put_contents(
            $manifestPath,
            (new PaperDatasetManifestCodec())->encode($manifest),
        ));
        self::assertTrue(chmod($manifestPath, 0600));

        return $datasetDirectory;
    }

    private static function expectedConfigurationSha256(OkxPaperPublicConfig $config): string
    {
        $instruments = new OkxPaperInstrumentMap();
        $subscriptions = new \App\Trading\Paper\Okx\Live\OkxPaperPublicSubscriptionSet($instruments);
        $policy = (new \ReflectionClass(OkxPaperLivePolicy::class))->getConstants();
        ksort($policy, SORT_STRING);

        return hash('sha256', CanonicalJson::encode([
            'business_web_socket_uri' => $config->businessWebSocketUri,
            'native_instrument_ids' => $instruments->nativeInstrumentIds(),
            'policy' => $policy,
            'public_subscriptions' => $subscriptions->publicArguments(),
            'public_web_socket_uri' => $config->webSocketUri,
            'business_subscriptions' => $subscriptions->businessArguments(),
            'rest_base_uri' => $config->restBaseUri,
            'venue' => PaperMarketDataVenue::OKX->value,
        ]));
    }

    private static function property(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !is_link($directory)) {
            return;
        }
        if (is_link($directory)) {
            @unlink($directory);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } else {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($directory);
    }
}

final class Task10PublicRestClient implements OkxPaperPublicRestClientInterface
{
    public function historyCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        int $limit = 300,
    ): array {
        return [];
    }

    public function currentCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        ?string $before = null,
        int $limit = 300,
    ): array {
        return [];
    }

    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array {
        return [];
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        return [];
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        return [];
    }
}

final class Task10PublicTransportFactory implements OkxPaperPublicWebSocketTransportFactoryInterface
{
    /** @var list<LoopInterface> */
    public array $loops = [];

    /** @var list<FakeOkxPaperPublicWebSocketTransport> */
    public array $transports = [];

    public function create(LoopInterface $loop): OkxPaperPublicWebSocketTransportInterface
    {
        $this->loops[] = $loop;
        $transport = new FakeOkxPaperPublicWebSocketTransport();
        $this->transports[] = $transport;

        return $transport;
    }
}

final class Task10ReplacingManifestFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $replaced = false;

    public function __construct(private readonly string $manifestPath)
    {
    }

    public function read($handle, int $length, string $operation): string|false
    {
        $contents = parent::read($handle, $length, $operation);
        if (!$this->replaced && $operation === 'okx_paper_public_live_manifest_read') {
            $this->replaced = true;
            $replacementPath = $this->manifestPath . '.replaced';
            if (!rename($this->manifestPath, $replacementPath)
                || file_put_contents($this->manifestPath, (string) file_get_contents($replacementPath)) === false
                || !chmod($this->manifestPath, 0600)
            ) {
                throw new \RuntimeException('task10_manifest_replacement_failed');
            }
        }

        return $contents;
    }
}

final class Task10ReplacingDatasetFilesystem extends PaperDatasetRecorderFilesystem
{
    public bool $replaced = false;

    public function __construct(private readonly string $datasetDirectory)
    {
    }

    public function pathStat(string $path, string $operation): array|false
    {
        if (!$this->replaced
            && $operation === 'okx_paper_live_path_validation'
            && $path === $this->datasetDirectory
        ) {
            $this->replaced = true;
            $originalDirectory = $this->datasetDirectory . '.original';
            if (!rename($this->datasetDirectory, $originalDirectory)
                || !mkdir($this->datasetDirectory, 0700)
                || !copy(
                    $originalDirectory . '/manifest.json',
                    $this->datasetDirectory . '/manifest.json',
                )
                || !chmod($this->datasetDirectory . '/manifest.json', 0600)
            ) {
                throw new \RuntimeException('task10_dataset_replacement_failed');
            }
        }

        return parent::pathStat($path, $operation);
    }
}
