<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Live;

use App\Trading\Paper\Okx\Live\OkxPaperLiveIntegrityException;
use App\Trading\Paper\Okx\Live\OkxPaperLivePolicy;
use App\Trading\Paper\Okx\Live\OkxPaperPublicFrameDecoder;
use App\Trading\Paper\Okx\Live\OkxPaperPublicFrameQueue;
use App\Trading\Paper\Okx\Live\OkxPaperPublicSubscriptionSet;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OkxPaperPublicSubscriptionSet::class)]
#[CoversClass(OkxPaperPublicFrameDecoder::class)]
#[CoversClass(OkxPaperPublicFrameQueue::class)]
final class OkxPaperPublicProtocolTest extends TestCase
{
    private const PUBLIC_ARGUMENTS = [
        ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
        ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
        ['channel' => 'trades', 'instId' => 'ETH-USDT-SWAP'],
        ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'],
    ];

    private const BUSINESS_ARGUMENTS = [
        ['channel' => 'candle1m', 'instId' => 'BTC-USDT-SWAP'],
        ['channel' => 'candle5m', 'instId' => 'BTC-USDT-SWAP'],
        ['channel' => 'candle15m', 'instId' => 'BTC-USDT-SWAP'],
        ['channel' => 'candle1H', 'instId' => 'BTC-USDT-SWAP'],
        ['channel' => 'candle1m', 'instId' => 'ETH-USDT-SWAP'],
        ['channel' => 'candle5m', 'instId' => 'ETH-USDT-SWAP'],
        ['channel' => 'candle15m', 'instId' => 'ETH-USDT-SWAP'],
        ['channel' => 'candle1H', 'instId' => 'ETH-USDT-SWAP'],
    ];

    public function testSubscriptionArgumentsAreSplitIntoTheExactStableSocketSets(): void
    {
        $subscriptions = self::subscriptions();

        self::assertSame(self::PUBLIC_ARGUMENTS, $subscriptions->publicArguments());
        self::assertSame(self::BUSINESS_ARGUMENTS, $subscriptions->businessArguments());
        foreach (self::PUBLIC_ARGUMENTS as $argument) {
            self::assertTrue($subscriptions->isPublicRequired($argument['channel'], $argument['instId']));
            self::assertFalse($subscriptions->isBusinessRequired($argument['channel'], $argument['instId']));
        }
        foreach (self::BUSINESS_ARGUMENTS as $argument) {
            self::assertFalse($subscriptions->isPublicRequired($argument['channel'], $argument['instId']));
            self::assertTrue($subscriptions->isBusinessRequired($argument['channel'], $argument['instId']));
        }
        self::assertFalse($subscriptions->isPublicRequired('tickers', 'BTC-USDT-SWAP'));
        self::assertFalse($subscriptions->isBusinessRequired('candle1m', 'SOL-USDT-SWAP'));
    }

    public function testReadinessRequiresFourPublicAndEightBusinessAcknowledgements(): void
    {
        $subscriptions = self::subscriptions();

        self::assertFalse($subscriptions->isPublicReady());
        self::assertFalse($subscriptions->isBusinessReady());
        self::assertFalse($subscriptions->isReady());

        foreach (self::PUBLIC_ARGUMENTS as $offset => $argument) {
            $subscriptions->acknowledgePublic($argument);
            $subscriptions->acknowledgePublic($argument);
            self::assertSame($offset === 3, $subscriptions->isPublicReady());
            self::assertFalse($subscriptions->isReady());
        }

        foreach (self::BUSINESS_ARGUMENTS as $offset => $argument) {
            $subscriptions->acknowledgeBusiness($argument);
            $subscriptions->acknowledgeBusiness($argument);
            self::assertSame($offset === 7, $subscriptions->isBusinessReady());
            self::assertSame($offset === 7, $subscriptions->isReady());
        }

        $subscriptions->reset();

        self::assertFalse($subscriptions->isPublicReady());
        self::assertFalse($subscriptions->isBusinessReady());
        self::assertFalse($subscriptions->isReady());
        self::assertSame(self::PUBLIC_ARGUMENTS, $subscriptions->publicArguments());
        self::assertSame(self::BUSINESS_ARGUMENTS, $subscriptions->businessArguments());
    }

