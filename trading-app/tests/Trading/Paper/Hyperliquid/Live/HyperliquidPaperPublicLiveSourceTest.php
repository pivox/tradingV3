<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveCheckpointStore;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicLiveSource;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicWebSocketTransportInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperPublicLiveSource::class)]
final class HyperliquidPaperPublicLiveSourceTest extends TestCase
{
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

    public function testAcquisitionDisabledAndPrematureMarketDataFailBeforeAcceptance(): void
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

        $premature = new DeterministicHyperliquidTransport(
            [],
            prematureFrame: self::tradeFrame(),
        );
        try {
            self::generator($this->source($premature)->events())->rewind();
            self::fail('Expected pre-subscription market data rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_message_before_ready',
                $exception->getMessage(),
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

    private function source(
        DeterministicHyperliquidTransport $transport,
        bool $enabled = true,
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
            new MockClock('2026-07-29T10:00:00Z'),
            $store,
            $checkpoint,
            new StreamSelectLoop(),
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

final class DeterministicHyperliquidTransport implements
    HyperliquidPaperPublicWebSocketTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];
    public int $connectCount = 0;
    public bool $closed = false;

    /** @var callable(string): void|null */
    private $onMessage = null;

    /**
     * @param list<string> $marketFrames
     */
    public function __construct(
        private readonly array $marketFrames,
        private readonly ?string $prematureFrame = null,
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
        $onOpen();
    }

    public function send(array $message): void
    {
        $this->sent[] = $message;
        $onMessage = $this->onMessage ?? throw new \LogicException();
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
}
