<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLiveIntegrityException;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperLivePolicy;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicFrameDecoder;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicFrameQueue;
use App\Trading\Paper\Hyperliquid\Live\HyperliquidPaperPublicSubscriptionSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperPublicFrameDecoder::class)]
#[CoversClass(HyperliquidPaperPublicFrameQueue::class)]
final class HyperliquidPaperPublicFrameDecoderTest extends TestCase
{
    public function testDecodesOnlyTheFiveSupportedPublicMessageKinds(): void
    {
        $set = new HyperliquidPaperPublicSubscriptionSet();
        $decoder = new HyperliquidPaperPublicFrameDecoder($set);

        self::assertSame(['kind' => 'subscription', 'data' => [
            'method' => 'subscribe',
            'subscription' => ['type' => 'trades', 'coin' => 'BTC'],
        ]], $decoder->decode(
            '{"channel":"subscriptionResponse","data":{"method":"subscribe","subscription":{"type":"trades","coin":"BTC"}}}',
        ));
        self::assertSame(['kind' => 'pong'], $decoder->decode('{"channel":"pong"}'));

        $trade = [
            'coin' => 'BTC',
            'side' => 'B',
            'px' => '65000',
            'sz' => '0.01',
            'hash' => '0xabc',
            'time' => 1_000,
            'tid' => 42,
            'users' => ['0xa', '0xb'],
        ];
        self::assertSame(
            ['kind' => 'trades', 'data' => [$trade]],
            $decoder->decode(json_encode(
                ['channel' => 'trades', 'data' => [$trade]],
                \JSON_THROW_ON_ERROR,
            )),
        );

        $book = [
            'coin' => 'BTC',
            'levels' => [
                [
                    ['px' => '64999', 'sz' => '1', 'n' => 2],
                    ['px' => '64998', 'sz' => '2', 'n' => 1],
                ],
                [
                    ['px' => '65002', 'sz' => '1', 'n' => 1],
                    ['px' => '65001', 'sz' => '2', 'n' => 3],
                ],
            ],
            'time' => 1_001,
        ];
        self::assertSame(
            ['kind' => 'book', 'data' => $book],
            $decoder->decode(json_encode(
                ['channel' => 'l2Book', 'data' => $book],
                \JSON_THROW_ON_ERROR,
            )),
        );

        $candle = [
            't' => 0,
            'T' => 59_999,
            's' => 'BTC',
            'i' => '1m',
            'o' => '1',
            'c' => '2',
            'h' => '3',
            'l' => '0.5',
            'v' => '4',
            'n' => 5,
        ];
        self::assertSame(
            ['kind' => 'candle', 'data' => $candle],
            $decoder->decode(json_encode(
                ['channel' => 'candle', 'data' => $candle],
                \JSON_THROW_ON_ERROR,
            )),
        );
    }

    /**
     * The shape of the frame that stopped the 2026-10-02 capture at 04:19:58: Hyperliquid
     * sends every fill of a block in one trades message, and a buy sweep from 85,911 to
     * 85,999 produced more than the former 1000-row cap (the minute counted 3515 trades, of
     * which 1145 after the last recorded one). The exact frame was never stored anywhere.
     */
    public static function sweepFrame(int $rows = 1145): string
    {
        $trades = [];
        for ($index = 0; $index < $rows; ++$index) {
            $trades[] = [
                'coin' => 'BTC',
                'side' => 'B',
                'px' => (string) (85_911 + intdiv($index * 89, $rows)) . '.0',
                'sz' => '0.0' . str_pad((string) (1 + $index % 997), 4, '0', \STR_PAD_LEFT),
                'hash' => '0x' . hash('sha256', 'sweep-block-04:19:58'),
                'time' => 1_790_914_798_100,
                'tid' => 1_000_000_000_000 + $index * 7_919,
                'users' => [
                    '0x' . substr(hash('sha256', 'buyer'), 0, 40),
                    '0x' . substr(hash('sha256', 'maker-' . $index), 0, 40),
                ],
            ];
        }

        return json_encode(['channel' => 'trades', 'data' => $trades], \JSON_THROW_ON_ERROR);
    }

