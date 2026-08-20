<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Microstructure;

use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Microstructure\PaperMarketStateMicrostructureSnapshotProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperMarketStateMicrostructureSnapshotProvider::class)]
final class PaperMarketStateMicrostructureSnapshotProviderTest extends TestCase
{
    public function testBuildsSnapshotFromTheProductionPaperMarketJournal(): void
    {
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $events = [
            $this->trade('1', '2026-08-10T11:59:10.000000Z', 'buy', '3'),
            $this->trade('2', '2026-08-10T11:59:30.000000Z', 'sell', '1'),
            $this->trade('3', '2026-08-10T11:59:55.000000Z', 'buy', '2'),
            $this->book('2026-08-10T11:59:59.000000Z'),
        ];
        foreach ($events as $event) {
            $market->apply($event);
        }

        $snapshot = (new PaperMarketStateMicrostructureSnapshotProvider($market))->snapshotFor(
            new LineageContext(
                origin: LineageContext::ORIGIN_MANUAL,
                exchange: 'okx',
                environment: 'mainnet',
                marketType: 'perpetual',
                symbol: 'BTCUSDT',
            ),
            new \DateTimeImmutable('2026-08-10T12:00:00.000000Z'),
        );

        self::assertNotNull($snapshot);
        self::assertSame($events[3]->eventId, $snapshot->bookSourceRecordId);
        self::assertSame(array_map(static fn (PaperMarketEvent $event): string => $event->eventId, array_slice($events, 0, 3)), $snapshot->tradeSourceRecordIds);
        self::assertSame('1', $snapshot->spreadBps);
        self::assertSame('0.666666666667', $snapshot->orderFlowImbalance);
    }

    public function testMissingOrForeignPublicJournalFailsClosed(): void
    {
        $provider = new PaperMarketStateMicrostructureSnapshotProvider(
            new PaperMarketStateProjector(new PaperKlineProvider()),
        );

        self::assertNull($provider->snapshotFor(
            new LineageContext(
                origin: LineageContext::ORIGIN_MANUAL,
                exchange: 'hyperliquid',
                environment: 'mainnet',
                marketType: 'perpetual',
                symbol: 'BTCUSDT',
            ),
            new \DateTimeImmutable('2026-08-10T12:00:00.000000Z'),
        ));
    }

    public function testOkxDemoReadsTheMainnetPublicJournal(): void
    {
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        foreach ([
            $this->trade('1', '2026-08-10T11:59:10.000000Z', 'buy', '3'),
            $this->trade('2', '2026-08-10T11:59:30.000000Z', 'sell', '1'),
            $this->trade('3', '2026-08-10T11:59:55.000000Z', 'buy', '2'),
            $this->book('2026-08-10T11:59:59.000000Z'),
        ] as $event) {
            $market->apply($event);
        }

        $snapshot = (new PaperMarketStateMicrostructureSnapshotProvider($market))->snapshotFor(
            new LineageContext(
                origin: LineageContext::ORIGIN_MANUAL,
                exchange: 'okx',
                environment: 'demo',
                marketType: 'perpetual',
                symbol: 'BTCUSDT',
            ),
            new \DateTimeImmutable('2026-08-10T12:00:00.000000Z'),
        );

        self::assertNotNull($snapshot);
        self::assertSame('mainnet', $snapshot->sourceNetwork);
    }

    private function trade(string $id, string $time, string $side, string $quantity): PaperMarketEvent
    {
        $timestamp = new \DateTimeImmutable($time);

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            $timestamp,
            $timestamp,
            $id,
            [
                'native_symbol' => 'BTC-USDT-SWAP',
                'trade_id' => $id,
                'price' => '100',
                'size_contracts' => $quantity,
                'taker_side' => $side,
                'aggregate_count' => null,
                'source' => '0',
                'source_seq_id' => null,
                'origin' => 'ws_aggregated',
            ],
        );
    }

    private function book(string $time): PaperMarketEvent
    {
        $timestamp = new \DateTimeImmutable($time);

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $timestamp,
            $timestamp,
            '4',
            [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bid_price' => '99.995',
                'bid_size_contracts' => '10',
                'bid_order_count' => '2',
                'ask_price' => '100.005',
                'ask_size_contracts' => '12',
                'ask_order_count' => '3',
                'source_seq_id' => '4',
                'source_prev_seq_id' => '3',
                'source_epoch' => 1,
                'origin' => 'ws_books',
            ],
        );
    }
}
