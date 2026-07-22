<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Normalization;

use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxPaperMarketEventNormalizer::class)]
#[CoversClass(OkxPaperSourceOrdinal::class)]
#[CoversClass(OkxMaterializedBookState::class)]
#[CoversClass(OkxPaperInstrumentMap::class)]
final class OkxPaperMarketEventNormalizerTest extends TestCase
{
    public function testHistoryCandlePreservesEveryDecimalStringAndExcludesTheUnconfirmedRow(): void
    {
        $fixture = $this->fixture('history-candles.json');
        $normalizer = $this->normalizer();

        $event = $normalizer->historyCandle('BTC-USDT-SWAP', '1m', $fixture['data'][0]);

        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame(PaperMarketDataVenue::OKX, $event->sourceVenue);
        self::assertSame('BTCUSDT', $event->symbol);
        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $event->channel);
        self::assertSame('2026-07-21T01:00:00.000000Z', $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertEquals($event->exchangeTimestamp, $event->receivedTimestamp);
        self::assertSame('1', $event->sequence);
        self::assertSame([
            'native_symbol' => 'BTC-USDT-SWAP',
            'bar' => '1m',
            'open' => '65000.1000',
            'high' => '65100.000',
            'low' => '64990.20',
            'close' => '65070.500',
            'volume_contracts' => '1234',
            'volume_base' => '12.3400',
            'volume_quote' => '802345.6700',
            'confirmed' => true,
            'origin' => 'rest_history',
        ], $event->payload);
        self::assertNull($normalizer->historyCandle('BTC-USDT-SWAP', '1m', $fixture['data'][1]));
    }

