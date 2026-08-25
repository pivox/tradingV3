<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Capture\PaperPublicCaptureStopController;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperFundingRateClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveIntegrityException;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicFrameQueue;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSource;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperPublicLiveSource::class)]
final class HyperliquidPaperPublicLiveSourceTest extends TestCase
{
    public function testRejectsWarmupRestClientFromAnotherNetwork(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_live_rest_client_network_mismatch');

        $this->source(
            new DeterministicHyperliquidTransport([]),
            restClient: new LiveSourceWarmupRestClient(
                PaperMarketDataNetwork::TESTNET,
            ),
        );
    }

    public function testWarmupPinsObservationAndResumesAfterAcknowledgedCandle(): void
    {
        $rest = new LiveSourceWarmupRestClient();
        $first = $this->source(
            new DeterministicHyperliquidTransport([]),
            restClient: $rest,
        );
        $events = self::generator($first->events());
        $events->rewind();
        $firstCandle = $events->current();

        self::assertInstanceOf(PaperMarketEvent::class, $firstCandle);
        self::assertStringStartsWith('candle_', $firstCandle->channel->value);
        self::assertSame([
            'BTC' => '1785319200000',
            'ETH' => '1785319200000',
        ], $this->checkpoint()->initialCandleWindowEnds);
        $first->acknowledge($firstCandle->eventId);

        $resumed = $this->source(
            new DeterministicHyperliquidTransport([]),
            restClient: $rest,
        );
        $resumedEvents = self::generator($resumed->events());
        $resumedEvents->rewind();

        self::assertInstanceOf(PaperMarketEvent::class, $resumedEvents->current());
        self::assertNotSame($firstCandle->eventId, $resumedEvents->current()->eventId);
        self::assertNotSame([
            $firstCandle->symbol,
            $firstCandle->channel->value,
            $firstCandle->payload['start_time'],
        ], [
            $resumedEvents->current()->symbol,
            $resumedEvents->current()->channel->value,
            $resumedEvents->current()->payload['start_time'],
        ]);
        self::assertSame(24, count($rest->requests));
    }

    public function testRestWarmupStartsBeforeTheWebSocketConnection(): void
    {
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source(
            $transport,
            restClient: new LiveSourceWarmupRestClient(),
        );

        self::generator($source->events())->rewind();

        self::assertSame(0, $transport->connectCount);
        self::assertSame('warming', $this->checkpoint()->phase);
    }

    public function testAuthenticatedMetadataAndFundingPrecedeInitialSnapshotBoundaries(): void
    {
        $source = $this->source(
            new DeterministicHyperliquidTransport(self::marketFrames()),
            metadataClient: new StaticHyperliquidPaperMetadataClient(),
            fundingClient: new StaticHyperliquidPaperFundingClient(),
        );
        $events = self::generator($source->events());
        $captured = [];
        $events->rewind();
        while ($events->valid() && \count($captured) < 6) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $captured[] = $event;
            $source->acknowledge($event->eventId);
            $events->next();
        }