    /** @return iterable<string, array{'acknowledgePublic'|'acknowledgeBusiness', array<array-key, mixed>}> */
    public static function invalidAcknowledgements(): iterable
    {
        yield 'candle cannot acknowledge on public socket' => ['acknowledgePublic', [
            'channel' => 'candle1m',
            'instId' => 'BTC-USDT-SWAP',
        ]];
        yield 'trades cannot acknowledge on business socket' => ['acknowledgeBusiness', [
            'channel' => 'trades',
            'instId' => 'BTC-USDT-SWAP',
        ]];
        yield 'books cannot acknowledge on business socket' => ['acknowledgeBusiness', [
            'channel' => 'books',
            'instId' => 'ETH-USDT-SWAP',
        ]];
        yield 'unknown instrument' => ['acknowledgePublic', [
            'channel' => 'trades',
            'instId' => 'SOL-USDT-SWAP',
        ]];
        yield 'ticker channel' => ['acknowledgePublic', [
            'channel' => 'tickers',
            'instId' => 'BTC-USDT-SWAP',
        ]];
        yield 'books l2 tbt channel' => ['acknowledgePublic', [
            'channel' => 'books-l2-tbt',
            'instId' => 'BTC-USDT-SWAP',
        ]];
        yield 'all trades channel' => ['acknowledgePublic', [
            'channel' => 'trades-all',
            'instId' => 'BTC-USDT-SWAP',
        ]];
        yield 'private account channel' => ['acknowledgePublic', [
            'channel' => 'account',
            'instId' => 'BTC-USDT-SWAP',
        ]];
        yield 'missing channel' => ['acknowledgePublic', [
            'instId' => 'BTC-USDT-SWAP',
        ]];
        yield 'missing instrument' => ['acknowledgeBusiness', [
            'channel' => 'candle1m',
        ]];
        yield 'additional field' => ['acknowledgePublic', [
            'channel' => 'trades',
            'instId' => 'BTC-USDT-SWAP',
            'uid' => 'must-not-be-accepted',
        ]];
        yield 'error acknowledgement' => ['acknowledgeBusiness', [
            'channel' => 'candle1m',
            'instId' => 'BTC-USDT-SWAP',
            'error' => 'must-not-be-accepted',
        ]];
        yield 'non-string channel' => ['acknowledgePublic', [
            'channel' => 1,
            'instId' => 'BTC-USDT-SWAP',
        ]];
    }

    /** @param 'acknowledgePublic'|'acknowledgeBusiness' $method */
    /** @param array<array-key, mixed> $argument */
    #[DataProvider('invalidAcknowledgements')]
    public function testSubscriptionAcknowledgementsFailClosed(string $method, array $argument): void
    {
        $subscriptions = self::subscriptions();

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('okx_paper_public_subscription_invalid');

        $subscriptions->{$method}($argument);
    }

    public function testDecoderAcceptsRealisticSubscribeControlsAndDropsRoutingMetadata(): void
    {
        $decoder = self::decoder();

        self::assertSame(['event' => 'pong'], $decoder->decodePublic('pong'));
        self::assertSame(['event' => 'pong'], $decoder->decodeBusiness('pong'));
        self::assertSame(
            [
                'event' => 'subscribe',
                'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
            ],
            $decoder->decodePublic(
                '{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"accb8e21"}',
            ),
        );
        self::assertSame(
            [
                'event' => 'subscribe',
                'arg' => ['channel' => 'candle1m', 'instId' => 'ETH-USDT-SWAP'],
            ],
            $decoder->decodeBusiness(
                '{"id":"Request42","event":"subscribe","arg":{"channel":"candle1m","instId":"ETH-USDT-SWAP"},"connId":"a4d3ae55"}',
            ),
        );
    }

    public function testDecoderAcceptsExactTradesCandlesAndBookDataShapesOnTheirSocket(): void
    {
        $decoder = self::decoder();
        $messages = [
            ['decodePublic', [
                'arg' => ['channel' => 'trades', 'instId' => 'BTC-USDT-SWAP'],
                'data' => [['tradeId' => '1']],
            ]],
            ['decodeBusiness', [
                'arg' => ['channel' => 'candle1H', 'instId' => 'ETH-USDT-SWAP'],
                'data' => [['1', '2', '3']],
            ]],
            ['decodePublic', [
                'arg' => ['channel' => 'books', 'instId' => 'BTC-USDT-SWAP'],
                'action' => 'snapshot',
                'data' => [['seqId' => 1]],
            ]],
            ['decodePublic', [
                'arg' => ['channel' => 'books', 'instId' => 'ETH-USDT-SWAP'],
                'action' => 'update',
                'data' => [],
            ]],
        ];

        foreach ($messages as [$method, $message]) {
            $frame = json_encode($message, JSON_THROW_ON_ERROR);
            self::assertSame($message, $decoder->{$method}($frame));
        }
    }