    public function testCurrentCandleFixtureUsesReceiptClockAndExactOneHourMapping(): void
    {
        $fixture = $this->fixture('current-candles.json');
        $event = $this->normalizer()->warmupCandle('ETH-USDT-SWAP', '1H', $fixture['data'][0]);

        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame(PaperMarketDataChannel::CANDLE_1H, $event->channel);
        self::assertSame('1h', $event->payload['bar']);
        self::assertSame('rest_warmup', $event->payload['origin']);
        self::assertSame('2026-07-21T02:00:00.000000Z', $event->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertNull($this->normalizer()->warmupCandle('ETH-USDT-SWAP', '1H', $fixture['data'][1]));
    }

    /**
     * @return iterable<string, array{string, string, PaperMarketDataChannel, string}>
     */
    public static function exactWebSocketCandleProvider(): iterable
    {
        yield 'one minute' => [
            'ws-candle-1m.json',
            'candle1m',
            PaperMarketDataChannel::CANDLE_1M,
            '1m',
        ];
        yield 'five minutes' => [
            'ws-candle-5m.json',
            'candle5m',
            PaperMarketDataChannel::CANDLE_5M,
            '5m',
        ];
        yield 'fifteen minutes' => [
            'ws-candle-15m.json',
            'candle15m',
            PaperMarketDataChannel::CANDLE_15M,
            '15m',
        ];
        yield 'one hour' => [
            'ws-candle-1H.json',
            'candle1H',
            PaperMarketDataChannel::CANDLE_1H,
            '1h',
        ];
    }

    #[DataProvider('exactWebSocketCandleProvider')]
    public function testWebSocketCandleFixturesMapExactBusinessChannelsAndPreservePayload(
        string $fixtureName,
        string $expectedNativeChannel,
        PaperMarketDataChannel $expectedChannel,
        string $expectedBar,
    ): void {
        $fixture = $this->fixture($fixtureName);
        self::assertSame(
            ['channel' => $expectedNativeChannel, 'instId' => $fixture['arg']['instId']],
            $fixture['arg'],
        );
        self::assertIsArray($fixture['data']);
        self::assertNotEmpty($fixture['data']);
        foreach ($fixture['data'] as $row) {
            self::assertIsArray($row);
            self::assertTrue(array_is_list($row));
            self::assertCount(9, $row);
            foreach ($row as $value) {
                self::assertIsString($value);
            }
        }

        $row = $fixture['data'][0];
        $event = $this->normalizer()->webSocketCandle(
            $fixture['arg']['instId'],
            $fixture['arg']['channel'],
            $row,
        );

        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame($expectedChannel, $event->channel);
        self::assertSame('2026-07-21T02:00:00.000000Z', $event->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame([
            'native_symbol' => $fixture['arg']['instId'],
            'bar' => $expectedBar,
            'open' => $row[1],
            'high' => $row[2],
            'low' => $row[3],
            'close' => $row[4],
            'volume_contracts' => $row[5],
            'volume_base' => $row[6],
            'volume_quote' => $row[7],
            'confirmed' => true,
            'origin' => 'ws_candle',
        ], $event->payload);
    }

    public function testUnconfirmedWebSocketCandleDoesNotConsumeAnOrdinal(): void
    {
        $fixture = $this->fixture('ws-candle-1m.json');
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);

        self::assertNull($normalizer->webSocketCandle(
            $fixture['arg']['instId'],
            $fixture['arg']['channel'],
            $fixture['data'][1],
        ));
        self::assertSame(['schema_version' => 1, 'scopes' => []], $ordinals->snapshot());

        $event = $normalizer->webSocketCandle(
            $fixture['arg']['instId'],
            $fixture['arg']['channel'],
            $fixture['data'][0],
        );
        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame('1', $event->sequence);
        self::assertStringContainsString(
            '"natural_identity":"candle|1m|1784595720000"',
            json_encode($ordinals->snapshot(), JSON_THROW_ON_ERROR),
        );
    }

    public function testLiveNormalizerPublicSignaturesRemainExactAndConstructorNamesStayStable(): void
    {
        $constructor = new \ReflectionMethod(OkxPaperMarketEventNormalizer::class, '__construct');
        self::assertSame(
            ['clock', 'instruments', 'ordinals'],
            array_map(
                static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                $constructor->getParameters(),
            ),
        );

        $expectedMethods = [
            'webSocketCandle' => ['instrumentId', 'bar', 'row'],
            'connectionState' => ['instrumentId', 'state', 'connectionEpoch'],
            'snapshotBoundary' => ['instrumentId', 'reason', 'sourceEpoch', 'sourceSequence'],
            'materializedTopOfBook' => ['instrumentId', 'materializedBookState', 'sourceEpoch', 'origin'],
        ];
        foreach ($expectedMethods as $methodName => $expectedParameterNames) {
            $method = new \ReflectionMethod(OkxPaperMarketEventNormalizer::class, $methodName);
            self::assertTrue($method->isPublic());
            self::assertSame(
                $expectedParameterNames,
                array_map(
                    static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
                    $method->getParameters(),
                ),
            );
        }

        $origin = (new \ReflectionMethod(
            OkxPaperMarketEventNormalizer::class,
            'materializedTopOfBook',
        ))->getParameters()[3];
        self::assertTrue($origin->isDefaultValueAvailable());
        self::assertSame('ws_books', $origin->getDefaultValue());
    }

    /** @return iterable<string, array{string, PaperMarketDataChannel, string}> */
    public static function exactTimeframeProvider(): iterable
    {
        yield 'one minute' => ['1m', PaperMarketDataChannel::CANDLE_1M, '1m'];
        yield 'five minutes' => ['5m', PaperMarketDataChannel::CANDLE_5M, '5m'];
        yield 'fifteen minutes' => ['15m', PaperMarketDataChannel::CANDLE_15M, '15m'];
        yield 'one hour' => ['1H', PaperMarketDataChannel::CANDLE_1H, '1h'];
    }

    #[DataProvider('exactTimeframeProvider')]
    public function testMapsOnlyTheFourExactOkxBars(
        string $bar,
        PaperMarketDataChannel $channel,
        string $normalizedBar,
    ): void {
        $row = $this->fixture('history-candles.json')['data'][0];
        $event = $this->normalizer()->historyCandle('BTC-USDT-SWAP', $bar, $row);

        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame($channel, $event->channel);
        self::assertSame($normalizedBar, $event->payload['bar']);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedTimeframeProvider(): iterable
    {
        yield 'lowercase hour' => ['1h'];
        yield 'sixty minutes' => ['60m'];
        yield 'thirty minutes' => ['30m'];
        yield 'utc daily' => ['1Dutc'];
        yield 'uppercase minute' => ['1M'];
        yield 'blank' => [''];
    }

    #[DataProvider('rejectedTimeframeProvider')]
    public function testRejectsEveryBarOutsideTheExactContract(string $bar): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_timeframe_not_allowed');

        $this->normalizer()->historyCandle(
            'BTC-USDT-SWAP',
            $bar,
            $this->fixture('history-candles.json')['data'][0],
        );
    }

    public function testHistoryTradeFixturePreservesRestFieldsAndUnknownAggregationAsNull(): void
    {
        $row = $this->fixture('history-trades.json')['data'][0];
        $event = $this->normalizer()->historyTrade($row);

        self::assertSame('BTCUSDT', $event->symbol);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $event->channel);
        self::assertSame('2026-07-21T01:01:40.123000Z', $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertEquals($event->exchangeTimestamp, $event->receivedTimestamp);
        self::assertSame('1', $event->sequence);
        self::assertSame([
            'native_symbol' => 'BTC-USDT-SWAP',
            'trade_id' => '242720720',
            'price' => '65070.5000',
            'size_contracts' => '4.000',
            'taker_side' => 'buy',
            'aggregate_count' => null,
            'source' => '0',
            'source_seq_id' => null,
            'origin' => 'rest_history',
        ], $event->payload);
    }

    public function testRecentTradeFixtureRetainsRestAggregationAndSourceSequence(): void
    {
        $row = $this->fixture('recent-trades.json')['data'][0];
        $event = $this->normalizer()->recoveryTrade($row);

        self::assertSame('ETHUSDT', $event->symbol);
        self::assertSame('2026-07-21T02:00:00.000000Z', $event->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2', $event->payload['aggregate_count']);
        self::assertSame('88001', $event->payload['source_seq_id']);
        self::assertSame('rest_recovery', $event->payload['origin']);
    }

    public function testWebSocketTradeFixtureRetainsAggregatesAndAllocatesDistinctOrdinalsForOneSeqId(): void
    {
        $fixture = $this->fixture('ws-trades.json');
        $normalizer = $this->normalizer();

        $first = $normalizer->webSocketTrade($fixture['data'][0]);
        $second = $normalizer->webSocketTrade($fixture['data'][1]);

        self::assertSame('777', $first->payload['source_seq_id']);
        self::assertSame('777', $second->payload['source_seq_id']);
        self::assertSame('3', $first->payload['aggregate_count']);
        self::assertSame('1', $second->payload['aggregate_count']);
        self::assertSame('ws_aggregated', $first->payload['origin']);
        self::assertSame('1', $first->sequence);
        self::assertSame('2', $second->sequence);
        self::assertNotSame($first->eventId, $second->eventId);
    }

    public function testDuplicateNaturalIdentityIsExactReplayAndConflictFailsWithoutConsumingAnOrdinal(): void
    {
        $rows = $this->fixture('history-trades.json')['data'];
        $normalizer = $this->normalizer();

        $first = $normalizer->historyTrade($rows[0]);
        self::assertSame($first, $normalizer->historyTrade($rows[0]));

        $conflicting = $rows[0];
        $conflicting['px'] = '65070.5001';
        try {
            $normalizer->historyTrade($conflicting);
            self::fail('A duplicate natural identity with another canonical payload must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('okx_paper_natural_identity_conflict', $exception->getMessage());
        }

        self::assertSame('2', $normalizer->historyTrade($rows[1])->sequence);
    }

    public function testTradeTimestampParticipatesInNaturalIdentityConflictDetection(): void
    {
        $rows = $this->fixture('history-trades.json')['data'];
        $normalizer = $this->normalizer();
        $normalizer->historyTrade($rows[0]);

        $conflicting = $rows[0];
        $conflicting['ts'] = '1784595700124';

        try {
            $normalizer->historyTrade($conflicting);
            self::fail('A duplicate trade identity with another exchange timestamp must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('okx_paper_natural_identity_conflict', $exception->getMessage());
        }

        self::assertSame('2', $normalizer->historyTrade($rows[1])->sequence);
    }

    public function testLiveReceiptTimeDoesNotTurnAnIdenticalSourceRetryIntoAConflict(): void
    {
        $clock = new MockClock('2026-07-21T02:00:00.000000Z');
        $normalizer = $this->normalizer($clock);
        $row = $this->fixture('recent-trades.json')['data'][0];

        $first = $normalizer->recoveryTrade($row);
        $clock->sleep(1);
        $retry = $normalizer->recoveryTrade($row);

        self::assertSame($first, $retry);
        self::assertSame('2026-07-21T02:00:00.000000Z', $retry->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testInjectedOrdinalSnapshotRestoresSequenceAndLatestReplayExactly(): void
    {
        self::assertTrue(method_exists(OkxPaperSourceOrdinal::class, 'snapshot'));
        self::assertTrue(method_exists(OkxPaperSourceOrdinal::class, 'restore'));
        $constructor = new \ReflectionMethod(OkxPaperMarketEventNormalizer::class, '__construct');
        self::assertContains('ordinals', array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        ));

        $clock = new MockClock('2026-07-21T02:00:00.000000Z');
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer($clock, $ordinals);
        $row = $this->fixture('recent-trades.json')['data'][0];
        $first = $normalizer->recoveryTrade($row);

        $restored = OkxPaperSourceOrdinal::restore($ordinals->snapshot());
        $clock->sleep(1);
        $resumed = $this->normalizer($clock, $restored);

        self::assertSame($first->toArray(), $resumed->recoveryTrade($row)->toArray());

        $next = $row;
        $next['tradeId'] = '242720802';
        $next['ts'] = '1784595705124';
        self::assertSame('2', $resumed->recoveryTrade($next)->sequence);
    }

    public function testOrdinalRestoreRejectsSyntacticallyValidNaturalIdentityTampering(): void
    {
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $normalizer->historyTrade($this->fixture('history-trades.json')['data'][0]);
        $state = $ordinals->snapshot();
        $state['scopes']['okx/BTCUSDT/public_trade']['latest']['natural_identity'] = 'trade|242720721';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_source_ordinal_state_invalid');

        OkxPaperSourceOrdinal::restore($state);
    }

    public function testGapReservationIsCheckpointableIdempotentAndConsumedByExactlyOneAcceptedEvent(): void
    {
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $rows = $this->fixture('history-trades.json')['data'];

        self::assertSame('1', $normalizer->historyTrade($rows[0])->sequence);
        $ordinals->reserveGap('okx/BTCUSDT/public_trade');
        $ordinals->reserveGap('okx/BTCUSDT/public_trade');
        $state = $ordinals->snapshot();
        self::assertSame('1', $state['scopes']['okx/BTCUSDT/public_trade']['last_sequence']);
        self::assertTrue($state['scopes']['okx/BTCUSDT/public_trade']['gap_pending']);

        $restored = OkxPaperSourceOrdinal::restore($state);
        $restored->reserveGap('okx/BTCUSDT/public_trade');
        $resumed = $this->normalizer(ordinals: $restored);
        self::assertSame('3', $resumed->historyTrade($rows[1])->sequence);
        self::assertFalse(
            $restored->snapshot()['scopes']['okx/BTCUSDT/public_trade']['gap_pending'],
        );

        $next = $rows[1];
        $next['tradeId'] = '242720722';
        $next['ts'] = '1784595701124';
        self::assertSame('4', $resumed->historyTrade($next)->sequence);
    }

    public function testGapReservationOnAnEmptyScopeFailsClosed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('okx_paper_source_gap_reservation_invalid');

        (new OkxPaperSourceOrdinal())->reserveGap('okx/BTCUSDT/public_trade');
    }

    public function testOrdinalSnapshotIsBoundedToTheLatestAcceptedEventPerFiniteScope(): void
    {
        self::assertTrue(method_exists(OkxPaperSourceOrdinal::class, 'snapshot'));
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $row = $this->fixture('history-trades.json')['data'][0];

        for ($index = 0; $index < 100; ++$index) {
            $row['tradeId'] = (string) (242_720_720 + $index);
            $row['ts'] = (string) (1_784_595_700_123 + $index);
            $normalizer->historyTrade($row);
        }

        $state = $ordinals->snapshot();
        self::assertSame(1, $state['schema_version']);
        self::assertCount(1, $state['scopes']);
        self::assertSame('100', $state['scopes']['okx/BTCUSDT/public_trade']['last_sequence']);
        self::assertSame(
            'trade|242720819',
            $state['scopes']['okx/BTCUSDT/public_trade']['latest']['natural_identity'],
        );
        self::assertStringNotContainsString('trade|242720720', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function testOrdinalRestoreRejectsStateOutsideTheFiniteOkxScopes(): void
    {
        self::assertTrue(method_exists(OkxPaperSourceOrdinal::class, 'restore'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_source_ordinal_state_invalid');

        OkxPaperSourceOrdinal::restore([
            'schema_version' => 1,
            'scopes' => [
                'okx/DOGEUSDT/public_trade' => [
                    'last_sequence' => '1',
                    'gap_pending' => false,
                    'latest' => null,
                ],
            ],
        ]);
    }

    public function testOrdinalRestoreRejectsPendingGapWithoutAnAcceptedEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_source_ordinal_state_invalid');

        OkxPaperSourceOrdinal::restore([
            'schema_version' => 1,
            'scopes' => [
                'okx/BTCUSDT/public_trade' => [
                    'last_sequence' => '1',
                    'gap_pending' => true,
                    'latest' => null,
                ],
            ],
        ]);
    }

    public function testOrdinalRestoreRejectsPreConsumedPendingGapSequence(): void
    {
        $ordinals = new OkxPaperSourceOrdinal();
        $this->normalizer(ordinals: $ordinals)
            ->historyTrade($this->fixture('history-trades.json')['data'][0]);
        $state = $ordinals->snapshot();
        $state['scopes']['okx/BTCUSDT/public_trade']['last_sequence'] = '2';
        $state['scopes']['okx/BTCUSDT/public_trade']['gap_pending'] = true;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_source_ordinal_state_invalid');

        OkxPaperSourceOrdinal::restore($state);
    }

    public function testOrdinalRestoreRejectsANonPositiveAcceptedEventSequence(): void
    {
        $timestamp = new \DateTimeImmutable('2026-07-21T01:01:40.123000Z');
        $event = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::PUBLIC_TRADE,
            exchangeTimestamp: $timestamp,
            receivedTimestamp: $timestamp,
            sequence: '0',
            payload: ['trade_id' => '242720720'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_source_ordinal_state_invalid');

        OkxPaperSourceOrdinal::restore([
            'schema_version' => 1,
            'scopes' => [
                'okx/BTCUSDT/public_trade' => [
                    'last_sequence' => '1',
                    'gap_pending' => false,
                    'latest' => [
                        'natural_identity' => 'trade|242720720',
                        'assignment_digest' => OkxPaperSourceOrdinal::assignmentDigest(
                            'trade|242720720',
                            $event->exchangeTimestamp,
                            $event->payload,
                        ),
                        'event' => $event->toArray(),
                    ],
                ],
            ],
        ]);
    }

    public function testEventConstructionFailureDoesNotCommitAnOrdinalOrAssignment(): void
    {
        self::assertTrue(method_exists(OkxPaperSourceOrdinal::class, 'snapshot'));
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $row = $this->fixture('history-trades.json')['data'][0];
        $row['px'] = str_repeat('1', 1_048_200);

        try {
            $normalizer->historyTrade($row);
            self::fail('An event exceeding the immutable envelope budget must fail construction.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_decode_bytes_exceeded', $exception->getMessage());
        }

        self::assertSame(['schema_version' => 1, 'scopes' => []], $ordinals->snapshot());
        self::assertSame(
            '1',
            $normalizer->historyTrade($this->fixture('history-trades.json')['data'][1])->sequence,
        );
    }

    public function testRestOrderBookFixtureEmitsOnlyTheBestBidAndAskWithStringsIntact(): void
    {
        $row = $this->fixture('order-book.json')['data'][0];
        $event = $this->normalizer()->materializedTopOfBook(
            'BTC-USDT-SWAP',
            OkxMaterializedBookState::fromSnapshot($row),
            1,
        );

        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $event->channel);
        self::assertSame([
            'native_symbol' => 'BTC-USDT-SWAP',
            'bid_price' => '65070.4000',
            'bid_size_contracts' => '9.000',
            'bid_order_count' => '3',
            'ask_price' => '65070.5000',
            'ask_size_contracts' => '4.000',
            'ask_order_count' => '2',
            'source_seq_id' => '123457',
            'source_prev_seq_id' => null,
            'source_epoch' => 1,
            'origin' => 'ws_books',
        ], $event->payload);
        self::assertArrayNotHasKey('bids', $event->payload);
        self::assertArrayNotHasKey('asks', $event->payload);
        self::assertArrayNotHasKey('checksum', $event->payload);
    }

    /** @return iterable<string, array{string}> */
    public static function topOfBookOriginProvider(): iterable
    {
        yield 'initial REST snapshot' => ['rest_initial_snapshot'];
        yield 'REST resync snapshot' => ['rest_resync_snapshot'];
        yield 'WebSocket books' => ['ws_books'];
    }

    #[DataProvider('topOfBookOriginProvider')]
    public function testMaterializedTopOfBookAllowsOnlyExplicitPublicOrigins(string $origin): void
    {
        $row = $this->fixture('order-book.json')['data'][0];
        $event = $this->normalizer()->materializedTopOfBook(
            'BTC-USDT-SWAP',
            OkxMaterializedBookState::fromSnapshot($row),
            1,
            $origin,
        );

        self::assertSame($origin, $event->payload['origin']);
    }

    public function testConnectionStatesAndSnapshotBoundariesUseExactControlContracts(): void
    {
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);

        foreach (['connected', 'subscribed', 'reconnecting', 'stopped'] as $index => $state) {
            $event = $normalizer->connectionState('BTC-USDT-SWAP', $state, 7);
            self::assertSame(PaperMarketDataVenue::OKX, $event->sourceVenue);
            self::assertSame('BTCUSDT', $event->symbol);
            self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $event->channel);
            self::assertSame((string) ($index + 1), $event->sequence);
            self::assertSame('2026-07-21T02:00:00.000000Z', $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'));
            self::assertEquals($event->exchangeTimestamp, $event->receivedTimestamp);
            self::assertSame([
                'native_symbol' => 'BTC-USDT-SWAP',
                'state' => $state,
                'connection_epoch' => 7,
            ], $event->payload);
            PaperMarketEventRedactor::assertSafe($event->payload);
        }

        foreach (['initial', 'reconnect', 'sequence_gap'] as $index => $reason) {
            $event = $normalizer->snapshotBoundary('ETH-USDT-SWAP', $reason, 11, '323457');
            self::assertSame(PaperMarketDataVenue::OKX, $event->sourceVenue);
            self::assertSame('ETHUSDT', $event->symbol);
            self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $event->channel);
            self::assertSame((string) ($index + 1), $event->sequence);
            self::assertSame('2026-07-21T02:00:00.000000Z', $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'));
            self::assertEquals($event->exchangeTimestamp, $event->receivedTimestamp);
            self::assertSame([
                'native_symbol' => 'ETH-USDT-SWAP',
                'reason' => $reason,
                'source_epoch' => 11,
                'source_seq_id' => '323457',
            ], $event->payload);
            PaperMarketEventRedactor::assertSafe($event->payload);
        }

        $scopes = $ordinals->snapshot()['scopes'];
        self::assertSame(
            'connection|7|stopped',
            $scopes['okx/BTCUSDT/connection_state']['latest']['natural_identity'],
        );
        self::assertSame(
            'snapshot|11|323457|sequence_gap',
            $scopes['okx/ETHUSDT/snapshot_boundary']['latest']['natural_identity'],
        );
    }

    /**
     * @return iterable<string, array{callable(OkxPaperMarketEventNormalizer): void, string}>
     */
    public static function invalidLiveControlProvider(): iterable
    {
        yield 'unknown connection state' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->connectionState('BTC-USDT-SWAP', 'disconnected', 1);
            },
            'okx_paper_connection_state_invalid',
        ];
        yield 'zero connection epoch' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->connectionState('BTC-USDT-SWAP', 'connected', 0);
            },
            'okx_paper_connection_state_invalid',
        ];
        yield 'negative connection epoch' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->connectionState('BTC-USDT-SWAP', 'connected', -1);
            },
            'okx_paper_connection_state_invalid',
        ];
        yield 'unknown snapshot reason' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'manual', 1, '123457');
            },
            'okx_paper_snapshot_boundary_invalid',
        ];
        yield 'zero snapshot epoch' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 0, '123457');
            },
            'okx_paper_snapshot_boundary_invalid',
        ];
        yield 'malformed snapshot sequence' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 1, '123.457');
            },
            'okx_paper_source_sequence_invalid',
        ];
        yield 'negative snapshot sequence' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 1, '-1');
            },
            'okx_paper_source_sequence_invalid',
        ];
        yield 'negative zero snapshot sequence' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 1, '-0');
            },
            'okx_paper_source_sequence_invalid',
        ];
        yield 'leading-zero snapshot sequence' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 1, '01');
            },
            'okx_paper_source_sequence_invalid',
        ];
        yield 'explicit-positive snapshot sequence' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 1, '+1');
            },
            'okx_paper_source_sequence_invalid',
        ];
        yield 'whitespace-padded snapshot sequence' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 1, ' 1');
            },
            'okx_paper_source_sequence_invalid',
        ];
        yield 'unknown top-of-book origin' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->materializedTopOfBook(
                    'BTC-USDT-SWAP',
                    OkxMaterializedBookState::fromSnapshot(
                        self::staticFixture('order-book.json')['data'][0],
                    ),
                    1,
                    'business_books',
                );
            },
            'okx_paper_top_of_book_origin_invalid',
        ];
        yield 'zero top-of-book epoch' => [
            static function (OkxPaperMarketEventNormalizer $normalizer): void {
                $normalizer->materializedTopOfBook(
                    'BTC-USDT-SWAP',
                    OkxMaterializedBookState::fromSnapshot(
                        self::staticFixture('order-book.json')['data'][0],
                    ),
                    0,
                );
            },
            'okx_paper_materialized_order_book_invalid',
        ];
    }

    /** @param callable(OkxPaperMarketEventNormalizer): void $normalize */
    #[DataProvider('invalidLiveControlProvider')]
    public function testInvalidLiveControlsFailBeforeOrdinalCommit(
        callable $normalize,
        string $expectedCode,
    ): void {
        $ordinals = new OkxPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);

        try {
            $normalize($normalizer);
            self::fail('Invalid live normalization input must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($expectedCode, $exception->getMessage());
        }

        self::assertSame(['schema_version' => 1, 'scopes' => []], $ordinals->snapshot());
    }

    public function testMaterializedBookStateHasExplicitSnapshotAndAppliedDeltaFactories(): void
    {
        self::assertTrue(class_exists(OkxMaterializedBookState::class));
        self::assertTrue(method_exists(OkxMaterializedBookState::class, 'fromSnapshot'));
        self::assertTrue(method_exists(OkxMaterializedBookState::class, 'fromAppliedDelta'));
        self::assertTrue((new \ReflectionClass(OkxMaterializedBookState::class))->isReadOnly());
        $parameter = (new \ReflectionMethod(
            OkxPaperMarketEventNormalizer::class,
            'materializedTopOfBook',
        ))->getParameters()[1];
        self::assertSame(OkxMaterializedBookState::class, (string) $parameter->getType());
    }

    public function testRawWebSocketUpdateArrayCannotCrossTheMaterializedBookBoundary(): void
    {
        $fixture = $this->fixture('ws-books-update.json');

        $this->expectException(\TypeError::class);

        (new \ReflectionMethod(OkxPaperMarketEventNormalizer::class, 'materializedTopOfBook'))
            ->invoke($this->normalizer(), $fixture['arg']['instId'], $fixture['data'][0], 2);
    }

    public function testWebSocketBookSnapshotFixtureNormalizesItsValidBestLevels(): void
    {
        $fixture = $this->fixture('ws-books-snapshot.json');
        $event = $this->normalizer()->materializedTopOfBook(
            $fixture['arg']['instId'],
            OkxMaterializedBookState::fromSnapshot($fixture['data'][0]),
            1,
        );

        self::assertSame('65080.4000', $event->payload['bid_price']);
        self::assertSame('65080.5000', $event->payload['ask_price']);
        self::assertSame('-1', $event->payload['source_prev_seq_id']);
        self::assertSame('223457', $event->payload['source_seq_id']);
    }

    public function testCompleteStateAfterAppliedDeltaPreservesMaterializedLevels(): void
    {
        $fixture = $this->fixture('materialized-after-update.json');
        $materializedState = OkxMaterializedBookState::fromAppliedDelta(
            $fixture['complete_state_after_applied_delta'],
        );
        $event = $this->normalizer()->materializedTopOfBook(
            $fixture['instrument_id'],
            $materializedState,
            2,
        );

        self::assertSame('ETHUSDT', $event->symbol);
        self::assertSame('3525.4000', $event->payload['bid_price']);
        self::assertSame('3525.6000', $event->payload['ask_price']);
        self::assertSame('323456', $event->payload['source_prev_seq_id']);
        self::assertSame(2, $event->payload['source_epoch']);
    }

    public function testMaterializedBookStateRejectsAnIncompleteAppliedDeltaResult(): void
    {
        self::assertTrue(method_exists(OkxPaperMarketEventNormalizer::class, 'materializedTopOfBook'));
        self::assertFalse(method_exists(OkxPaperMarketEventNormalizer::class, 'topOfBook'));

        $row = $this->fixture('materialized-after-update.json')['complete_state_after_applied_delta'];
        $row['asks'] = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_materialized_order_book_invalid');

        OkxMaterializedBookState::fromAppliedDelta($row);
    }

    public function testMaterializedBookStateRejectsRawZeroSizeDeletion(): void
    {
        self::assertTrue(method_exists(OkxPaperMarketEventNormalizer::class, 'materializedTopOfBook'));

        $row = $this->fixture('materialized-after-update.json')['complete_state_after_applied_delta'];
        $row['bids'][0][1] = '0';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_materialized_order_book_invalid');

        OkxMaterializedBookState::fromAppliedDelta($row);
    }

    /** @return iterable<string, array{int|string|null, string}> */
    public static function malformedCandleNumberProvider(): iterable
    {
        yield 'blank' => ['', 'okx_paper_decimal_invalid'];
        yield 'whitespace' => [' ', 'okx_paper_decimal_invalid'];
        yield 'exponent' => ['6.5e4', 'okx_paper_decimal_invalid'];
        yield 'comma' => ['65,000.1', 'okx_paper_decimal_invalid'];
        yield 'non-string integer' => [65000, 'okx_paper_decimal_invalid'];
        yield 'missing' => [null, 'okx_paper_decimal_invalid'];
    }

    #[DataProvider('malformedCandleNumberProvider')]
    public function testBlankMalformedOrMissingCandleNumbersAreRejectedAndNeverZeroFilled(
        int|string|null $value,
        string $error,
    ): void {
        $row = $this->fixture('history-candles.json')['data'][0];
        if ($value === null) {
            unset($row[4]);
        } else {
            $row[4] = $value;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        $this->normalizer()->historyCandle('BTC-USDT-SWAP', '1m', $row);
    }

    public function testMalformedConfirmationIsRejectedInsteadOfTreatedAsFalse(): void
    {
        $row = $this->fixture('history-candles.json')['data'][0];
        $row[8] = 1;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_candle_confirmation_invalid');

        $this->normalizer()->historyCandle('BTC-USDT-SWAP', '1m', $row);
    }

    public function testMissingTradeSourceIsRejectedInsteadOfZeroFilled(): void
    {
        $row = $this->fixture('history-trades.json')['data'][0];
        unset($row['source']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_unsigned_integer_invalid');

        $this->normalizer()->historyTrade($row);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): void}> */
    public static function invalidBookMutationProvider(): iterable
    {
        yield 'missing asks' => [static function (array &$row): void { unset($row['asks']); }];
        yield 'blank bid price' => [static function (array &$row): void { $row['bids'][0][0] = ''; }];
        yield 'malformed non-best ask size' => [static function (array &$row): void { $row['asks'][0][1] = 'unknown'; }];
        yield 'zero active bid size' => [static function (array &$row): void { $row['bids'][0][1] = '0'; }];
        yield 'zero active ask order count' => [static function (array &$row): void { $row['asks'][0][3] = '0'; }];
    }

    /** @param callable(array<string, mixed>): void $mutation */
    #[DataProvider('invalidBookMutationProvider')]
    public function testOrderBookRejectsAbsentOrInvalidBestLevelInputs(callable $mutation): void
    {
        $row = $this->fixture('order-book.json')['data'][0];
        $mutation($row);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_paper_materialized_order_book_invalid');

        OkxMaterializedBookState::fromSnapshot($row);
    }

    public function testEveryNormalizationFixtureProducesOnlyRedactorSafeWhitelistedPayloads(): void
    {
        $normalizer = $this->normalizer();
        $historyCandles = $this->fixture('history-candles.json');
        $currentCandles = $this->fixture('current-candles.json');
        $historyTrades = $this->fixture('history-trades.json');
        $recentTrades = $this->fixture('recent-trades.json');
        $restBook = $this->fixture('order-book.json');
        $wsSnapshot = $this->fixture('ws-books-snapshot.json');
        $materializedAfterUpdate = $this->fixture('materialized-after-update.json');
        $wsTrades = $this->fixture('ws-trades.json');
        $wsCandle = $this->fixture('ws-candle-1m.json');

        $events = [
            $normalizer->historyCandle('BTC-USDT-SWAP', '1m', $historyCandles['data'][0]),
            $normalizer->warmupCandle('ETH-USDT-SWAP', '1H', $currentCandles['data'][0]),
            $normalizer->historyTrade($historyTrades['data'][0]),
            $normalizer->recoveryTrade($recentTrades['data'][0]),
            $normalizer->materializedTopOfBook(
                'BTC-USDT-SWAP',
                OkxMaterializedBookState::fromSnapshot($restBook['data'][0]),
                1,
            ),
            $normalizer->materializedTopOfBook(
                $wsSnapshot['arg']['instId'],
                OkxMaterializedBookState::fromSnapshot($wsSnapshot['data'][0]),
                2,
            ),
            $normalizer->materializedTopOfBook(
                $materializedAfterUpdate['instrument_id'],
                OkxMaterializedBookState::fromAppliedDelta(
                    $materializedAfterUpdate['complete_state_after_applied_delta'],
                ),
                1,
            ),
            $normalizer->webSocketTrade($wsTrades['data'][0]),
            $normalizer->webSocketTrade($wsTrades['data'][1]),
            $normalizer->webSocketCandle(
                $wsCandle['arg']['instId'],
                $wsCandle['arg']['channel'],
                $wsCandle['data'][0],
            ),
            $normalizer->connectionState('BTC-USDT-SWAP', 'connected', 1),
            $normalizer->snapshotBoundary('BTC-USDT-SWAP', 'initial', 1, '123457'),
        ];

        foreach ($events as $event) {
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            PaperMarketEventRedactor::assertSafe($event->payload);
            self::assertStringNotContainsString('authorization', strtolower(json_encode($event->toArray(), JSON_THROW_ON_ERROR)));
            self::assertStringNotContainsString('ok-access-', strtolower(json_encode($event->toArray(), JSON_THROW_ON_ERROR)));
        }
    }

    private function normalizer(
        ?MockClock $clock = null,
        ?OkxPaperSourceOrdinal $ordinals = null,
    ): OkxPaperMarketEventNormalizer
    {
        $clock ??= new MockClock('2026-07-21T02:00:00.000000Z');
        if ($ordinals === null) {
            return new OkxPaperMarketEventNormalizer($clock);
        }

        return new OkxPaperMarketEventNormalizer($clock, ordinals: $ordinals);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return self::staticFixture($name);
    }

    /** @return array<string, mixed> */
    private static function staticFixture(string $name): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/OkxPaperPublic/' . $name;
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