        self::assertSame([
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            PaperMarketDataChannel::FUNDING_RATE,
            PaperMarketDataChannel::FUNDING_RATE,
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
        ], array_column($captured, 'channel'));
        self::assertSame(
            ['BTCUSDT', 'ETHUSDT', 'BTCUSDT', 'ETHUSDT', 'BTCUSDT', 'ETHUSDT'],
            array_column($captured, 'symbol'),
        );
        self::assertSame(1, $captured[0]->payload['source_epoch']);
        self::assertSame(1, $captured[1]->payload['source_epoch']);
        self::assertSame(1, $captured[2]->payload['source_epoch']);
        self::assertSame(1, $captured[3]->payload['source_epoch']);
        $source->stop();
    }

    public function testFundingRefreshesBeforeExpiryWithoutChangingTheSourceEpoch(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $clock = new MockClock('2026-07-29T10:00:00Z');
        $funding = new StaticHyperliquidPaperFundingClient();
        $source = $this->source(
            new DeterministicHyperliquidTransport([]),
            loop: $loop,
            metadataClient: new StaticHyperliquidPaperMetadataClient(),
            fundingClient: $funding,
            clock: $clock,
        );
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 6; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 5) {
                $events->next();
            }
        }

        self::assertSame(1, $funding->calls);
        self::assertContains(3000.0, $loop->intervals());
        $clock->sleep(3000);
        self::assertSame(3000.0, $loop->fire(3000.0));
        $events->next();

        self::assertSame(PaperMarketDataChannel::FUNDING_RATE, $events->current()->channel);
        self::assertSame('BTCUSDT', $events->current()->symbol);
        self::assertSame(1, $events->current()->payload['source_epoch']);
        self::assertSame('2026-07-29T10:50:00.000000Z', $events->current()->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame(2, $funding->calls);
        self::assertContains(3000.0, $loop->intervals());
        $source->stop();
    }

    private string $directory;

    protected function setUp(): void
    {
        $root = realpath(sys_get_temp_dir());
        self::assertIsString($root);
        $this->directory = $root . '/hyperliquid-source-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory) || !is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->directory,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testHappyPathSubscribesAndNormalizesTradesBookAndClosedCandle(): void
    {
        $transport = new DeterministicHyperliquidTransport(self::marketFrames());
        $source = $this->source($transport);
        $generator = $source->events();
        self::assertInstanceOf(\Generator::class, $generator);

        $events = [];
        $generator->rewind();
        while ($generator->valid()) {
            $event = $generator->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $events[] = $event;
            $source->acknowledge($event->eventId);
            if (\count($events) === 6) {
                $source->requestHealthyOperatorStop();
            }
            $generator->next();
        }

        self::assertCount(12, $transport->sent);
        self::assertSame([
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            PaperMarketDataChannel::PUBLIC_TRADE,
            PaperMarketDataChannel::PUBLIC_TRADE,
            PaperMarketDataChannel::TOP_OF_BOOK,
            PaperMarketDataChannel::CANDLE_1M,
        ], array_map(
            static fn (PaperMarketEvent $event): PaperMarketDataChannel => $event->channel,
            $events,
        ));
        self::assertSame(['42', '43'], [
            $events[2]->payload['trade_id'],
            $events[3]->payload['trade_id'],
        ]);
        self::assertFalse($events[4]->payload['synthetic']);
        self::assertSame('0', $events[5]->payload['start_time']);
        self::assertTrue($source->isComplete());
        self::assertNull($source->failureReason());
        self::assertTrue($transport->closed);
    }

    public function testLargeTradeFrameFitsAfterCheckpointHistoryReachesItsWindow(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $state = $checkpoint->toArray();
        $state['acknowledged_identities'] = array_map(
            static fn (int $index): string => hash('sha256', 'ack-' . $index),
            range(1, HyperliquidPaperLiveCheckpoint::MAXIMUM_ACKNOWLEDGED_IDENTITIES),
        );
        $state['trade_identity_history'] = array_map(
            static fn (int $index): array => [
                'identity_hash' => hash('sha256', 'identity-' . $index),
                'assignment_digest' => hash('sha256', 'assignment-' . $index),
            ],
            range(1, HyperliquidPaperLiveCheckpoint::MAXIMUM_TRADE_IDENTITIES),
        );
        $store->save(HyperliquidPaperLiveCheckpoint::fromArray($state));

        $source = $this->source(new DeterministicHyperliquidTransport([
            self::largeTradeFrame(),
        ]));
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $event->channel);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        self::assertInstanceOf(PaperMarketEvent::class, $events->current());
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $events->current()->channel);
        self::assertCount(
            HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS - 1,
            $this->checkpoint()->pendingContinuation['remaining_trade_rows'] ?? [],
        );
        self::assertLessThanOrEqual(
            HyperliquidPaperLiveCheckpoint::MAXIMUM_BYTES,
            strlen(CanonicalJson::encode($this->checkpoint()->toArray())),
        );
        $source->stop();
    }

    public function testOpenKeepsTheLoopRunningUntilAsyncSubscriptionAcknowledgement(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new AsyncAcknowledgementHyperliquidTransport($loop);
        $source = $this->source($transport, loop: $loop);
        $events = self::generator($source->events());

        $events->rewind();
        self::assertTrue($events->valid());
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $events->current()->channel);
        $source->acknowledge($events->current()->eventId);
        $events->next();
        self::assertTrue($events->valid());
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $events->current()->channel);
        $source->acknowledge($events->current()->eventId);
        $source->requestHealthyOperatorStop();
        $events->next();

        self::assertFalse($events->valid());
        self::assertCount(12, $transport->sent);
        self::assertTrue($transport->closed);
    }

    public function testDurationStopCompletesWhileWaitingForTheNextFrame(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport(self::marketFrames());
        $source = $this->source($transport, loop: $loop);
        $stops = new PaperPublicCaptureStopController($loop, $source);
        $stops->start(300);
        $events = self::generator($source->events());

        $events->rewind();
        for ($eventCount = 0; $eventCount < 6; ++$eventCount) {
            self::assertTrue($events->valid());
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($eventCount < 5) {
                $events->next();
            }
        }

        $loop->onNextRun(static function () use ($loop, $transport): void {
            $loop->fire(300.0);
            $lateTrade = json_decode(self::tradeFrame(), true, 512, \JSON_THROW_ON_ERROR);
            $lateTrade['data'][0]['hash'] = '0xaaa';
            $lateTrade['data'][0]['time'] = 2_000;
            $lateTrade['data'][0]['tid'] = 44;
            $transport->push(CanonicalJson::encode($lateTrade));
        });
        try {
            $events->next();
        } finally {
            $stops->close();
        }

        self::assertFalse($events->valid());
        self::assertTrue($source->isComplete());
        self::assertNull($source->failureReason());
        self::assertTrue($transport->closed);
    }

    public function testHealthyStopCannotBeRequestedWithAPendingEvent(): void
    {
        $source = $this->source(
            new DeterministicHyperliquidTransport(self::marketFrames()),
        );
        $events = self::generator($source->events());
        $events->rewind();
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);
        self::assertNotNull($this->checkpoint()->pendingEvent);

        try {
            $source->requestHealthyOperatorStop();
            self::fail('A pending event must prevent the healthy stop request.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_healthy_stop_invalid',
                $exception->getMessage(),
            );
        }

        self::assertSame('streaming', $this->checkpoint()->phase);
        self::assertFalse($source->isComplete());
        $source->stop();
    }

    public function testAcquisitionDisabledFailsBeforeConnect(): void
    {
        $disabledTransport = new DeterministicHyperliquidTransport([]);
        $disabled = $this->source($disabledTransport, enabled: false);
        try {
            self::generator($disabled->events())->rewind();
            self::fail('Expected acquisition disabled.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_acquisition_disabled',
                $exception->getMessage(),
            );
        }
        self::assertSame(0, $disabledTransport->connectCount);
    }

    public function testMarketDataInterleavedWithSubscriptionAcknowledgementsIsBuffered(): void
    {
        $premature = new DeterministicHyperliquidTransport(
            [],
            prematureFrame: self::tradeFrame(),
        );
        $source = $this->source($premature);
        $events = self::generator($source->events());

        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            self::assertTrue($events->valid());
            self::assertSame(
                PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
                $events->current()->channel,
            );
            $source->acknowledge($events->current()->eventId);
            $events->next();
        }
        self::assertTrue($events->valid());
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $events->current()->channel);
        self::assertSame('42', $events->current()->payload['trade_id']);
        $source->acknowledge($events->current()->eventId);
        $source->requestHealthyOperatorStop();
        $events->next();

        self::assertFalse($events->valid());
        self::assertTrue($premature->closed);
    }

    public function testUnexpectedFailureKeepsItsCauseBehindTheStablePublicReason(): void
    {
        $source = $this->source(
            new DeterministicHyperliquidTransport([]),
            metadataClient: new class implements HyperliquidPaperInstrumentMetadataClientInterface {
                public function instrumentMetadata(): array
                {
                    throw new \LogicException('private_diagnostic_detail');
                }
            },
        );

        try {
            self::generator($source->events())->rewind();
            self::fail('Expected the metadata failure to stop the source.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_protocol_error',
                $exception->getMessage(),
            );
            self::assertInstanceOf(\LogicException::class, $exception->getPrevious());
            self::assertSame(
                'private_diagnostic_detail',
                $exception->getPrevious()->getMessage(),
            );
        }
    }

    public function testGeneratorCannotAdvanceWithoutAcknowledgement(): void
    {
        $source = $this->source(new DeterministicHyperliquidTransport([]));
        $generator = self::generator($source->events());
        $generator->rewind();
        self::assertInstanceOf(PaperMarketEvent::class, $generator->current());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'hyperliquid_acquisition_pending_event_not_acknowledged',
        );
        $generator->next();
    }

    public function testCheckpointNetworkMismatchFailsBeforeConnect(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $transport = new DeterministicHyperliquidTransport([]);
        $config = new HyperliquidPaperPublicConfig(
            PaperMarketDataNetwork::TESTNET,
            true,
            HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
            HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
            $this->directory,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_live_checkpoint_mismatch');
        new HyperliquidPaperPublicLiveSource(
            $transport,
            $config,
            new MockClock('2026-07-29T10:00:00Z'),
            $store,
            $checkpoint,
            new StreamSelectLoop(),
        );
    }

    public function testRestartReyieldsExactPendingEventBeforeConnecting(): void
    {
        $firstTransport = new DeterministicHyperliquidTransport([]);
        $first = $this->source(
            $firstTransport,
            metadataClient: new StaticHyperliquidPaperMetadataClient(),
        );
        $firstEvents = self::generator($first->events());
        $firstEvents->rewind();
        $pending = $firstEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame(PaperMarketDataChannel::INSTRUMENT_METADATA, $pending->channel);

        $resumedTransport = new DeterministicHyperliquidTransport([]);
        $resumed = $this->source(
            $resumedTransport,
            metadataClient: new StaticHyperliquidPaperMetadataClient(),
        );
        $resumedEvents = self::generator($resumed->events());
        $resumedEvents->rewind();

        self::assertSame(
            CanonicalJson::encode($pending->toArray()),
            CanonicalJson::encode($resumedEvents->current()->toArray()),
        );
        self::assertSame(0, $resumedTransport->connectCount);
        $resumed->acknowledge($pending->eventId);
    }

    #[DataProvider('unfinishedSetupPhases')]
    public function testRestartFromUnfinishedSetupEmitsInitialBoundaries(
        string $phase,
    ): void {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $store->save($checkpoint->withPhase($phase));
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source(
            $transport,
            loop: new HyperliquidDeterministicLoop(),
        );
        $events = self::generator($source->events());

        $events->rewind();

        self::assertSame(1, $transport->connectCount);
        self::assertSame(
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            $events->current()->channel,
        );
        self::assertSame('initial', $events->current()->payload['reason']);
    }

    /** @return iterable<string, array{string}> */
    public static function unfinishedSetupPhases(): iterable
    {
        yield 'connecting' => ['connecting'];
        yield 'subscribing' => ['subscribing'];
    }

    public function testRestartFromStoppingCompletesThePersistedHealthyStop(): void
    {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $store->save(
            $checkpoint->withPhase('streaming')->requestHealthyStop(),
        );
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source($transport);

        $events = self::generator($source->events());
        $events->rewind();

        $persisted = $this->checkpoint();
        self::assertFalse($events->valid());
        self::assertSame('complete', $persisted->phase);
        self::assertTrue($persisted->continuity);
        self::assertTrue($source->isComplete());
        self::assertNull($source->failureReason());
        self::assertSame(0, $transport->connectCount);
        self::assertTrue($transport->closed);
    }

    public function testOverlappingTradeBatchRedeliveryIsIgnoredBeforeNewOrdinals(): void
    {
        $trade = self::tradeFrame(twoRows: true);
        $source = $this->source(
            new DeterministicHyperliquidTransport([$trade, $trade]),
        );
        $events = self::generator($source->events());
        $events->rewind();

        $tradeIds = [];
        for ($index = 0; $index < 4; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            if ($event->channel === PaperMarketDataChannel::PUBLIC_TRADE) {
                $tradeIds[] = $event->payload['trade_id'];
            }
            $source->acknowledge($event->eventId);
            if ($index < 3) {
                $events->next();
            }
        }
        self::assertSame(['42', '43'], $tradeIds);

        $source->requestHealthyOperatorStop();
        self::assertSame('streaming', $this->checkpoint()->phase);
        $events->next();

        self::assertFalse($events->valid());
        self::assertTrue($source->isComplete());
    }

    public function testBufferedDuplicateFramesCannotStarveTheNetworkLoop(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $trade = self::tradeFrame();
        $source = $this->source(
            new DeterministicHyperliquidTransport([
                $trade,
                $trade,
                self::largeTradeFrame(1),
            ]),
            loop: $loop,
        );
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $source->acknowledge($events->current()->eventId);
            $events->next();
        }
        self::assertSame('42', $events->current()->payload['trade_id']);
        $source->acknowledge($events->current()->eventId);
        $baselineRuns = $loop->runCount();

        $events->next();

        self::assertSame('1', $events->current()->payload['trade_id']);
        self::assertSame($baselineRuns + 2, $loop->runCount());
    }

    public function testRestartResumesTheCompactRemainderOfATradeFrame(): void
    {
        $firstSource = $this->source(new DeterministicHyperliquidTransport([
            self::tradeFrame(twoRows: true),
        ]));
        $firstEvents = self::generator($firstSource->events());
        $firstEvents->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $boundary = $firstEvents->current();
            self::assertInstanceOf(PaperMarketEvent::class, $boundary);
            $firstSource->acknowledge($boundary->eventId);
            $firstEvents->next();
        }
        $pending = $firstEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame('42', $pending->payload['trade_id']);

        $resumedTransport = new DeterministicHyperliquidTransport([]);
        $resumed = $this->source($resumedTransport);
        $resumedEvents = self::generator($resumed->events());
        $resumedEvents->rewind();

        self::assertSame(
            CanonicalJson::encode($pending->toArray()),
            CanonicalJson::encode($resumedEvents->current()->toArray()),
        );
        self::assertSame(0, $resumedTransport->connectCount);
        $resumed->acknowledge($pending->eventId);
        $resumedEvents->next();

        $second = $resumedEvents->current();
        self::assertInstanceOf(PaperMarketEvent::class, $second);
        self::assertSame('43', $second->payload['trade_id']);
        self::assertSame(0, $resumedTransport->connectCount);
    }

    public function testLargeTradeFramePumpsTheNetworkAfterEveryDurableChunk(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $source = $this->source(
            new DeterministicHyperliquidTransport([
                self::largeTradeFrame(HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS + 1),
            ]),
            loop: $loop,
        );
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $source->acknowledge($events->current()->eventId);
            $events->next();
        }
        $baselineRuns = $loop->runCount();

        for ($index = 0; $index < HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS; ++$index) {
            self::assertSame((string) ($index + 1), $events->current()->payload['trade_id']);
            $source->acknowledge($events->current()->eventId);
            if ($index + 1 < HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS) {
                $events->next();
            }
        }

        self::assertSame($baselineRuns + 1, $loop->runCount());
        $events->next();
        self::assertSame(
            (string) (HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS + 1),
            $events->current()->payload['trade_id'],
        );
        $source->acknowledge($events->current()->eventId);
        self::assertSame($baselineRuns + 2, $loop->runCount());
    }

    public function testTradeCheckpointIsCommittedOnlyAtDurableChunkBoundaries(): void
    {
        $source = $this->source(new DeterministicHyperliquidTransport([
            self::largeTradeFrame(8),
        ]));
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $source->acknowledge($events->current()->eventId);
            $events->next();
        }

        self::assertSame('1', $events->current()->payload['trade_id']);
        $durablePendingEvent = $this->checkpoint()->pendingEvent;
        self::assertInstanceOf(PaperMarketEvent::class, $durablePendingEvent);
        self::assertSame('1', $durablePendingEvent->payload['trade_id']);

        $source->acknowledge($events->current()->eventId);
        $events->next();

        self::assertSame('2', $events->current()->payload['trade_id']);
        $durablePendingEvent = $this->checkpoint()->pendingEvent;
        self::assertInstanceOf(PaperMarketEvent::class, $durablePendingEvent);
        self::assertSame(
            '1',
            $durablePendingEvent->payload['trade_id'],
            'The durable checkpoint must remain at the start of the in-flight trade chunk.',
        );

        for ($tradeId = 2; $tradeId <= 8; ++$tradeId) {
            self::assertSame((string) $tradeId, $events->current()->payload['trade_id']);
            $source->acknowledge($events->current()->eventId);
            if ($tradeId < 8) {
                $events->next();
            }
        }

        $checkpoint = $this->checkpoint();
        self::assertNull($checkpoint->pendingEvent);
        self::assertCount(8, $checkpoint->tradeIdentityHistory);
    }

    public function testExposesCurrentDurableTradeBatchBoundary(): void
    {
        $source = $this->source(new DeterministicHyperliquidTransport([
            self::largeTradeFrame(8),
        ]));
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            self::assertSame(1, $source->pendingDurableBatchSize());
            $source->acknowledge($events->current()->eventId);
            $events->next();
        }

        for ($remaining = 8; $remaining >= 1; --$remaining) {
            self::assertSame($remaining, $source->pendingDurableBatchSize());
            $source->acknowledge($events->current()->eventId);
            if ($remaining > 1) {
                $events->next();
            }
        }
    }

    public function testIngressPausesAtHighWaterAndResumesAfterDrain(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source(
            $transport,
            loop: $loop,
        );
        $events = self::generator($source->events());
        $events->rewind();
        $source->acknowledge($events->current()->eventId);
        $events->next();
        for ($index = 0; $index < HyperliquidPaperLivePolicy::NETWORK_PUMP_FRAME_HIGH_WATER; ++$index) {
            $transport->push(self::tradeFrameForId($index + 1));
        }

        self::assertSame(1, $transport->pauseCount);
        self::assertSame(0, $transport->resumeCount);

        $source->acknowledge($events->current()->eventId);
        for ($index = 0; $index < 64; ++$index) {
            $events->next();
            $source->acknowledge($events->current()->eventId);
        }

        self::assertSame(1, $transport->pauseCount);
        self::assertSame(1, $transport->resumeCount);
    }

    public function testIngressPausesBeforeQueuedBytesCanExhaustCapacity(): void
    {
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source($transport);
        $events = self::generator($source->events());
        $events->rewind();
        $source->acknowledge($events->current()->eventId);
        $events->next();
        $halfOfMaximumFrame = str_repeat(
            'x',
            intdiv(HyperliquidPaperLivePolicy::MAX_FRAME_BYTES, 2),
        );

        $transport->push($halfOfMaximumFrame);
        $transport->push($halfOfMaximumFrame);

        self::assertSame(1, $transport->pauseCount);
    }

    public function testUnhealthyStopAfterStreamingPersistsContinuityLoss(): void
    {
        $source = $this->source(new DeterministicHyperliquidTransport([]));
        $events = self::generator($source->events());
        $events->rewind();
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);

        $source->stop();

        $checkpoint = (new HyperliquidPaperLiveCheckpointStore($this->directory))
            ->loadOrCreate(
                'paper-hyperliquid-live-mainnet',
                PaperMarketDataNetwork::MAINNET,
                str_repeat('a', 64),
            );
        self::assertFalse($checkpoint->continuity);
        self::assertSame(
            'hyperliquid_public_trade_gap_unrecoverable',
            $checkpoint->failureReason,
        );
        self::assertFalse($source->isComplete());
    }

    public function testHeartbeatSendsExactPingAndPongRefreshesDeadline(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source($transport, loop: $loop);
        $events = self::generator($source->events());
        $events->rewind();

        self::assertSame([5.0], $loop->intervals());
        self::assertSame(5.0, $loop->fire(5.0));
        self::assertSame(['method' => 'ping'], $transport->sent[12]);
        self::assertSame([10.0], $loop->intervals());

        $transport->push(CanonicalJson::encode(['channel' => 'pong']));
        $transport->push(self::tradeFrame());
        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        $source->acknowledge($first->eventId);
        $events->next();
        $second = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $second);
        $source->acknowledge($second->eventId);
        $events->next();

        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $events->current()->channel);
        self::assertSame([5.0], $loop->intervals());
    }

    public function testHeartbeatPongOvertakesBufferedMarketFrames(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source($transport, loop: $loop);
        $events = self::generator($source->events());
        $events->rewind();

        self::assertSame(5.0, $loop->fire(5.0));
        self::assertSame(['method' => 'ping'], $transport->sent[12]);
        self::assertSame([10.0], $loop->intervals());

        $transport->push(self::tradeFrame());
        $transport->push(CanonicalJson::encode(['channel' => 'pong']));

        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            $events->next();
        }

        $trade = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $trade);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $trade->channel);
        self::assertSame('42', $trade->payload['trade_id']);
        self::assertSame([5.0], $loop->intervals());
    }

    public function testDurableAcknowledgementCooperativelyPumpsTheNetworkLoop(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $source = $this->source(
            new DeterministicHyperliquidTransport([]),
            loop: $loop,
        );
        $events = self::generator($source->events());
        $events->rewind();
        $runsBeforeAcknowledgement = $loop->runCount();

        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);
        self::assertSame($runsBeforeAcknowledgement, $loop->runCount());

        $events->next();
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        $source->acknowledge($event->eventId);

        self::assertSame($runsBeforeAcknowledgement + 1, $loop->runCount());
    }

    public function testPongTimeoutPersistsContinuityLossBeforeReconnectDelay(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source(
            $transport,
            loop: $loop,
        );
        self::generator($source->events())->rewind();

        $loop->fire(5.0);
        $loop->fire(10.0);
        $transport->push(CanonicalJson::encode(['channel' => 'pong']));
        $transport->push(self::tradeFrame());

        $checkpoint = $this->checkpoint();
        self::assertFalse($checkpoint->continuity);
        self::assertSame('reconnecting', $checkpoint->phase);
        self::assertSame(1, $checkpoint->reconnectAttempt);
        self::assertSame([1.0], $loop->intervals());
        self::assertTrue($transport->closed);
    }

    public function testCloseWhileWaitingLetsTheScheduledReconnectRun(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source($transport, loop: $loop);
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index === 0) {
                $events->next();
            }
        }
        $loop->enqueue(static fn () => $transport->serverClose());
        $loop->enqueue(static fn () => $loop->fire(1.0));

        $events->next();

        self::assertTrue($events->valid());
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $events->current()->channel);
        self::assertSame('reconnect', $events->current()->payload['reason']);
        self::assertSame(2, $transport->connectCount);
        self::assertFalse($this->checkpoint()->continuity);
    }

    public function testReconnectBuffersMarketFrameUntilAllSubscriptionsAreReady(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport(
            [],
            reconnectPrematureFrame: self::tradeFrame(),
        );
        $source = $this->source($transport, loop: $loop);
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index === 0) {
                $events->next();
            }
        }
        $loop->enqueue(static fn () => $transport->serverClose());
        $loop->enqueue(static fn () => $loop->fire(1.0));

        $events->next();
        $btcBoundary = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $btcBoundary);
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $btcBoundary->channel);
        self::assertSame('reconnect', $btcBoundary->payload['reason']);
        $source->acknowledge($btcBoundary->eventId);
        $events->next();
        $ethBoundary = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $ethBoundary);
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $ethBoundary->channel);
        $source->acknowledge($ethBoundary->eventId);

        $events->next();

        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $events->current()->channel);
        self::assertSame(2, $transport->connectCount);
        self::assertFalse($this->checkpoint()->continuity);
    }

    public function testReconnectUsesBoundedDelaysAndThenFailsTerminally(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source($transport, loop: $loop);
        self::generator($source->events())->rewind();
        $loop->fire(5.0);
        $loop->fire(10.0);

        foreach ([1.0, 2.0, 4.0, 8.0, 15.0, 30.0] as $delay) {
            self::assertSame($delay, $loop->fire($delay));
            $transport->serverClose();
        }

        $checkpoint = $this->checkpoint();
        self::assertSame('failed', $checkpoint->phase);
        self::assertSame(
            'hyperliquid_paper_public_reconnect_exhausted',
            $checkpoint->failureReason,
        );
        self::assertSame([], $loop->intervals());
    }

    public function testReconnectMetadataAndBoundariesPrecedeQueuedBookAndCannotCertify(): void
    {
        $loop = new HyperliquidDeterministicLoop();
        $transport = new DeterministicHyperliquidTransport([]);
        $source = $this->source(
            $transport,
            loop: $loop,
            metadataClient: new StaticHyperliquidPaperMetadataClient(),
        );
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 4; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index < 3) {
                $events->next();
            }
        }

        $loop->fire(5.0);
        $loop->fire(10.0);
        $loop->fire(1.0);
        $transport->push(CanonicalJson::encode([
            'channel' => 'l2Book',
            'data' => [
                'coin' => 'BTC',
                'levels' => [
                    [['px' => '1', 'sz' => '1', 'n' => 1]],
                    [['px' => '2', 'sz' => '1', 'n' => 1]],
                ],
                'time' => 2_000,
            ],
        ]));

        $events->next();
        self::assertSame(PaperMarketDataChannel::INSTRUMENT_METADATA, $events->current()->channel);
        self::assertSame('BTCUSDT', $events->current()->symbol);
        self::assertSame(2, $events->current()->payload['source_epoch']);
        $source->acknowledge($events->current()->eventId);
        $events->next();
        self::assertSame(PaperMarketDataChannel::INSTRUMENT_METADATA, $events->current()->channel);
        self::assertSame('ETHUSDT', $events->current()->symbol);
        self::assertSame(2, $events->current()->payload['source_epoch']);
        $source->acknowledge($events->current()->eventId);
        $events->next();
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $events->current()->channel);
        self::assertSame('reconnect', $events->current()->payload['reason']);
        $source->acknowledge($events->current()->eventId);
        $events->next();
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $events->current()->channel);
        $source->acknowledge($events->current()->eventId);
        $events->next();
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $events->current()->channel);
        self::assertFalse($source->isComplete());
        try {
            $source->requestHealthyOperatorStop();
            self::fail('Continuity-lost capture cannot complete.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_healthy_stop_invalid',
                $exception->getMessage(),
            );
        }
    }

    public function testBackpressureFailsWithStableReason(): void
    {
        $frames = array_fill(0, 257, self::tradeFrame());
        $source = $this->source(new DeterministicHyperliquidTransport($frames));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('market_data_backpressure_exhausted');
        self::generator($source->events())->rewind();
    }

    public function testConflictingTradeIdentityFailsWithStableReason(): void
    {
        $conflicting = json_decode(self::tradeFrame(), true, 512, \JSON_THROW_ON_ERROR);
        $conflicting['data'][] = array_replace(
            $conflicting['data'][0],
            ['px' => '65001'],
        );
        $source = $this->source(new DeterministicHyperliquidTransport([
            CanonicalJson::encode($conflicting),
        ]));
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index === 0) {
                $events->next();
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('market_event_identity_conflict');
        $events->next();
    }

    public function testTerminalFrameFailureClosesTransportAndInvalidatesCallbacks(): void
    {
        $transport = new DeterministicHyperliquidTransport([
            '{"channel":"trades","data":"invalid"}',
        ]);
        $source = $this->source($transport);
        $events = self::generator($source->events());
        $events->rewind();
        for ($index = 0; $index < 2; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $source->acknowledge($event->eventId);
            if ($index === 0) {
                $events->next();
            }
        }

        try {
            $events->next();
            self::fail('Invalid market data must fail the capture.');
        } catch (\RuntimeException) {
            self::assertTrue($transport->closed);
        }
    }

    private function source(
        HyperliquidPaperPublicWebSocketTransportInterface $transport,
        bool $enabled = true,
        ?LoopInterface $loop = null,
        ?HyperliquidPaperInstrumentMetadataClientInterface $metadataClient = null,
        ?HyperliquidPaperFundingRateClientInterface $fundingClient = null,
        ?MockClock $clock = null,
        ?HyperliquidPaperPublicRestClientInterface $restClient = null,
        ?HyperliquidPaperPublicFrameQueue $queue = null,
    ): HyperliquidPaperPublicLiveSource {
        $store = new HyperliquidPaperLiveCheckpointStore($this->directory);
        $checkpoint = $store->loadOrCreate(
            'paper-hyperliquid-live-mainnet',
            PaperMarketDataNetwork::MAINNET,
            str_repeat('a', 64),
        );
        $config = new HyperliquidPaperPublicConfig(
            PaperMarketDataNetwork::MAINNET,
            $enabled,
            HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
            HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
            $this->directory,
        );

        return new HyperliquidPaperPublicLiveSource(
            $transport,
            $config,
            $clock ?? new MockClock('2026-07-29T10:00:00Z'),
            $store,
            $checkpoint,
            $loop ?? new StreamSelectLoop(),
            queue: $queue,
            metadataClient: $metadataClient,
            fundingClient: $fundingClient,
            restClient: $restClient,
        );
    }

    private function checkpoint(): \App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpoint
    {
        return (new HyperliquidPaperLiveCheckpointStore($this->directory))
            ->loadOrCreate(
                'paper-hyperliquid-live-mainnet',
                PaperMarketDataNetwork::MAINNET,
                str_repeat('a', 64),
            );
    }

    /** @return list<string> */
    private static function marketFrames(): array
    {
        return [
            self::tradeFrame(twoRows: true),
            CanonicalJson::encode([
                'channel' => 'l2Book',
                'data' => [
                    'coin' => 'BTC',
                    'levels' => [
                        [['px' => '64999', 'sz' => '1', 'n' => 1]],
                        [['px' => '65001', 'sz' => '2', 'n' => 1]],
                    ],
                    'time' => 1_001,
                ],
            ]),
            self::candleFrame(0, '2'),
            self::candleFrame(60_000, '3'),
        ];
    }

    private static function tradeFrame(bool $twoRows = false): string
    {
        $rows = [[
            'coin' => 'BTC',
            'side' => 'B',
            'px' => '65000',
            'sz' => '0.01',
            'hash' => '0xabc',
            'time' => 1_000,
            'tid' => 42,
            'users' => ['0xa', '0xb'],
        ]];
        if ($twoRows) {
            $rows[] = [
                'coin' => 'BTC',
                'side' => 'A',
                'px' => '65001',
                'sz' => '0.02',
                'hash' => '0xdef',
                'time' => 1_001,
                'tid' => 43,
                'users' => ['0xb', '0xa'],
            ];
        }

        return CanonicalJson::encode(['channel' => 'trades', 'data' => $rows]);
    }

    private static function tradeFrameForId(int $tradeId): string
    {
        $decoded = json_decode(self::tradeFrame(), true, 512, \JSON_THROW_ON_ERROR);
        $decoded['data'][0]['hash'] = '0x' . hash('sha256', 'queued-trade-' . $tradeId);
        $decoded['data'][0]['time'] = 1_000 + $tradeId;
        $decoded['data'][0]['tid'] = $tradeId;

        return CanonicalJson::encode($decoded);
    }

    private static function largeTradeFrame(int $rowCount = 1_000): string
    {
        $rows = [];
        for ($index = 1; $index <= $rowCount; ++$index) {
            $rows[] = [
                'coin' => $index % 2 === 0 ? 'BTC' : 'ETH',
                'side' => $index % 2 === 0 ? 'B' : 'A',
                'px' => $index % 2 === 0 ? '65000' : '2500',
                'sz' => '0.01',
                'hash' => '0x' . hash('sha256', 'trade-' . $index),
                'time' => 1_000 + $index,
                'tid' => $index,
                'users' => [
                    '0x' . hash('sha256', 'maker-' . $index),
                    '0x' . hash('sha256', 'taker-' . $index),
                ],
            ];
        }

        return CanonicalJson::encode(['channel' => 'trades', 'data' => $rows]);
    }

    private static function candleFrame(int $start, string $close): string
    {
        return CanonicalJson::encode([
            'channel' => 'candle',
            'data' => [
                'T' => $start + 59_999,
                'c' => $close,
                'h' => '3',
                'i' => '1m',
                'l' => '0.5',
                'n' => 5,
                'o' => '1',
                's' => 'BTC',
                't' => $start,
                'v' => '4',
            ],
        ]);
    }

    /** @param iterable<PaperMarketEvent> $events */
    private static function generator(iterable $events): \Generator
    {
        self::assertInstanceOf(\Generator::class, $events);

        return $events;
    }
}

