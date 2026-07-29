<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperMarketEventNormalizer::class)]
#[CoversClass(HyperliquidPaperSourceOrdinal::class)]
final class HyperliquidPaperMarketEventLiveNormalizerTest extends TestCase
{
    public function testTradeIdentityIsStablePerNetworkCoinTimeAndTid(): void
    {
        $row = self::trade();
        $mainnet = self::normalizer(PaperMarketDataNetwork::MAINNET)->liveTrade($row);
        $replay = self::normalizer(PaperMarketDataNetwork::MAINNET)->liveTrade($row);
        $testnet = self::normalizer(PaperMarketDataNetwork::TESTNET)->liveTrade($row);

        self::assertSame($mainnet->toArray(), $replay->toArray());
        self::assertNotSame($mainnet->eventId, $testnet->eventId);
        self::assertSame(PaperMarketDataChannel::PUBLIC_TRADE, $mainnet->channel);
        self::assertSame([
            'native_symbol' => 'BTC',
            'side' => 'buy',
            'price' => '65000',
            'size' => '0.01',
            'transaction_hash' => '0xabc',
            'block_time' => '1000',
            'trade_id' => '42',
            'origin' => 'ws_trades',
        ], $mainnet->payload);
        self::assertArrayNotHasKey('users', $mainnet->payload);
        self::assertSame('1970-01-01T00:00:01.000000Z', self::exchangeTime($mainnet));
        self::assertSame('2026-07-29T10:00:00.123456Z', self::receivedTime($mainnet));
    }

    public function testWireDecimalsAreCanonicalizedBeforeEventConstruction(): void
    {
        $trade = self::trade();
        $trade['px'] = '65000.0';
        $trade['sz'] = '0.0100';
        $tradeEvent = self::normalizer()->liveTrade($trade);
        $bookEvent = self::normalizer()->liveTopOfBook([
            'coin' => 'BTC',
            'levels' => [
                [['px' => '64999.0', 'sz' => '1.00', 'n' => 1]],
                [['px' => '65001.00', 'sz' => '2.000', 'n' => 1]],
            ],
            'time' => 1_001,
        ], sourceEpoch: 1);

        self::assertSame('65000', $tradeEvent->payload['price']);
        self::assertSame('0.01', $tradeEvent->payload['size']);
        self::assertSame('64999', $bookEvent->payload['bid_price']);
        self::assertSame('1', $bookEvent->payload['bid_size']);
        self::assertSame('65001', $bookEvent->payload['ask_price']);
        self::assertSame('2', $bookEvent->payload['ask_size']);
    }

