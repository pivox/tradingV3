<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalFundingSource;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalFundingSource::class)]
final class PaperCanonicalFundingSourceTest extends TestCase
{
    public function testReturnsCurrentHyperliquidContextWithExactEpochLineage(): void
    {
        $funding = $this->hyperliquidFunding('1', '0.0000125', 3);
        $boundary = $this->hyperliquidBoundary('2', 3);
        $trigger = $this->hyperliquidTrigger('3');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$funding, $boundary, $trigger]);

        $snapshot = (new PaperCanonicalFundingSource($market, new PaperReplayClock($trigger->receivedTimestamp)))
            ->snapshotFor($this->hyperliquidCell(), $trigger, 3600);

        self::assertNotNull($snapshot);
        self::assertSame('venue_schedule', $snapshot->source);
        self::assertSame(0.0000125, $snapshot->rate);
        self::assertSame(3600, $snapshot->intervalSeconds);
        self::assertSame('2026-08-01T10:00:58.000000Z', $snapshot->observedAt->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('sha256:' . $funding->eventId, $snapshot->inputHash);
    }

    public function testHyperliquidFundingFailsClosedWhenMissingStaleOrFromAnotherEpoch(): void
    {
        $trigger = $this->hyperliquidTrigger('3');
        foreach ([
            [$this->hyperliquidBoundary('2', 3)],
            [
                $this->hyperliquidFunding('1', '0.0000125', 3, '2026-08-01T08:00:00Z'),
                $this->hyperliquidBoundary('2', 3),
            ],
        ] as $prefix) {
            $market = new PaperMarketStateProjector(new PaperKlineProvider());
            $market->restore([...$prefix, $trigger]);
            self::assertNull((new PaperCanonicalFundingSource($market, new PaperReplayClock($trigger->receivedTimestamp)))
                ->snapshotFor($this->hyperliquidCell(), $trigger, 3600));
        }

        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([
            $this->hyperliquidFunding('1', '0.0000125', 2),
            $this->hyperliquidBoundary('2', 3),
            $trigger,
        ]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_funding_evidence_invalid');
        (new PaperCanonicalFundingSource($market, new PaperReplayClock($trigger->receivedTimestamp)))
            ->snapshotFor($this->hyperliquidCell(), $trigger, 3600);
    }

    public function testReturnsLatestAvailableRateWithExactEventLineage(): void
    {
        $lateReceipt = $this->funding('1', '-0.0002', '2026-08-01T10:00:58Z', '2026-08-01T10:01:00.500Z');
        $newerExchange = $this->funding('2', '0.0001', '2026-08-01T10:00:59Z', '2026-08-01T10:01:00Z');
        $trigger = $this->trigger('3', '2026-08-01T10:01:01Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$lateReceipt, $newerExchange, $trigger]);

        $snapshot = (new PaperCanonicalFundingSource($market, new PaperReplayClock($trigger->receivedTimestamp)))
            ->snapshotFor($this->cell(), $trigger, 28800);

        self::assertNotNull($snapshot);
        self::assertSame('venue_schedule', $snapshot->source);
        self::assertSame(-0.0002, $snapshot->rate);
        self::assertSame(28800, $snapshot->intervalSeconds);
        self::assertSame('2026-08-01T10:00:58.000000Z', $snapshot->observedAt->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('sha256:' . $lateReceipt->eventId, $snapshot->inputHash);
    }

    public function testReturnsNoEvidenceWhenRateIsMissingOrNotYetReceived(): void
    {
        $trigger = $this->trigger('2', '2026-08-01T10:01:01Z');
        foreach ([
            [],
            [$this->funding('1', '0.0001', '2026-08-01T10:00:58Z', '2026-08-01T10:01:02Z')],
            [$this->funding('1', '0.0001', '2026-07-31T00:00:00Z', '2026-08-01T10:00:59Z')],
        ] as $prefix) {
            $market = new PaperMarketStateProjector(new PaperKlineProvider());
            $market->restore([...$prefix, $trigger]);
            self::assertNull((new PaperCanonicalFundingSource($market, new PaperReplayClock($trigger->receivedTimestamp)))
                ->snapshotFor($this->cell(), $trigger, 28800));
        }
    }

    public function testRejectsIntervalMismatchMalformedEvidenceAndStaleTrigger(): void
    {
        $funding = $this->funding('1', '0.0001');
        $trigger = $this->trigger('2', '2026-08-01T10:01:01Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$funding, $trigger]);
        try {
            (new PaperCanonicalFundingSource($market, new PaperReplayClock($trigger->receivedTimestamp)))
                ->snapshotFor($this->cell(), $trigger, 14400);
            self::fail('Mismatched funding interval was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_funding_interval_mismatch', $exception->getMessage());
        }

        $malformed = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::OKX, 'BTCUSDT',
            PaperMarketDataChannel::FUNDING_RATE,
            new \DateTimeImmutable('2026-08-01T10:00:58Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59Z'),
            '1', ['native_symbol' => 'BTC-USDT-SWAP'],
        );
        $market->restore([$malformed, $trigger]);
        try {
            (new PaperCanonicalFundingSource($market, new PaperReplayClock($trigger->receivedTimestamp)))
                ->snapshotFor($this->cell(), $trigger, 28800);
            self::fail('Malformed funding evidence was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_funding_evidence_invalid', $exception->getMessage());
        }

        $newer = $this->trigger('3', '2026-08-01T10:02:01Z', '2026-08-01T10:02:00Z');
        $market->restore([$funding, $trigger, $newer]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_not_current');
        (new PaperCanonicalFundingSource($market, new PaperReplayClock($newer->receivedTimestamp)))
            ->snapshotFor($this->cell(), $trigger, 28800);
    }

    public function testRejectsLegacyAndCrossScopeCells(): void
    {
        $source = new PaperCanonicalFundingSource(
            new PaperMarketStateProjector(new PaperKlineProvider()),
            new PaperReplayClock(),
        );
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64), 'regular', 'paper-funding-legacy-run',
        );
        try {
            $source->snapshotFor($legacy, $this->trigger('1', '2026-08-01T10:01:01Z'), 28800);
            self::fail('Legacy cell was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_strategy_cell_identity_missing', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_market_scope_mismatch');
        $source->snapshotFor($this->cell(), PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID, 'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable('2026-08-01T10:01:00Z'), new \DateTimeImmutable('2026-08-01T10:01:01Z'),
            '1', ['interval' => '1m', 'start_time' => '1785578460000', 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume' => '1', 'confirmed' => true],
        ), 28800);
    }

    private function funding(string $sequence, string $rate, string $exchange = '2026-08-01T10:00:58Z', string $received = '2026-08-01T10:00:59Z'): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::OKX, 'BTCUSDT',
            PaperMarketDataChannel::FUNDING_RATE,
            new \DateTimeImmutable($received), new \DateTimeImmutable($received), $sequence,
            [
                'funding_schema_version' => 'paper-funding-rate.v1',
                'native_symbol' => 'BTC-USDT-SWAP', 'instrument_type' => 'perpetual',
                'funding_rate' => $rate, 'observed_at_ms' => (new \DateTimeImmutable($exchange))->format('Uv'),
                'funding_time_ms' => '1785607200000',
                'next_funding_time_ms' => '1785636000000', 'funding_interval_seconds' => 28800,
                'method' => 'current_period', 'formula_type' => 'withRate',
                'settlement_state' => 'settled', 'source_epoch' => 1,
                'origin' => 'rest_public_funding_rate',
            ],
        );
    }

    private function trigger(string $sequence, string $received, string $exchange = '2026-08-01T10:01:00Z'): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::OKX, 'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable($exchange), new \DateTimeImmutable($received), $sequence,
            ['bar' => '1m', 'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100', 'volume_base' => '1', 'confirmed' => true],
        );
    }

    private function hyperliquidFunding(
        string $sequence,
        string $rate,
        int $sourceEpoch,
        string $observed = '2026-08-01T10:00:58Z',
    ): PaperMarketEvent {
        $timestamp = new \DateTimeImmutable($observed);

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::FUNDING_RATE,
            $timestamp,
            $timestamp,
            $sequence,
            [
                'funding_schema_version' => 'paper-funding-rate.v2',
                'native_symbol' => 'BTC',
                'instrument_type' => 'perpetual',
                'funding_rate' => $rate,
                'observed_at_ms' => $timestamp->format('Uv'),
                'funding_interval_seconds' => 3600,
                'method' => 'current_asset_context',
                'formula_type' => 'metaAndAssetCtxsFunding',
                'settlement_state' => 'processing',
                'source_epoch' => $sourceEpoch,
                'origin' => 'rest_public_meta_and_asset_contexts',
            ],
        );
    }

    private function hyperliquidBoundary(string $sequence, int $sourceEpoch): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
            new \DateTimeImmutable('2026-08-01T10:00:59Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59Z'),
            $sequence,
            [
                'native_symbol' => 'BTC',
                'reason' => 'reconnect',
                'source_epoch' => $sourceEpoch,
            ],
        );
    }

    private function hyperliquidTrigger(string $sequence): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable('2026-08-01T10:01:00Z'),
            new \DateTimeImmutable('2026-08-01T10:01:01Z'),
            $sequence,
            [
                'native_symbol' => 'BTC',
                'interval' => '1m',
                'start_time' => '1785578400000',
                'close_time' => '1785578459999',
                'open' => '100',
                'high' => '101',
                'low' => '99',
                'close' => '100',
                'volume' => '1',
                'trade_count' => '1',
                'confirmed' => true,
                'origin' => 'ws_candle',
            ],
        );
    }

    private function cell(): PaperExecutionCell
    {
        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::OKX,
                'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0', 'long',
                'sha256:' . str_repeat('b', 64), 'sha256:' . str_repeat('c', 64),
            ),
            'paper-funding-run',
        );
    }

    private function hyperliquidCell(): PaperExecutionCell
    {
        return PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('d', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::HYPERLIQUID,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('e', 64),
                'sha256:' . str_repeat('f', 64),
            ),
            'paper-hyperliquid-funding-run',
        );
    }
}