final class AsyncAcknowledgementHyperliquidTransport implements
    HyperliquidPaperPublicWebSocketTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    public bool $closed = false;
    public int $pauseCount = 0;
    public int $resumeCount = 0;

    /** @var callable(string): void|null */
    private $onMessage = null;

    public function __construct(private readonly HyperliquidDeterministicLoop $loop)
    {
    }

    public function connect(
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        $this->onMessage = $onMessage;
        $this->loop->enqueue($onOpen(...));
    }

    public function send(array $message): void
    {
        $this->sent[] = $message;
        if ($message === ['method' => 'ping']) {
            return;
        }

        $onMessage = $this->onMessage ?? throw new \LogicException();
        $this->loop->enqueue(static function () use ($message, $onMessage): void {
            $onMessage(CanonicalJson::encode([
                'channel' => 'subscriptionResponse',
                'data' => $message,
            ]));
        });
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function pauseReading(): void
    {
        ++$this->pauseCount;
    }

    public function resumeReading(): void
    {
        ++$this->resumeCount;
    }
}

final class StaticHyperliquidPaperMetadataClient implements HyperliquidPaperInstrumentMetadataClientInterface
{
    public function instrumentMetadata(): array
    {
        return [
            ['coin' => 'BTC', 'asset_id' => 0, 'sz_decimals' => 5, 'max_leverage' => 50],
            ['coin' => 'ETH', 'asset_id' => 1, 'sz_decimals' => 4, 'max_leverage' => 25],
        ];
    }
}

