<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Backtesting\PaperBacktestAdapterException;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalOrderBookSource;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalOrderBookSource::class)]
final class PaperCanonicalOrderBookSourceTest extends TestCase
{
    public function testReturnsLatestCanonicallyAvailableBookWithExactEventLineage(): void
    {
        $lateReceipt = $this->book('1', '99', '101', '2026-08-01T10:00:58.000000Z', '2026-08-01T10:01:00.500000Z');
        $newerExchange = $this->book('2', '100', '102', '2026-08-01T10:00:59.000000Z', '2026-08-01T10:01:00.000000Z');
        $trigger = $this->trigger('3', '2026-08-01T10:01:01.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$lateReceipt, $newerExchange, $trigger]);

        $snapshot = (new PaperCanonicalOrderBookSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->snapshotFor($this->cell(), $trigger);

        self::assertNotNull($snapshot);
        self::assertSame('okx', $snapshot->exchange);
        self::assertSame('mainnet', $snapshot->environment);
        self::assertSame('BTCUSDT', $snapshot->symbol);
        self::assertSame('perpetual', $snapshot->marketType);
        self::assertSame('order_book', $snapshot->source);
        self::assertSame(99.0, $snapshot->bestBid);
        self::assertSame(101.0, $snapshot->bestAsk);
        self::assertSame(200.0, $snapshot->spreadBps);
        self::assertSame('2026-08-01T10:00:58.000000Z', $snapshot->observedAt->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('sha256:' . $lateReceipt->eventId, $snapshot->inputHash);
        self::assertSame(1, $snapshot->sourceEpoch);
    }

    public function testReturnsNoEvidenceWhenTheOnlyAppliedBookIsNotYetObservable(): void
    {
        $book = $this->book('1', '99', '101', '2026-08-01T10:00:58.000000Z', '2026-08-01T10:01:02.000000Z');
        $trigger = $this->trigger('2', '2026-08-01T10:01:01.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$book, $trigger]);

        self::assertNull((new PaperCanonicalOrderBookSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->snapshotFor($this->cell(), $trigger));
    }

    public function testPreservesTheHyperliquidBookSourceEpoch(): void
    {
        $book = PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-01T10:00:58.000000Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59.000000Z'),
            '1',
            [
                'native_symbol' => 'BTC', 'bid_price' => '99', 'bid_size' => '5',
                'ask_price' => '101', 'ask_size' => '4', 'bid_level_count' => '2',
                'ask_level_count' => '3', 'source_time' => '1785578458000',
                'source_epoch' => '7', 'source_book_hash' => str_repeat('d', 64),
                'origin' => 'ws_l2_book', 'synthetic' => false,
            ],
        );
        $trigger = PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable('2026-08-01T10:01:00.000000Z'),
            new \DateTimeImmutable('2026-08-01T10:01:01.000000Z'),
            '2',
            [
                'native_symbol' => 'BTC', 'interval' => '1m',
                'start_time' => '1785578460000', 'close_time' => '1785578519999',
                'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100',
                'volume' => '1', 'trade_count' => '1', 'confirmed' => true,
                'origin' => 'rest_candle_snapshot',
            ],
        );
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$book, $trigger]);

        $snapshot = (new PaperCanonicalOrderBookSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->snapshotFor($this->hyperliquidCell(), $trigger);

        self::assertNotNull($snapshot);
        self::assertSame(7, $snapshot->sourceEpoch);
    }

    public function testRejectsAnOlderTriggerAgainstANewerAppliedPrefix(): void
    {
        $book = $this->book('1', '99', '101', '2026-08-01T10:00:58.000000Z', '2026-08-01T10:00:59.000000Z');
        $trigger = $this->trigger('2', '2026-08-01T10:01:01.000000Z');
        $newer = $this->trigger('3', '2026-08-01T10:02:01.000000Z', '2026-08-01T10:02:00.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$book, $trigger, $newer]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_not_current');

        (new PaperCanonicalOrderBookSource(
            $market,
            new PaperReplayClock($newer->receivedTimestamp),
        ))->snapshotFor($this->cell(), $trigger);
    }

    public function testRejectsABookThatPassedThePriceCacheButNotTheVenueContract(): void
    {
        $book = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-01T10:00:58.000000Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59.000000Z'),
            '1',
            ['bid_price' => '99', 'ask_price' => '101'],
        );
        $trigger = $this->trigger('2', '2026-08-01T10:01:01.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$book, $trigger]);

        $this->expectException(PaperBacktestAdapterException::class);
        $this->expectExceptionMessage('paper_backtest_payload_shape_invalid');

        (new PaperCanonicalOrderBookSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->snapshotFor($this->cell(), $trigger);
    }

    public function testRejectsLegacyCellWithoutReadingMarketEvidence(): void
    {
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            'regular',
            'paper-order-book-legacy-run',
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_cell_identity_missing');

        (new PaperCanonicalOrderBookSource(
            new PaperMarketStateProjector(new PaperKlineProvider()),
            new PaperReplayClock(),
        ))->snapshotFor($legacy, $this->trigger('1', '2026-08-01T10:01:01.000000Z'));
    }

    public function testRejectsCrossScopeTriggerWithoutReadingMarketEvidence(): void
    {
        $foreign = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable('2026-08-01T10:01:00.000000Z'),
            new \DateTimeImmutable('2026-08-01T10:01:01.000000Z'),
            '1',
            ['interval' => '1m'],
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_market_scope_mismatch');

        (new PaperCanonicalOrderBookSource(
            new PaperMarketStateProjector(new PaperKlineProvider()),
            new PaperReplayClock(),
        ))->snapshotFor($this->cell(), $foreign);
    }

    /** @return array<string, mixed> */
    private function bookPayload(string $sequence, string $bid, string $ask): array
    {
        return [
            'native_symbol' => 'BTC-USDT-SWAP',
            'bid_price' => $bid,
            'bid_size_contracts' => '5',
            'bid_order_count' => '2',
            'ask_price' => $ask,
            'ask_size_contracts' => '4',
            'ask_order_count' => '3',
            'source_seq_id' => $sequence,
            'source_prev_seq_id' => $sequence === '1' ? null : (string) ((int) $sequence - 1),
            'source_epoch' => 1,
            'origin' => 'ws_books',
        ];
    }

    private function book(
        string $sequence,
        string $bid,
        string $ask,
        string $exchangeTimestamp,
        string $receivedTimestamp,
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            $this->bookPayload($sequence, $bid, $ask),
        );
    }

    private function trigger(
        string $sequence,
        string $receivedTimestamp,
        string $exchangeTimestamp = '2026-08-01T10:01:00.000000Z',
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bar' => '1m',
                'open' => '100',
                'high' => '101',
                'low' => '99',
                'close' => '100',
                'volume_contracts' => '10',
                'volume_base' => '1',
                'volume_quote' => '100',
                'confirmed' => true,
                'origin' => 'rest_history',
            ],
        );
    }

    private function cell(): PaperExecutionCell
    {
        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('b', 64),
                'sha256:' . str_repeat('c', 64),
            ),
            'paper-order-book-source-run',
        );
    }

    private function hyperliquidCell(): PaperExecutionCell
    {
        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('a', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('b', 64),
                'sha256:' . str_repeat('c', 64),
            ),
            'paper-order-book-source-hyperliquid-run',
        );
    }
}
