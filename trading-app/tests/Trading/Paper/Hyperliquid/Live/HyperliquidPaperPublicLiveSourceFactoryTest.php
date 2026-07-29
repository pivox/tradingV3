<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfigFactory;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSource;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSourceFactory;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportFactoryInterface;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperPublicLiveSourceFactory::class)]
final class HyperliquidPaperPublicLiveSourceFactoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $temporary = realpath(sys_get_temp_dir());
        self::assertIsString($temporary);
        $this->root = $temporary . '/hyperliquid-factory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{PaperMarketDataNetwork, string}> */
    public static function networks(): iterable
    {
        yield 'mainnet' => [
            PaperMarketDataNetwork::MAINNET,
            HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
        ];
        yield 'testnet' => [
            PaperMarketDataNetwork::TESTNET,
            HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
        ];
    }

    #[DataProvider('networks')]
    public function testFactoryPinsManifestNetworkAndCreatesFreshPublicGraph(
        PaperMarketDataNetwork $network,
        string $expectedUri,
    ): void {
        $directory = $this->recordingDirectory($network);
        $transportFactory = new RecordingHyperliquidTransportFactory();
        $factory = $this->factory($transportFactory);
        $loop = new StreamSelectLoop();

        $first = $factory->create($directory, $loop);
        $second = $factory->create($directory, $loop);

        self::assertInstanceOf(HyperliquidPaperPublicLiveSource::class, $first);
        self::assertNotSame($first, $second);
        self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $first->venue());
        self::assertCount(2, $transportFactory->configs);
        self::assertSame($network, $transportFactory->configs[0]->network);
        self::assertSame($expectedUri, $transportFactory->configs[0]->webSocketUri);
        self::assertNotSame(
            $transportFactory->transports[0],
            $transportFactory->transports[1],
        );
        $checkpoint = json_decode(
            (string) file_get_contents($directory . '/checkpoints/hyperliquid-live.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertSame($network->value, $checkpoint['state']['network']);
        self::assertSame(12, \count($checkpoint['state']['subscriptions']));
    }

    public function testFactoryRejectsNoncanonicalManifestAndSymlinkBeforeTransport(): void
    {
        $directory = $this->recordingDirectory(PaperMarketDataNetwork::MAINNET);
        $transportFactory = new RecordingHyperliquidTransportFactory();
        $factory = $this->factory($transportFactory);
        $manifest = $directory . '/manifest.json';
        $contents = (string) file_get_contents($manifest);
        unlink($manifest);
        $target = $this->root . '/manifest-target.json';
        file_put_contents($target, $contents);
        chmod($target, 0600);
        self::assertTrue(symlink($target, $manifest));

        try {
            $factory->create($directory, new StreamSelectLoop());
            self::fail('Expected manifest symlink rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_live_manifest_invalid',
                $exception->getMessage(),
            );
        }
        self::assertSame([], $transportFactory->transports);
    }

    private function recordingDirectory(PaperMarketDataNetwork $network): string
    {
        $datasetId = 'paper-hyperliquid-live-' . $network->value;
        $directory = $this->root . '/' . $datasetId;
        self::assertTrue(mkdir($directory, 0700));
        $manifest = new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: 'test',
            datasetId: $datasetId,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: $network,
            symbols: ['BTCUSDT' => 'BTC', 'ETHUSDT' => 'ETH'],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
        file_put_contents(
            $directory . '/manifest.json',
            (new PaperDatasetManifestCodec())->encode($manifest),
        );
        chmod($directory . '/manifest.json', 0600);

        return $directory;
    }

    private function factory(
        RecordingHyperliquidTransportFactory $transportFactory,
    ): HyperliquidPaperPublicLiveSourceFactory {
        return new HyperliquidPaperPublicLiveSourceFactory(
            new HyperliquidPaperPublicConfigFactory(true, $this->root),
            $transportFactory,
            new MockClock('2026-07-29T10:00:00Z'),
            new PaperDatasetManifestCodec(),
            new PaperDatasetRecorderFilesystem(),
        );
    }
}

final class RecordingHyperliquidTransportFactory implements
    HyperliquidPaperPublicWebSocketTransportFactoryInterface
{
    /** @var list<HyperliquidPaperPublicConfig> */
    public array $configs = [];
    /** @var list<HyperliquidPaperPublicWebSocketTransportInterface> */
    public array $transports = [];

    public function create(
        LoopInterface $loop,
        HyperliquidPaperPublicConfig $config,
    ): HyperliquidPaperPublicWebSocketTransportInterface {
        $this->configs[] = $config;
        $transport = new FactoryNoopHyperliquidTransport();
        $this->transports[] = $transport;

        return $transport;
    }
}

final class FactoryNoopHyperliquidTransport implements
    HyperliquidPaperPublicWebSocketTransportInterface
{
    public function connect(
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
    }

    public function send(array $message): void
    {
    }

    public function close(): void
    {
    }
}