final class StaticHyperliquidPaperFundingClient implements HyperliquidPaperFundingRateClientInterface
{
    public int $calls = 0;

    public function fundingRates(): array
    {
        ++$this->calls;

        return [
            ['coin' => 'BTC', 'funding_rate' => '0.0000125'],
            ['coin' => 'ETH', 'funding_rate' => '-0.000025'],
        ];
    }
}

final class LiveSourceWarmupRestClient implements HyperliquidPaperPublicRestClientInterface
{
    /** @var list<array{string, string, int, int}> */
    public array $requests = [];

    public function __construct(
        private readonly PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
    ) {
    }

    public function network(): PaperMarketDataNetwork
    {
        return $this->network;
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        $this->requests[] = [$coin, $interval, $startTime, $endTime];
        $step = ['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000][$interval];
        $rows = [];
        for ($start = $startTime; $start <= $endTime; $start += $step) {
            $rows[] = [
                'T' => $start + $step - 1,
                'c' => '100', 'h' => '101', 'i' => $interval,
                'l' => '99', 'n' => 1, 'o' => '100', 's' => $coin,
                't' => $start, 'v' => '10',
            ];
        }

        return $rows;
    }
}

final class DeterministicHyperliquidTransport implements
    HyperliquidPaperPublicWebSocketTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    public int $connectCount = 0;
    public bool $closed = false;
    public int $pauseCount = 0;
    public int $resumeCount = 0;

