<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\PaperPublicCaptureResult;
use App\Trading\Paper\Capture\PaperPublicCaptureRunner;
use App\Trading\Paper\Capture\PaperPublicDatasetCapture;
use App\Trading\Paper\Capture\PaperPublicLiveManifestFactory;
use App\Trading\Paper\Capture\PaperPublicLiveSourceFactoryInterface;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

#[CoversClass(PaperPublicCaptureRunner::class)]
#[CoversClass(PaperPublicCaptureResult::class)]
final class PaperPublicCaptureRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/paper-public-runner-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700, true));
        $resolved = realpath($root);
        self::assertIsString($resolved);
        $this->root = $resolved;
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
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testRunsOnlyTheSelectedVenueAndReturnsRedactedEvidence(): void
    {
        $okx = new CaptureRunnerSourceFactory(
            PaperMarketDataVenue::OKX,
            [$this->event(PaperMarketDataVenue::OKX, '1', 1)],
        );
        $hyperliquid = new CaptureRunnerSourceFactory(PaperMarketDataVenue::HYPERLIQUID, []);
        $loop = new StreamSelectLoop();

        $result = $this->runner($okx, $hyperliquid)->run(
            'okx',
            'first-baseline-okx-mainnet',
            300,
            $loop,
        );

        self::assertSame(1, $okx->calls);
        self::assertSame(0, $hyperliquid->calls);
        self::assertSame($loop, $okx->receivedLoop);
        self::assertSame($this->root . '/first-baseline-okx-mainnet', $okx->receivedDirectory);
        self::assertSame([
            'schema_version' => 'paper-public-capture-result-v1',
            'dataset_id' => 'first-baseline-okx-mainnet',
            'source_network' => 'mainnet',
            'source_venue' => 'okx',
            'state' => 'complete',
            'quality' => 'recorded_public_book_and_trades',
            'event_count' => 1,
            'start_exchange_timestamp' => '2026-08-23T10:00:01.000000Z',
            'end_exchange_timestamp' => '2026-08-23T10:00:01.000000Z',
            'channels' => ['public_trade'],
            'events_file_sha256' => $result->toArray()['events_file_sha256'],
            'certification_status' => 'not_evaluated',
        ], $result->toArray());
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $result->toArray()['events_file_sha256']);
        self::assertStringNotContainsString($this->root, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testResumesAnExactRecordingDataset(): void
    {
        $datasetId = 'resume-okx-mainnet';
        $manifest = (new PaperPublicLiveManifestFactory())->create(PaperMarketDataVenue::OKX, $datasetId);
        $recorder = new PaperDatasetRecorder($this->root, $manifest);
        $recorder->append($this->event(PaperMarketDataVenue::OKX, '1', 1));
        unset($recorder);
        $okx = new CaptureRunnerSourceFactory(
            PaperMarketDataVenue::OKX,
            [$this->event(PaperMarketDataVenue::OKX, '2', 2)],
        );

        $result = $this->runner($okx, new CaptureRunnerSourceFactory(
            PaperMarketDataVenue::HYPERLIQUID,
            [],
        ))->run('okx', $datasetId, 300, new StreamSelectLoop());

        self::assertSame(2, $result->toArray()['event_count']);
        self::assertSame(1, $okx->calls);
    }

    public function testRefusesToReopenATerminalDatasetBeforeCreatingASource(): void
    {
        $datasetId = 'terminal-okx-mainnet';
        $okx = new CaptureRunnerSourceFactory(
            PaperMarketDataVenue::OKX,
            [$this->event(PaperMarketDataVenue::OKX, '1', 1)],
        );
        $runner = $this->runner($okx, new CaptureRunnerSourceFactory(
            PaperMarketDataVenue::HYPERLIQUID,
            [],
        ));
        $runner->run('okx', $datasetId, 300, new StreamSelectLoop());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_public_capture_dataset_terminal');
        try {
            $runner->run('okx', $datasetId, 300, new StreamSelectLoop());
        } finally {
            self::assertSame(1, $okx->calls);
        }
    }

    public function testRejectsUnknownVenueBeforeCreatingAnyDataset(): void
    {
        $okx = new CaptureRunnerSourceFactory(PaperMarketDataVenue::OKX, []);
        $hyperliquid = new CaptureRunnerSourceFactory(PaperMarketDataVenue::HYPERLIQUID, []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_public_capture_venue_invalid');
        try {
            $this->runner($okx, $hyperliquid)->run(
                'bitmart',
                'invalid-venue-mainnet',
                300,
                new StreamSelectLoop(),
            );
        } finally {
            self::assertSame(0, $okx->calls);
            self::assertSame(0, $hyperliquid->calls);
            self::assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
        }
    }

    private function runner(
        PaperPublicLiveSourceFactoryInterface $okx,
        PaperPublicLiveSourceFactoryInterface $hyperliquid,
    ): PaperPublicCaptureRunner {
        return new PaperPublicCaptureRunner(
            new PaperPublicLiveManifestFactory(),
            new PaperPublicDatasetCapture(),
            $okx,
            $hyperliquid,
            $this->root,
        );
    }

    private function event(PaperMarketDataVenue $venue, string $sequence, int $seconds): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            $venue,
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            new \DateTimeImmutable(sprintf('2026-08-23T10:00:%02dZ', $seconds)),
            new \DateTimeImmutable(sprintf('2026-08-23T10:00:%02d.100000Z', $seconds)),
            $sequence,
            ['price' => '65000.0', 'size' => '0.01'],
        );
    }
}

final class CaptureRunnerSourceFactory implements PaperPublicLiveSourceFactoryInterface
{
    public int $calls = 0;
    public ?string $receivedDirectory = null;
    public ?LoopInterface $receivedLoop = null;

    /** @param list<PaperMarketEvent> $events */
    public function __construct(
        private readonly PaperMarketDataVenue $venue,
        private readonly array $events,
    ) {
    }

    public function create(string $datasetDirectory, ?LoopInterface $loop = null): PaperLiveMarketDataSourceInterface
    {
        ++$this->calls;
        $this->receivedDirectory = $datasetDirectory;
        $this->receivedLoop = $loop;

        return new CaptureRunnerSource($this->venue, $this->events);
    }
}

final class CaptureRunnerSource implements PaperLiveMarketDataSourceInterface
{
    /** @param list<PaperMarketEvent> $events */
    public function __construct(
        private readonly PaperMarketDataVenue $venue,
        private readonly array $events,
    ) {
    }

    public function venue(): PaperMarketDataVenue
    {
        return $this->venue;
    }

    public function events(): iterable
    {
        yield from $this->events;
    }

    public function acknowledge(string $eventId): void
    {
    }

    public function stop(): void
    {
    }

    public function isComplete(): bool
    {
        return true;
    }

    public function requestHealthyOperatorStop(): void
    {
    }

    public function failureReason(): ?string
    {
        return null;
    }
}