    public function testRealBookSelectsBestPricesWithoutTrustingLevelOrder(): void
    {
        $event = self::normalizer()->liveTopOfBook([
            'coin' => 'BTC',
            'levels' => [
                [
                    ['px' => '64998', 'sz' => '2', 'n' => 1],
                    ['px' => '64999', 'sz' => '1', 'n' => 2],
                ],
                [
                    ['px' => '65002', 'sz' => '1', 'n' => 1],
                    ['px' => '65001', 'sz' => '2', 'n' => 3],
                ],
            ],
            'time' => 1_001,
        ], sourceEpoch: 3);

        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $event->channel);
        self::assertSame('64999', $event->payload['bid_price']);
        self::assertSame('1', $event->payload['bid_size']);
        self::assertSame('65001', $event->payload['ask_price']);
        self::assertSame('2', $event->payload['ask_size']);
        self::assertSame('2', $event->payload['bid_level_count']);
        self::assertSame('2', $event->payload['ask_level_count']);
        self::assertSame('3', $event->payload['source_epoch']);
        self::assertSame('ws_l2_book', $event->payload['origin']);
        self::assertFalse($event->payload['synthetic']);
        self::assertMatchesRegularExpression(
            '/\A[0-9a-f]{64}\z/D',
            $event->payload['source_book_hash'],
        );
    }

    public function testClosedLiveCandleUsesLiveOriginWithoutChangingHistoricalCandle(): void
    {
        $candle = self::candle();
        $live = self::normalizer()->closedLiveCandle($candle);
        $historical = self::normalizer()->candle($candle);

        self::assertSame(PaperMarketDataChannel::CANDLE_1M, $live->channel);
        self::assertTrue($live->payload['confirmed']);
        self::assertSame('ws_candle', $live->payload['origin']);
        self::assertSame('rest_candle_snapshot', $historical->payload['origin']);
        self::assertNotSame($live->payloadHash, $historical->payloadHash);
        self::assertSame('2026-07-29T10:00:00.123456Z', self::receivedTime($live));
        self::assertSame(self::exchangeTime($historical), self::receivedTime($historical));
    }

    public function testOperationalEventsUseInjectedReceiptTimeAndExplicitEpochs(): void
    {
        $normalizer = self::normalizer();
        $connected = $normalizer->connectionState('BTC', 'connected', 2);
        $boundary = $normalizer->snapshotBoundary('BTC', 'reconnect', 3);

        self::assertSame(PaperMarketDataChannel::CONNECTION_STATE, $connected->channel);
        self::assertSame([
            'native_symbol' => 'BTC',
            'state' => 'connected',
            'connection_epoch' => 2,
        ], $connected->payload);
        self::assertSame(PaperMarketDataChannel::SNAPSHOT_BOUNDARY, $boundary->channel);
        self::assertSame([
            'native_symbol' => 'BTC',
            'reason' => 'reconnect',
            'source_epoch' => 3,
        ], $boundary->payload);
        self::assertSame(self::exchangeTime($connected), self::receivedTime($connected));
        self::assertSame(self::exchangeTime($boundary), self::receivedTime($boundary));
    }

    public function testLiveOrdinalSnapshotRestoresExactReplayAndNextSequence(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $continuous = self::normalizer(ordinals: $ordinals);
        $first = $continuous->liveTrade(self::trade());
        $restored = self::normalizer(
            ordinals: HyperliquidPaperSourceOrdinal::restore($ordinals->snapshot()),
        );

        self::assertSame($first->toArray(), $restored->liveTrade(self::trade())->toArray());
        $next = self::trade();
        $next['time'] = 1_001;
        $next['tid'] = 43;
        self::assertSame('2', $continuous->liveTrade($next)->sequence);
        self::assertSame('2', $restored->liveTrade($next)->sequence);
    }

    /** @return array<string, mixed> */
    private static function trade(): array
    {
        return [
            'coin' => 'BTC',
            'side' => 'B',
            'px' => '65000',
            'sz' => '0.01',
            'hash' => '0xabc',
            'time' => 1_000,
            'tid' => 42,
            'users' => ['0xa', '0xb'],
        ];
    }

    private static function candle(): HyperliquidCandle
    {
        return HyperliquidCandle::fromApiRow([
            'T' => 59_999,
            'c' => '2',
            'h' => '3',
            'i' => '1m',
            'l' => '0.5',
            'n' => 5,
            'o' => '1',
            's' => 'BTC',
            't' => 0,
            'v' => '4',
        ], 'BTC', '1m');
    }

    private static function normalizer(
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
        ?HyperliquidPaperSourceOrdinal $ordinals = null,
    ): HyperliquidPaperMarketEventNormalizer {
        return new HyperliquidPaperMarketEventNormalizer(
            network: $network,
            ordinals: $ordinals,
            clock: new MockClock('2026-07-29T10:00:00.123456Z'),
        );
    }

    private static function exchangeTime(object $event): string
    {
        /** @var \DateTimeImmutable $timestamp */
        $timestamp = $event->exchangeTimestamp;

        return $timestamp->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function receivedTime(object $event): string
    {
        /** @var \DateTimeImmutable $timestamp */
        $timestamp = $event->receivedTimestamp;

        return $timestamp->format('Y-m-d\TH:i:s.u\Z');
    }
}
