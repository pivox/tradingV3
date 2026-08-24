<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\PaperPublicDatasetCapture;
use App\Trading\Paper\Capture\PaperPublicLiveManifestFactory;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperPublicDatasetCapture::class)]
final class PaperPublicDatasetCaptureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/paper-public-capture-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0700, true));
        $resolved = realpath($this->root);
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

    public function testDurablyRecordsEachEventBeforeAcknowledgingIt(): void
    {
        $recorder = $this->recorder('record-before-ack-mainnet');
        $events = [$this->event('1', 1), $this->event('2', 2)];
        $observedCounts = [];
        $source = new DatasetOnlyCaptureSource(
            $events,
            true,
            static function (string $eventId) use ($recorder, &$observedCounts): void {
                self::assertSame($eventId, $recorder->manifest()->lastEventId);
                $observedCounts[] = $recorder->manifest()->eventCount;
            },
        );

        $manifest = (new PaperPublicDatasetCapture())->run($recorder, $source);

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(2, $manifest->eventCount);
        self::assertSame([1, 2], $observedCounts);
        self::assertSame([$events[0]->eventId, $events[1]->eventId], $source->acknowledged);
        self::assertSame(0, $source->stopCalls);
    }

    public function testMarksAnAbnormalEndIncomplete(): void
    {
        $recorder = $this->recorder('abnormal-end-mainnet');
        $source = new DatasetOnlyCaptureSource([], false);

        $manifest = (new PaperPublicDatasetCapture())->run($recorder, $source);

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(1, $source->stopCalls);
    }

    public function testNotifiesOnlyAfterTheEventIsDurableAndAcknowledged(): void
    {
        $recorder = $this->recorder('durable-observer-mainnet');
        $events = [$this->event('1', 1), $this->event('2', 2)];
        $source = new DatasetOnlyCaptureSource($events, true);
        $observed = [];

        (new PaperPublicDatasetCapture())->run(
            $recorder,
            $source,
            static function (PaperMarketEvent $event) use ($recorder, $source, &$observed): void {
                self::assertContains($event->eventId, $source->acknowledged);
                self::assertSame($event->eventId, $recorder->manifest()->lastEventId);
                $observed[] = $event->eventId;
            },
        );

        self::assertSame([$events[0]->eventId, $events[1]->eventId], $observed);
    }

    public function testPersistsIncompleteAndRethrowsAStableSourceFailure(): void
    {
        $recorder = $this->recorder('source-failure-mainnet');
        $failure = new \RuntimeException('public_source_failed');
        $source = new DatasetOnlyCaptureSource([], false, failure: $failure);

        try {
            (new PaperPublicDatasetCapture())->run($recorder, $source);
            self::fail('The source failure must escape capture.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        self::assertSame(1, $source->stopCalls);
    }

    private function recorder(string $datasetId): PaperDatasetRecorder
    {
        return new PaperDatasetRecorder(
            $this->root,
            (new PaperPublicLiveManifestFactory())->create(PaperMarketDataVenue::OKX, $datasetId),
        );
    }

    private function event(string $sequence, int $seconds): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            network: PaperMarketDataNetwork::MAINNET,
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::PUBLIC_TRADE,
            exchangeTimestamp: new \DateTimeImmutable(sprintf('2026-08-23T10:00:%02dZ', $seconds)),
            receivedTimestamp: new \DateTimeImmutable(sprintf('2026-08-23T10:00:%02d.100000Z', $seconds)),
            sequence: $sequence,
            payload: ['price' => '65000.0', 'size' => '0.01'],
        );
    }
}

final class DatasetOnlyCaptureSource implements PaperLiveMarketDataSourceInterface
{
    /** @var list<string> */
    public array $acknowledged = [];
    public int $stopCalls = 0;

    /**
     * @param list<PaperMarketEvent> $events
     * @param (\Closure(string): void)|null $onAcknowledge
     */
    public function __construct(
        private readonly array $events,
        private readonly bool $complete,
        private readonly ?\Closure $onAcknowledge = null,
        private readonly ?\Throwable $failure = null,
    ) {
    }

    public function venue(): PaperMarketDataVenue
    {
        return PaperMarketDataVenue::OKX;
    }

    public function events(): iterable
    {
        foreach ($this->events as $event) {
            yield $event;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function acknowledge(string $eventId): void
    {
        if ($this->onAcknowledge !== null) {
            ($this->onAcknowledge)($eventId);
        }
        $this->acknowledged[] = $eventId;
    }

    public function stop(): void
    {
        ++$this->stopCalls;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function requestHealthyOperatorStop(): void
    {
    }

    public function failureReason(): ?string
    {
        return $this->failure?->getMessage();
    }
}
