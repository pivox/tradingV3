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
        $queue = new HyperliquidPaperPublicFrameQueue();
        $queue->enqueue('first');
        $queue->enqueue('second');
        self::assertSame('first', $queue->peek());
        self::assertSame('first', $queue->dequeue());
        self::assertSame('second', $queue->dequeue());
        self::assertNull($queue->dequeue());

        $large = str_repeat('x', HyperliquidPaperLivePolicy::MAX_QUEUED_BYTES);
        $queue->enqueue($large);
        try {
            $queue->enqueue('x');
            self::fail('Expected byte backpressure rejection.');
        } catch (HyperliquidPaperLiveIntegrityException $exception) {
            self::assertSame('market_data_backpressure_exhausted', $exception->getMessage());
        }

        $queue->clear();
        for ($index = 0; $index < HyperliquidPaperLivePolicy::MAX_QUEUED_FRAMES; ++$index) {
            $queue->enqueue('x');
        }
        $this->expectExceptionMessage('market_data_backpressure_exhausted');
        $queue->enqueue('overflow');
    }
}