    public function testAcceptsTheWholeBlockOfASweepBeyondTheFormerRowCap(): void
    {
        $decoded = (new HyperliquidPaperPublicFrameDecoder(new HyperliquidPaperPublicSubscriptionSet()))
            ->decode(self::sweepFrame());

        self::assertSame('trades', $decoded['kind']);
        self::assertIsArray($decoded['data'] ?? null);
        self::assertCount(1145, $decoded['data']);
        self::assertSame('85911.0', $decoded['data'][0]['px']);
        self::assertSame('85999.0', $decoded['data'][1144]['px']);
    }

    public function testRowsAreBoundedByTheFrameSizeOnly(): void
    {
        $smallestRow = '{"coin":"BTC","side":"B","px":"1","sz":"1","hash":"0x1","time":0,"tid":0,"users":["0xa","0xb"]},';

        // More rows than the sanity bound cannot fit in a frame the transport accepts.
        self::assertGreaterThan(
            HyperliquidPaperLivePolicy::MAX_FRAME_BYTES,
            \strlen($smallestRow) * (HyperliquidPaperPublicFrameDecoder::MAX_TRADE_ROWS + 1),
        );
        self::assertGreaterThan(1145, HyperliquidPaperPublicFrameDecoder::MAX_TRADE_ROWS);
    }