    /** @var callable(string): void|null */
    private $onMessage = null;
    /** @var callable(?int): void|null */
    private $onClose = null;

    /**
     * @param list<string> $marketFrames
     */
    public function __construct(
        private readonly array $marketFrames,
        private readonly ?string $prematureFrame = null,
        private readonly ?string $reconnectPrematureFrame = null,
    ) {
    }

    public function connect(
        callable $onOpen,
        callable $onMessage,
        callable $onClose,
        callable $onError,
    ): void {
        ++$this->connectCount;
        $this->onMessage = $onMessage;
        $this->onClose = $onClose;
        $onOpen();
    }

    public function send(array $message): void
    {
        $this->sent[] = $message;
        if ($message === ['method' => 'ping']) {
            return;
        }
        $onMessage = $this->onMessage ?? throw new \LogicException();
        if ($this->connectCount > 1
            && \count($this->sent) === 13
            && $this->reconnectPrematureFrame !== null
        ) {
            $onMessage($this->reconnectPrematureFrame);
        }
        $onMessage(CanonicalJson::encode([
            'channel' => 'subscriptionResponse',
            'data' => $message,
        ]));
        if (\count($this->sent) === 1 && $this->prematureFrame !== null) {
            $onMessage($this->prematureFrame);
        }
        if (\count($this->sent) === 12) {
            foreach ($this->marketFrames as $frame) {
                $onMessage($frame);
            }
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function pauseReading(): void
    {
        ++$this->pauseCount;
    }

    public function resumeReading(): void
    {
        ++$this->resumeCount;
    }

    public function push(string $frame): void
    {
        ($this->onMessage ?? throw new \LogicException())($frame);
    }

    public function serverClose(): void
    {
        ($this->onClose ?? throw new \LogicException())(1006);
    }
}

final class HyperliquidDeterministicLoop implements LoopInterface
{
    /** @var list<TimerInterface> */
    private array $timers = [];

    /** @var list<\Closure(): void> */
    private array $onNextRuns = [];

    private int $stopGeneration = 0;
    private int $runCount = 0;

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer((float) $interval, $callback);
        $this->timers[] = $timer;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer((float) $interval, $callback, true);
        $this->timers[] = $timer;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (TimerInterface $candidate): bool => $candidate !== $timer,
        ));
    }

    public function futureTick($listener): void
    {
        $listener();
    }

    public function addSignal($signal, $listener): void
    {
    }

    public function removeSignal($signal, $listener): void
    {
    }

    public function run(): void
    {
        ++$this->runCount;
        $stopGeneration = $this->stopGeneration;
        while ($this->onNextRuns !== []) {
            $callback = array_shift($this->onNextRuns);
            $callback();
            if ($stopGeneration !== $this->stopGeneration) {
                return;
            }
        }
    }

    public function stop(): void
    {
        ++$this->stopGeneration;
    }

    public function onNextRun(\Closure $callback): void
    {
        $this->onNextRuns[] = $callback;
    }

    public function enqueue(callable $callback): void
    {
        $this->onNextRuns[] = $callback(...);
    }

    /** @return list<float> */
    public function intervals(): array
    {
        return array_map(
            static fn (TimerInterface $timer): float => $timer->getInterval(),
            $this->timers,
        );
    }

    public function runCount(): int
    {
        return $this->runCount;
    }

    public function fire(float $interval): float
    {
        foreach ($this->timers as $index => $timer) {
            if ($timer->getInterval() !== $interval) {
                continue;
            }
            array_splice($this->timers, $index, 1);
            ($timer->getCallback())();

            return $interval;
        }

        throw new \LogicException('timer_not_found');
    }
}
