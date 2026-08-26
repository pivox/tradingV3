<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Market;

use App\Common\Enum\Timeframe;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperKlineProviderAdapter;
use App\Trading\Paper\Execution\Market\PaperMarketEffectCodec;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperMarketStateProjector::class)]
#[CoversClass(PaperKlineProviderAdapter::class)]
#[CoversClass(PaperMarketEffectCodec::class)]
final class PaperMarketStateProjectorTest extends TestCase
{
    public function testEquivalentOkxAndHyperliquidCandlesProduceTheSameKline(): void
    {
        $okxProvider = new PaperKlineProvider();
        $hyperliquidProvider = new PaperKlineProvider();
        (new PaperMarketStateProjector($okxProvider))->apply($this->candle(
            PaperMarketDataVenue::OKX,
            PaperMarketDataNetwork::MAINNET,
            ['bar' => '1m', 'open' => '25000', 'high' => '25010', 'low' => '24990', 'close' => '25000.5', 'volume_base' => '12.5', 'confirmed' => true],
            '1',
        ));
        (new PaperMarketStateProjector($hyperliquidProvider))->apply($this->candle(
            PaperMarketDataVenue::HYPERLIQUID,
            PaperMarketDataNetwork::MAINNET,
            ['interval' => '1m', 'start_time' => '1785578400000', 'open' => '25000', 'high' => '25010', 'low' => '24990', 'close' => '25000.5', 'volume' => '12.5', 'confirmed' => true],
            '1',
        ));

        $okx = $okxProvider->getLastKline('BTCUSDT', Timeframe::TF_1M);
        $hyperliquid = $hyperliquidProvider->getLastKline('BTCUSDT', Timeframe::TF_1M);
        self::assertNotNull($okx);
        self::assertNotNull($hyperliquid);
        self::assertEquals($okx, $hyperliquid);
        self::assertSame('25000.5', (string) $hyperliquid->close);
    }

    public function testDuplicateReplayAndJournalRestorationAreEqual(): void
    {
        $provider = new PaperKlineProvider();
        $projector = new PaperMarketStateProjector($provider);
        $events = [
            $this->candle(PaperMarketDataVenue::HYPERLIQUID, PaperMarketDataNetwork::TESTNET, $this->payload('1m', '1785578400000'), '1'),
            $this->candle(PaperMarketDataVenue::HYPERLIQUID, PaperMarketDataNetwork::TESTNET, $this->payload('5m', '1785578700000'), '2', PaperMarketDataChannel::CANDLE_5M),
        ];
        $projector->apply($events[0]);
        $projector->apply($events[0]);
        $projector->apply($events[1]);

        $restoredProvider = new PaperKlineProvider();
        (new PaperMarketStateProjector($restoredProvider))->restore($events);
        self::assertEquals($provider->getKlines('BTCUSDT', Timeframe::TF_1M), $restoredProvider->getKlines('BTCUSDT', Timeframe::TF_1M));
        self::assertEquals($provider->getKlines('BTCUSDT', Timeframe::TF_5M), $restoredProvider->getKlines('BTCUSDT', Timeframe::TF_5M));
    }

    public function testModernProjectionRetainsCanonicalEventsWithoutBuildingLegacyKlines(): void
    {
        $provider = new PaperKlineProvider();
        $projector = new PaperMarketStateProjector($provider);
        $event = $this->candle(
            PaperMarketDataVenue::HYPERLIQUID,
            PaperMarketDataNetwork::TESTNET,
            $this->payload('1m', '1785578400000'),
            '1',
        );

        $projector->apply($event, false);

        self::assertSame([$event], $projector->events());
        self::assertSame([], $provider->getKlines('BTCUSDT', Timeframe::TF_1M));

        $projector->restore([$event], false);

        self::assertSame([$event], $projector->events());
        self::assertSame([], $provider->getKlines('BTCUSDT', Timeframe::TF_1M));
    }

    public function testTopOfBookUpdatesAndCrossedBookFailsClosed(): void
    {
        $projector = new PaperMarketStateProjector(new PaperKlineProvider());
        $projector->apply($this->book('99.5', '100.5', '10'));
        self::assertSame(['bid' => '99.5', 'ask' => '100.5'], $projector->topOfBook('BTCUSDT'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_market_top_of_book_invalid');
        $projector->apply($this->book('101', '100', '11'));
    }

    public function testUnconfirmedCandleFailsClosed(): void
    {
        $payload = $this->payload('1m', '1785578400000');
        $payload['confirmed'] = false;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_market_candle_unconfirmed');
        (new PaperMarketStateProjector(new PaperKlineProvider()))->apply($this->candle(
            PaperMarketDataVenue::HYPERLIQUID,
            PaperMarketDataNetwork::TESTNET,
            $payload,
            '1',
        ));
    }

    public function testPaperKlineAdapterFeedsTheFakeMtfPortWithoutWrites(): void
    {
        $provider = new PaperKlineProvider();
        (new PaperMarketStateProjector($provider))->apply($this->candle(
            PaperMarketDataVenue::HYPERLIQUID,
            PaperMarketDataNetwork::TESTNET,
            $this->payload('1m', '1785578400000'),
            '1',
        ));
        $adapter = new PaperKlineProviderAdapter($provider);

        self::assertCount(1, $adapter->getKlines('BTCUSDT', Timeframe::TF_1M));
        self::assertNotNull($adapter->getLastKline('BTCUSDT', Timeframe::TF_1M));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_kline_provider_read_only');
        $adapter->saveKline($provider->getLastKline('BTCUSDT', Timeframe::TF_1M));
    }

    public function testMarketEffectCodecRoundTripsAndAuthenticatesTheSourceEvent(): void
    {
        $event = $this->book('99.5', '100.5', '10');
        $codec = new PaperMarketEffectCodec();
        $encoded = $codec->encode($event);
        self::assertSame($event->eventId, $codec->decode($encoded)->eventId);

        $persisted = json_decode(CanonicalJson::encode($encoded), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($persisted);
        self::assertNotSame(array_keys($encoded), array_keys($persisted));
        self::assertSame($event->eventId, $codec->decode($persisted)->eventId);

        $encoded['payload']['sequence'] = '11';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_effect_payload_invalid');
        $codec->decode($encoded);
    }

    /** @return array<string, mixed> */
    private function payload(string $interval, string $startTime): array
    {
        return ['interval' => $interval, 'start_time' => $startTime, 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '5', 'confirmed' => true];
    }

    /** @param array<string, mixed> $payload */
    private function candle(
        PaperMarketDataVenue $venue,
        PaperMarketDataNetwork $network,
        array $payload,
        string $sequence,
        PaperMarketDataChannel $channel = PaperMarketDataChannel::CANDLE_1M,
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            $network, $venue, 'BTCUSDT', $channel,
            new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            new \DateTimeImmutable('2026-08-01T10:00:01+00:00'),
            $sequence, $payload,
        );
    }

    private function book(string $bid, string $ask, string $sequence): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'BTCUSDT', PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            new \DateTimeImmutable('2026-08-01T10:00:01+00:00'),
            $sequence, ['bid_price' => $bid, 'ask_price' => $ask],
        );
    }
}
