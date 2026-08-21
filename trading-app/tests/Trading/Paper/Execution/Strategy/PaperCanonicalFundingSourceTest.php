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
}