    /** @return iterable<string, array{'decodePublic'|'decodeBusiness', string}> */
    public static function wrongSocketFrames(): iterable
    {
        yield 'public subscribe cannot acknowledge a candle' => [
            'decodePublic',
            '{"event":"subscribe","arg":{"channel":"candle1m","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}',
        ];
        yield 'business subscribe cannot acknowledge trades' => [
            'decodeBusiness',
            '{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}',
        ];
        yield 'public socket cannot carry candle data' => [
            'decodePublic',
            '{"arg":{"channel":"candle1m","instId":"BTC-USDT-SWAP"},"data":[]}',
        ];
        yield 'business socket cannot carry books data' => [
            'decodeBusiness',
            '{"arg":{"channel":"books","instId":"BTC-USDT-SWAP"},"action":"snapshot","data":[]}',
        ];
    }

    /** @param 'decodePublic'|'decodeBusiness' $method */
    #[DataProvider('wrongSocketFrames')]
    public function testDecoderRejectsMessagesRoutedThroughTheWrongSocket(string $method, string $frame): void
    {
        $decoder = self::decoder();

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('okx_paper_public_message_invalid');

        $decoder->{$method}($frame);
    }

    public function testRealisticErrorControlsAreReducedWithoutRawIdConnIdArgOrOkxMessage(): void
    {
        $decoder = self::decoder();
        $frames = [
            ['decodePublic', '{"event":"error","code":"60012","msg":"SENSITIVE_PUBLIC_MESSAGE","connId":"a4d3ae55"}'],
            ['decodeBusiness', '{"id":"1512","event":"error","code":"60012","msg":"SENSITIVE_BUSINESS_MESSAGE","connId":"accb8e21","arg":{"channel":"candle1m","instId":"BTC-USDT-SWAP"}}'],
        ];

        foreach ($frames as [$method, $raw]) {
            try {
                $decoder->{$method}($raw);
                self::fail('Expected protocol error.');
            } catch (OkxPaperLiveIntegrityException $exception) {
                self::assertSame('okx_paper_public_protocol_error', $exception->getMessage());
                self::assertStringNotContainsString('SENSITIVE_', (string) $exception);
                self::assertStringNotContainsString('1512', (string) $exception);
                self::assertStringNotContainsString('a4d3ae55', (string) $exception);
                self::assertStringNotContainsString('accb8e21', (string) $exception);
                self::assertStringNotContainsString($raw, (string) $exception);
            }
        }
    }

