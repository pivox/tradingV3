<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidOrderNotionalLimits;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(HyperliquidPaperMarketEventNormalizer::class)]
#[CoversClass(HyperliquidPaperSourceOrdinal::class)]
#[CoversClass(HyperliquidOrderNotionalLimits::class)]
final class HyperliquidPaperMarketEventLiveNormalizerTest extends TestCase
{
    public function testInstrumentMetadataPreservesDynamicPrecisionAndBaseSizeUnits(): void
    {
        $event = self::normalizer()->instrumentMetadata([
            'coin' => 'BTC',
            'asset_id' => 0,
            'sz_decimals' => 5,
            'max_leverage' => 50,
        ], sourceEpoch: 4);

        self::assertSame(PaperMarketDataChannel::INSTRUMENT_METADATA, $event->channel);
        self::assertSame([
            'metadata_schema_version' => 'paper-instrument-metadata.v2',
            'native_symbol' => 'BTC',
            'instrument_type' => 'perpetual',
            'base_asset' => 'BTC',
            'quote_asset' => 'USDT',
            'settlement_asset' => 'USDC',
            'status' => 'live',
            'asset_id' => 0,
            'quantity_unit' => 'base_asset',
            'quantity_step' => '0.00001',
            'minimum_quantity' => '0.00001',
            'contract_value' => '1',
            'contract_multiplier' => '1',
            'contract_value_unit' => 'BTC',
            'size_decimals' => 5,
            'price_precision_digits' => 5,
            'price_max_decimals' => 1,
            'maximum_leverage' => '50',
            'maximum_market_notional' => '15000000',
            'maximum_limit_notional' => '150000000',
            'order_notional_limit_model' => 'hyperliquid-max-order-notional-by-leverage.v1',
            'source_epoch' => 4,
            'origin' => 'rest_meta',
        ], $event->payload);
        self::assertEquals($event->exchangeTimestamp, $event->receivedTimestamp);
    }

    public function testInstrumentMetadataFreezesEveryPublicOrderNotionalTier(): void
    {
        foreach ([
            25 => ['15000000', '150000000'],
            20 => ['5000000', '50000000'],
            10 => ['2000000', '20000000'],
            9 => ['500000', '5000000'],
        ] as $maximumLeverage => [$market, $limit]) {
            $event = self::normalizer()->instrumentMetadata([
                'coin' => 'BTC',
                'asset_id' => 0,
                'sz_decimals' => 5,
                'max_leverage' => $maximumLeverage,
            ], sourceEpoch: 1);

            self::assertSame($market, $event->payload['maximum_market_notional']);
            self::assertSame($limit, $event->payload['maximum_limit_notional']);
        }
    }

    public function testInstrumentMetadataRejectsUnknownAssetsAndImpossiblePrecision(): void
    {
        foreach ([
            ['coin' => 'SOL', 'asset_id' => 2, 'sz_decimals' => 2, 'max_leverage' => 20],
            ['coin' => 'BTC', 'asset_id' => 0, 'sz_decimals' => 7, 'max_leverage' => 50],
            ['coin' => 'BTC', 'asset_id' => -1, 'sz_decimals' => 5, 'max_leverage' => 50],
            ['coin' => 'BTC', 'asset_id' => 0, 'sz_decimals' => 5, 'max_leverage' => 0],
        ] as $row) {
            $this->expectMetadataFailure($row);
        }
    }

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

    /** @param array<string, mixed> $row */
    private function expectMetadataFailure(array $row): void
    {
        try {
            self::normalizer()->instrumentMetadata($row, 1);
            self::fail('Invalid Hyperliquid metadata must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_paper_instrument_metadata_invalid', $exception->getMessage());
        }
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