    public function testDecodesBestBidAndOfferMessagesAsBooks(): void
    {
        $bbo = [
            'coin' => 'BTC',
            'time' => 1_790_920_637_778,
            'bbo' => [
                ['px' => '85937.0', 'sz' => '20.3216', 'n' => 55],
                ['px' => '85938.0', 'sz' => '0.0004', 'n' => 2],
            ],
        ];

        self::assertSame(
            ['kind' => 'book', 'data' => $bbo],
            (new HyperliquidPaperPublicFrameDecoder(new HyperliquidPaperPublicSubscriptionSet()))->decode(
                json_encode(['channel' => 'bbo', 'data' => $bbo], \JSON_THROW_ON_ERROR),
            ),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedFramesWithTheirCheck(): iterable
    {
        yield 'empty bbo side' => [
            '{"channel":"bbo","data":{"coin":"BTC","time":1,"bbo":[null,{"px":"2","sz":"1","n":1}]}}',
            'bbo_side_empty channel=bbo',
        ];
        yield 'crossed bbo' => [
            '{"channel":"bbo","data":{"coin":"ETH","time":1,"bbo":[{"px":"3","sz":"1","n":1},{"px":"2","sz":"1","n":1}]}}',
            'book_crossed channel=bbo',
        ];
        yield 'unknown channel' => [
            '{"channel":"notification","data":{"notification":"maintenance"}}',
            'channel_unknown channel=notification',
        ];
        yield 'trade with a new field' => [
            '{"channel":"trades","data":[{"coin":"BTC","side":"B","px":"1","sz":"1","hash":"0x1","time":1,"tid":1,"users":["0xa","0xb"],"liquidation":true}]}',
            'trade_keys channel=trades',
        ];
    }

    #[DataProvider('rejectedFramesWithTheirCheck')]
    public function testARejectionNamesItsCheckAndTheFrameWithoutItsContent(string $frame, string $check): void
    {
        try {
            (new HyperliquidPaperPublicFrameDecoder(new HyperliquidPaperPublicSubscriptionSet()))->decode($frame);
            self::fail('Expected frame rejection.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame('hyperliquid_paper_public_message_invalid', $exception->getMessage());
            $previous = $exception->getPrevious();
            self::assertInstanceOf(\InvalidArgumentException::class, $previous);
            self::assertStringStartsWith($check . ' bytes=' . \strlen($frame) . ' rows=', $previous->getMessage());
            self::assertStringEndsWith(' sha256=' . substr(hash('sha256', $frame), 0, 16), $previous->getMessage());
            self::assertStringNotContainsString('maintenance', $previous->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFrames(): iterable
    {
        yield 'invalid json' => ['wallet=secret'];
        yield 'unknown channel' => ['{"channel":"userFills","data":{"user":"secret"}}'];
        yield 'extra outer key' => ['{"channel":"pong","wallet":"secret"}'];
        yield 'empty trades' => ['{"channel":"trades","data":[]}'];
        yield 'bad trade side' => ['{"channel":"trades","data":[{"coin":"BTC","side":"X","px":"1","sz":"1","hash":"0x1","time":1,"tid":1,"users":["a","b"]}]}'];
        yield 'user-shaped trade' => ['{"channel":"trades","data":[{"coin":"BTC","side":"B","px":"1","sz":"1","hash":"0x1","time":1,"tid":1,"users":["a","b"],"wallet":"secret"}]}'];
        yield 'empty book side' => ['{"channel":"l2Book","data":{"coin":"BTC","levels":[[],[{"px":"2","sz":"1","n":1}]],"time":1}}'];
        yield 'crossed book' => ['{"channel":"l2Book","data":{"coin":"BTC","levels":[[{"px":"2","sz":"1","n":1}],[{"px":"1","sz":"1","n":1}]],"time":1}}'];
        yield 'bad candle geometry' => ['{"channel":"candle","data":{"t":0,"T":59999,"s":"BTC","i":"1m","o":"2","c":"2","h":"1","l":"0.5","v":"4","n":5}}'];
    }

    #[DataProvider('invalidFrames')]
    public function testRejectsMalformedOrPrivateFramesWithOneRedactedReason(
        string $frame,
    ): void {
        try {
            (new HyperliquidPaperPublicFrameDecoder(
                new HyperliquidPaperPublicSubscriptionSet(),
            ))->decode($frame);
            self::fail('Expected frame rejection.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame(
                'hyperliquid_paper_public_message_invalid',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public function testQueuePreservesOrderAndEnforcesBothBounds(): void
    {
        $queue = new HyperliquidPaperPublicFrameQueue(maxFrames: 8, maxBytes: 64);
        $queue->enqueue('first');
        $queue->enqueue('second');
        self::assertSame('first', $queue->peek());
        self::assertSame('first', $queue->dequeue());
        self::assertSame('second', $queue->dequeue());
        self::assertNull($queue->dequeue());

        $queue->enqueue(str_repeat('x', 64));
        try {
            $queue->enqueue('x');
            self::fail('Expected byte backpressure rejection.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_backpressure_exhausted', $exception->getMessage());
        }

        $queue->clear();
        for ($index = 0; $index < 8; ++$index) {
            $queue->enqueue('x');
        }
        $this->expectExceptionMessage('market_data_backpressure_exhausted');
        $queue->enqueue('overflow');
    }

    public function testQueueBoundsDefaultToThePolicy(): void
    {
        $queue = new HyperliquidPaperPublicFrameQueue();

        self::assertSame(
            HyperliquidPaperLivePolicy::MAX_QUEUED_FRAMES,
            (new \ReflectionProperty($queue, 'maxFrames'))->getValue($queue),
        );
        self::assertSame(
            HyperliquidPaperLivePolicy::MAX_QUEUED_BYTES,
            (new \ReflectionProperty($queue, 'maxBytes'))->getValue($queue),
        );
        self::assertGreaterThanOrEqual(
            4 * HyperliquidPaperLivePolicy::MAX_FRAME_BYTES,
            HyperliquidPaperLivePolicy::MAX_QUEUED_BYTES,
            'A queue holds several frames of the largest accepted size.',
        );
    }
}