    public function testDecodedFrameValuesAreRedactedFromProductionTracesWhenArgumentsAreRetained(): void
    {
        $decoder = self::decoder();
        $cases = [
            [
                'frame' => '{"id":"TraceIdSentinelB2","event":"error","code":"60012","msg":"TraceMsgSentinelA1","connId":"TraceConnSentinelC3","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"}}',
                'message' => 'okx_paper_public_protocol_error',
                'sentinels' => ['TraceMsgSentinelA1', 'TraceIdSentinelB2', 'TraceConnSentinelC3'],
            ],
            [
                'frame' => '{"id":"Trace-InvalidMetadata-Sentinel","event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}',
                'message' => 'okx_paper_public_message_invalid',
                'sentinels' => ['Trace-InvalidMetadata-Sentinel'],
            ],
            [
                'frame' => '{"event":"subscribe","arg":{"channel":"TraceArgSentinelD4","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}',
                'message' => 'okx_paper_public_message_invalid',
                'sentinels' => ['TraceArgSentinelD4'],
            ],
            [
                'frame' => '{"arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"data":[{"tradeId":"TraceRowsDataSentinelE5"}],"uid":"unexpected"}',
                'message' => 'okx_paper_public_message_invalid',
                'sentinels' => ['TraceRowsDataSentinelE5'],
            ],
            [
                'frame' => '{"TraceMalformedSentinelF6":',
                'message' => 'okx_paper_public_message_invalid',
                'sentinels' => ['TraceMalformedSentinelF6'],
            ],
        ];
        $previousSetting = ini_get('zend.exception_ignore_args');
        self::assertIsString($previousSetting);

        try {
            self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'));
            self::assertSame('0', ini_get('zend.exception_ignore_args'));

            foreach ($cases as $case) {
                try {
                    $decoder->decodePublic($case['frame']);
                    self::fail('Expected the production decoder to reject the frame.');
                } catch (OkxPaperLiveIntegrityException $exception) {
                    self::assertSame($case['message'], $exception->getMessage());
                    $trace = print_r($exception->getTrace(), true);
                    $traceAsString = $exception->getTraceAsString();
                    self::assertStringContainsString(OkxPaperPublicFrameDecoder::class, $trace);

                    foreach ($case['sentinels'] as $sentinel) {
                        self::assertStringNotContainsString($sentinel, $trace);
                        self::assertStringNotContainsString($sentinel, $traceAsString);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousSetting);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFrames(): iterable
    {
        yield 'blank' => [''];
        yield 'whitespace only' => [" \n\t"];
        yield 'malformed json' => ['{"arg":'];
        yield 'list root' => ['[]'];
        yield 'json scalar' => ['true'];
        yield 'oversized frame' => [str_repeat('x', OkxPaperLivePolicy::MAX_FRAME_BYTES + 1)];
        yield 'pong with whitespace is not literal' => [' pong'];
        yield 'login control' => ['{"event":"login","code":"0","connId":"a4d3ae55"}'];
        yield 'unknown control' => ['{"event":"notice","connId":"a4d3ae55"}'];
        yield 'error control missing msg' => ['{"event":"error","code":"60012","connId":"a4d3ae55"}'];
        yield 'error control missing connId' => ['{"event":"error","code":"60012","msg":"error"}'];
        yield 'error control extra metadata' => ['{"event":"error","code":"60012","msg":"error","connId":"a4d3ae55","raw":"extra"}'];
        yield 'error control extra arg field' => ['{"event":"error","code":"60012","msg":"error","connId":"a4d3ae55","arg":{"channel":"trades","instId":"BTC-USDT-SWAP","uid":"extra"}}'];
        yield 'subscribe missing arg' => ['{"event":"subscribe","connId":"a4d3ae55"}'];
        yield 'subscribe missing connId' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"}}'];
        yield 'subscribe extra root metadata' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55","raw":"extra"}'];
        yield 'subscribe extra arg field' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP","uid":"extra"},"connId":"a4d3ae55"}'];
        yield 'subscribe unknown channel' => ['{"event":"subscribe","arg":{"channel":"tickers","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}'];
        yield 'subscribe unknown instrument' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"SOL-USDT-SWAP"},"connId":"a4d3ae55"}'];
        yield 'connId empty' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":""}'];
        yield 'connId non-string' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":42}'];
        yield 'connId whitespace' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3 ae55"}'];
        yield 'connId non-canonical punctuation' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3-ae55"}'];
        yield 'connId above 64 characters' => ['{"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"'.str_repeat('a', 65).'"}'];
        yield 'id empty' => ['{"id":"","event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}'];
        yield 'id non-string' => ['{"id":1512,"event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}'];
        yield 'id contains punctuation' => ['{"id":"request-42","event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}'];
        yield 'id above 32 characters' => ['{"id":"'.str_repeat('a', 33).'","event":"subscribe","arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"connId":"a4d3ae55"}'];
        yield 'data missing data' => ['{"arg":{"channel":"trades","instId":"BTC-USDT-SWAP"}}'];
        yield 'data is not a list' => ['{"arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"data":{}}'];
        yield 'data extra root field' => ['{"arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"data":[],"uid":"extra"}'];
        yield 'data extra arg field' => ['{"arg":{"channel":"trades","instId":"BTC-USDT-SWAP","uid":"extra"},"data":[]}'];
        yield 'data unknown channel' => ['{"arg":{"channel":"tickers","instId":"BTC-USDT-SWAP"},"data":[]}'];
        yield 'data private channel' => ['{"arg":{"channel":"account","instId":"BTC-USDT-SWAP"},"data":[]}'];
        yield 'data unknown instrument' => ['{"arg":{"channel":"trades","instId":"SOL-USDT-SWAP"},"data":[]}'];
        yield 'books action missing' => ['{"arg":{"channel":"books","instId":"BTC-USDT-SWAP"},"data":[]}'];
        yield 'books action invalid' => ['{"arg":{"channel":"books","instId":"BTC-USDT-SWAP"},"action":"partial","data":[]}'];
        yield 'trades action forbidden' => ['{"arg":{"channel":"trades","instId":"BTC-USDT-SWAP"},"action":"update","data":[]}'];
    }

    #[DataProvider('invalidFrames')]
    public function testDecoderRejectsInvalidFramesWithoutLeakingRawBytes(
        #[\SensitiveParameter] string $frame,
    ): void {
        $decoder = self::decoder();

        try {
            $decoder->decodePublic($frame);
            self::fail('Expected invalid message.');
        } catch (OkxPaperLiveIntegrityException $exception) {
            self::assertSame('okx_paper_public_message_invalid', $exception->getMessage());
            if ($frame !== '') {
                self::assertStringNotContainsString($frame, (string) $exception);
            }
        }
    }

    public function testRawFrameParametersAreSensitive(): void
    {
        $decodePublic = new \ReflectionMethod(OkxPaperPublicFrameDecoder::class, 'decodePublic');
        $decodeBusiness = new \ReflectionMethod(OkxPaperPublicFrameDecoder::class, 'decodeBusiness');
        $enqueue = new \ReflectionMethod(OkxPaperPublicFrameQueue::class, 'enqueue');

        self::assertNotEmpty($decodePublic->getParameters()[0]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($decodeBusiness->getParameters()[0]->getAttributes(\SensitiveParameter::class));
        self::assertNotEmpty($enqueue->getParameters()[0]->getAttributes(\SensitiveParameter::class));
    }

    public function testQueueIsFifoWithExactCountAndByteAccountingAndClear(): void
    {
        $queue = new OkxPaperPublicFrameQueue();

        self::assertSame(0, $queue->count());
        self::assertSame(0, $queue->bytes());
        self::assertNull($queue->dequeue());

        $queue->enqueue('a');
        $queue->enqueue('é');
        $queue->enqueue('three');

        self::assertSame(3, $queue->count());
        self::assertSame(strlen('aéthree'), $queue->bytes());
        self::assertSame('a', $queue->dequeue());
        self::assertSame(2, $queue->count());
        self::assertSame(strlen('éthree'), $queue->bytes());
        self::assertSame('é', $queue->dequeue());

        $queue->clear();

        self::assertSame(0, $queue->count());
        self::assertSame(0, $queue->bytes());
        self::assertNull($queue->dequeue());
    }

    public function testQueueAllowsExactlyTheCountLimitAndRejectsFrameTwoHundredFiftySeven(): void
    {
        $queue = new OkxPaperPublicFrameQueue();
        for ($index = 0; $index < OkxPaperLivePolicy::MAX_QUEUED_FRAMES; ++$index) {
            $queue->enqueue('x');
        }

        self::assertSame(OkxPaperLivePolicy::MAX_QUEUED_FRAMES, $queue->count());
        self::assertSame(OkxPaperLivePolicy::MAX_QUEUED_FRAMES, $queue->bytes());

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('market_data_backpressure_exhausted');

        $queue->enqueue('x');
    }

    public function testQueueAllowsExactlyTheByteLimitAndRejectsOneAdditionalByte(): void
    {
        $queue = new OkxPaperPublicFrameQueue();
        $queue->enqueue(str_repeat('x', OkxPaperLivePolicy::MAX_QUEUED_BYTES));

        self::assertSame(1, $queue->count());
        self::assertSame(OkxPaperLivePolicy::MAX_QUEUED_BYTES, $queue->bytes());

        $this->expectException(OkxPaperLiveIntegrityException::class);
        $this->expectExceptionMessage('market_data_backpressure_exhausted');

        $queue->enqueue('x');
    }

    public function testRejectedEnqueueDoesNotChangeQueueAccounting(): void
    {
        $queue = new OkxPaperPublicFrameQueue();
        $queue->enqueue(str_repeat('x', OkxPaperLivePolicy::MAX_QUEUED_BYTES));

        try {
            $queue->enqueue('rejected');
            self::fail('Expected queue exhaustion.');
        } catch (OkxPaperLiveIntegrityException) {
            self::assertSame(1, $queue->count());
            self::assertSame(OkxPaperLivePolicy::MAX_QUEUED_BYTES, $queue->bytes());
        }
    }

    private static function subscriptions(): OkxPaperPublicSubscriptionSet
    {
        return new OkxPaperPublicSubscriptionSet(new OkxPaperInstrumentMap());
    }

    private static function decoder(): OkxPaperPublicFrameDecoder
    {
        return new OkxPaperPublicFrameDecoder(self::subscriptions());
    }
}
