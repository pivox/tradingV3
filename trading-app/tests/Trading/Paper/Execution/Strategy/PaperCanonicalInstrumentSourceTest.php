<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Backtesting\PaperBacktestAdapterException;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Market\PaperKlineProvider;
use App\Trading\Paper\Execution\Market\PaperMarketStateProjector;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalInstrumentSource;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalInstrumentEvidence;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalInstrumentSource::class)]
#[CoversClass(PaperCanonicalInstrumentEvidence::class)]
final class PaperCanonicalInstrumentSourceTest extends TestCase
{
    public function testReturnsLatestCompleteOkxMetadataWithExactEventLineage(): void
    {
        $lateReceipt = $this->okxMetadata('1', '0.1', '2026-08-01T10:00:58.000000Z', '2026-08-01T10:01:00.500000Z');
        $newerExchange = $this->okxMetadata('2', '0.2', '2026-08-01T10:00:59.000000Z', '2026-08-01T10:01:00.000000Z');
        $trigger = $this->trigger('3', '2026-08-01T10:01:01.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$lateReceipt, $newerExchange, $trigger]);

        $snapshot = (new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->snapshotFor($this->cell(), $trigger);

        self::assertNotNull($snapshot);
        self::assertSame('okx', $snapshot->exchange);
        self::assertSame('mainnet', $snapshot->environment);
        self::assertSame('BTCUSDT', $snapshot->symbol);
        self::assertSame('perpetual', $snapshot->marketType);
        self::assertSame('USDT', $snapshot->quoteCurrency);
        self::assertSame(0.02, $snapshot->contractSize);
        self::assertSame(0.1, $snapshot->quantityStep);
        self::assertSame(0.1, $snapshot->minQuantity);
        self::assertSame(2000.0, $snapshot->maxQuantity);
        self::assertSame(1000.0, $snapshot->marketMaxQuantity);
        self::assertSame(100.0, $snapshot->exchangeLeverageCap);
        self::assertNull($snapshot->symbolLeverageCap);
        self::assertSame('2026-08-01T10:00:58.000000Z', $snapshot->observedAt->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('sha256:' . $lateReceipt->eventId, $snapshot->inputHash);
    }

    public function testReturnsInstrumentAndTickFromTheSameMetadataRecord(): void
    {
        $metadata = $this->okxMetadata('1', '0.1');
        $trigger = $this->trigger('2', '2026-08-01T10:01:01.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$metadata, $trigger]);

        $evidence = (new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->evidenceFor($this->cell(), $trigger, $this->book());

        self::assertNotNull($evidence);
        self::assertSame(0.1, $evidence->tick->tickSize);
        self::assertSame($evidence->instrument->exchange, $evidence->tick->exchange);
        self::assertSame($evidence->instrument->environment, $evidence->tick->environment);
        self::assertSame($evidence->instrument->symbol, $evidence->tick->symbol);
        self::assertSame($evidence->instrument->marketType, $evidence->tick->marketType);
        self::assertSame('2026-08-01T10:01:01.000000Z', $evidence->instrument->observedAt->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-08-01T10:01:01.000000Z', $evidence->tick->observedAt->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame($evidence->instrument->inputHash, $evidence->tick->inputHash);
    }

    public function testReturnsHyperliquidV2EvidenceBoundToTheCurrentBook(): void
    {
        $metadata = $this->hyperliquidMetadata(schema: 'paper-instrument-metadata.v2');
        $trigger = $this->triggerFor(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            '2',
        );
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$metadata, $trigger]);
        $book = $this->book(
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: PaperMarketDataNetwork::TESTNET,
            bestBid: 64_999.0,
            bestAsk: 65_001.0,
        );

        $evidence = (new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->evidenceFor($this->hyperliquidCell(), $trigger, $book);

        self::assertNotNull($evidence);
        self::assertSame('USDT', $evidence->instrument->quoteCurrency);
        self::assertSame(1.0, $evidence->instrument->contractSize);
        self::assertSame(0.00001, $evidence->instrument->quantityStep);
        self::assertSame(2307.6568, $evidence->instrument->maxQuantity);
        self::assertSame(230.76568, $evidence->instrument->marketMaxQuantity);
        self::assertSame(50.0, $evidence->instrument->exchangeLeverageCap);
        self::assertSame(1.0, $evidence->tick->tickSize);
        self::assertSame($evidence->instrument->inputHash, $evidence->tick->inputHash);
        self::assertNotSame('sha256:' . $metadata->eventId, $evidence->instrument->inputHash);
    }

    public function testHyperliquidEvidenceUsesTheFiveSignificantFigureBoundary(): void
    {
        $metadata = $this->hyperliquidMetadata(schema: 'paper-instrument-metadata.v2');
        $trigger = $this->triggerFor(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            '2',
        );
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$metadata, $trigger]);
        $source = new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        );

        self::assertSame(0.1, $source->evidenceFor(
            $this->hyperliquidCell(),
            $trigger,
            $this->book(PaperMarketDataVenue::HYPERLIQUID, PaperMarketDataNetwork::TESTNET, 9_999.8, 9_999.9),
        )?->tick->tickSize);
        self::assertSame(1.0, $source->evidenceFor(
            $this->hyperliquidCell(),
            $trigger,
            $this->book(PaperMarketDataVenue::HYPERLIQUID, PaperMarketDataNetwork::TESTNET, 10_000.0, 10_000.1),
        )?->tick->tickSize);
    }

    public function testHyperliquidEvidenceRejectsABookOutsideTheCell(): void
    {
        $metadata = $this->hyperliquidMetadata(schema: 'paper-instrument-metadata.v2');
        $trigger = $this->triggerFor(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            '2',
        );
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$metadata, $trigger]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_instrument_book_identity_mismatch');
        (new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->evidenceFor($this->hyperliquidCell(), $trigger, $this->book());
    }

    public function testHyperliquidEvidenceRejectsABookFromThePreviousSourceEpoch(): void
    {
        $metadata = $this->hyperliquidMetadata(
            schema: 'paper-instrument-metadata.v2',
            sourceEpoch: 2,
        );
        $trigger = $this->triggerFor(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            '2',
        );
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$metadata, $trigger]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_instrument_book_identity_mismatch');
        (new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->evidenceFor(
            $this->hyperliquidCell(),
            $trigger,
            $this->book(
                PaperMarketDataVenue::HYPERLIQUID,
                PaperMarketDataNetwork::TESTNET,
                sourceEpoch: 1,
            ),
        );
    }

    public function testReturnsNoEvidenceForUnavailableV1Metadata(): void
    {
        foreach ([$this->okxMetadata('1', '0.1', schema: 'paper-instrument-metadata.v1'), $this->hyperliquidMetadata()] as $metadata) {
            $cell = $metadata->sourceVenue === PaperMarketDataVenue::OKX ? $this->cell() : $this->hyperliquidCell();
            $trigger = $this->triggerFor($metadata->sourceNetwork, $metadata->sourceVenue, '2');
            $market = new PaperMarketStateProjector(new PaperKlineProvider());
            $market->restore([$metadata, $trigger]);

            self::assertNull((new PaperCanonicalInstrumentSource(
                $market,
                new PaperReplayClock($trigger->receivedTimestamp),
            ))->snapshotFor($cell, $trigger));
        }
    }

    public function testReturnsNoEvidenceWhenMetadataReceiptIsAfterReplayClock(): void
    {
        $metadata = $this->okxMetadata('1', '0.1', '2026-08-01T10:00:58.000000Z', '2026-08-01T10:01:02.000000Z');
        $trigger = $this->trigger('2', '2026-08-01T10:01:01.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$metadata, $trigger]);

        self::assertNull((new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($trigger->receivedTimestamp),
        ))->snapshotFor($this->cell(), $trigger));
    }

    public function testRejectsStaleTriggerAndMalformedMetadata(): void
    {
        $metadata = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            new \DateTimeImmutable('2026-08-01T10:00:58.000000Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59.000000Z'),
            '1',
            ['native_symbol' => 'BTC-USDT-SWAP'],
        );
        $trigger = $this->trigger('2', '2026-08-01T10:01:01.000000Z');
        $market = new PaperMarketStateProjector(new PaperKlineProvider());
        $market->restore([$metadata, $trigger]);

        try {
            (new PaperCanonicalInstrumentSource(
                $market,
                new PaperReplayClock($trigger->receivedTimestamp),
            ))->snapshotFor($this->cell(), $trigger);
            self::fail('Malformed metadata was accepted.');
        } catch (PaperBacktestAdapterException $exception) {
            self::assertSame('paper_backtest_payload_shape_invalid', $exception->getMessage());
        }

        $newer = $this->trigger('3', '2026-08-01T10:02:01.000000Z', '2026-08-01T10:02:00.000000Z');
        $market->restore([$this->okxMetadata('1', '0.1'), $trigger, $newer]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_not_current');
        (new PaperCanonicalInstrumentSource(
            $market,
            new PaperReplayClock($newer->receivedTimestamp),
        ))->snapshotFor($this->cell(), $trigger);
    }

    public function testRejectsLegacyAndCrossScopeCells(): void
    {
        $source = new PaperCanonicalInstrumentSource(
            new PaperMarketStateProjector(new PaperKlineProvider()),
            new PaperReplayClock(),
        );
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('a', 64),
            'regular',
            'paper-instrument-legacy-run',
        );
        try {
            $source->snapshotFor($legacy, $this->trigger('1', '2026-08-01T10:01:01.000000Z'));
            self::fail('Legacy cell was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_strategy_cell_identity_missing', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_market_scope_mismatch');
        $source->snapshotFor($this->cell(), $this->triggerFor(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            '1',
        ));
    }

    private function okxMetadata(
        string $sequence,
        string $quantityStep,
        string $exchangeTimestamp = '2026-08-01T10:00:58.000000Z',
        string $receivedTimestamp = '2026-08-01T10:00:59.000000Z',
        string $schema = 'paper-instrument-metadata.v2',
    ): PaperMarketEvent {
        $payload = [
            'metadata_schema_version' => $schema,
            'native_symbol' => 'BTC-USDT-SWAP',
            'instrument_type' => 'perpetual',
            'base_asset' => 'BTC',
            'quote_asset' => 'USDT',
            'settlement_asset' => 'USDT',
            'status' => 'live',
            'quantity_unit' => 'contracts',
            'quantity_step' => $quantityStep,
            'minimum_quantity' => $quantityStep,
            'maximum_market_quantity' => '1000',
            'maximum_limit_quantity' => '2000',
            'contract_value' => '0.01',
            'contract_multiplier' => '2',
            'contract_value_unit' => 'BTC',
            'price_tick' => '0.1',
            'source_epoch' => 1,
            'origin' => 'rest_public_instruments',
        ];
        if ($schema === 'paper-instrument-metadata.v2') {
            $payload['maximum_leverage'] = '100';
        }
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            $payload,
        );
    }

    private function hyperliquidMetadata(
        string $schema = 'paper-instrument-metadata.v1',
        int $sourceEpoch = 1,
    ): PaperMarketEvent
    {
        $payload = [
            'metadata_schema_version' => $schema,
            'native_symbol' => 'BTC', 'instrument_type' => 'perpetual',
            'base_asset' => 'BTC',
            'quote_asset' => $schema === 'paper-instrument-metadata.v2' ? 'USDT' : 'USDC',
            'settlement_asset' => 'USDC',
            'status' => 'live', 'asset_id' => 0, 'quantity_unit' => 'base_asset',
            'quantity_step' => '0.00001', 'minimum_quantity' => '0.00001',
            'contract_value' => '1', 'contract_multiplier' => '1',
            'contract_value_unit' => 'BTC', 'size_decimals' => 5,
            'price_precision_digits' => 5, 'price_max_decimals' => 1,
            'maximum_leverage' => '50', 'source_epoch' => $sourceEpoch, 'origin' => 'rest_meta',
        ];
        if ($schema === 'paper-instrument-metadata.v2') {
            $payload['maximum_market_notional'] = '15000000';
            $payload['maximum_limit_notional'] = '150000000';
            $payload['order_notional_limit_model'] = 'hyperliquid-max-order-notional-by-leverage.v1';
        }
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            new \DateTimeImmutable('2026-08-01T10:00:58.000000Z'),
            new \DateTimeImmutable('2026-08-01T10:00:59.000000Z'),
            '1',
            $payload,
        );
    }

    private function book(
        PaperMarketDataVenue $venue = PaperMarketDataVenue::OKX,
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
        float $bestBid = 99.9,
        float $bestAsk = 100.1,
        ?int $sourceEpoch = null,
    ): CanonicalOrderBookSnapshot {
        $sourceEpoch ??= $venue === PaperMarketDataVenue::HYPERLIQUID ? 1 : null;
        return new CanonicalOrderBookSnapshot(
            exchange: $venue->value,
            environment: $network->value,
            symbol: 'BTCUSDT',
            marketType: 'perpetual',
            source: 'order_book',
            bestBid: $bestBid,
            bestAsk: $bestAsk,
            spreadBps: 10_000.0 * ($bestAsk - $bestBid) / (($bestAsk + $bestBid) / 2.0),
            observedAt: new \DateTimeImmutable('2026-08-01T10:01:00.000000Z'),
            inputHash: 'sha256:' . hash('sha256', implode('|', [
                $venue->value,
                $network->value,
                (string) $bestBid,
                (string) $bestAsk,
            ])),
            sourceEpoch: $sourceEpoch,
        );
    }

    private function trigger(
        string $sequence,
        string $receivedTimestamp,
        string $exchangeTimestamp = '2026-08-01T10:01:00.000000Z',
    ): PaperMarketEvent {
        return $this->triggerFor(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            $sequence,
            $receivedTimestamp,
            $exchangeTimestamp,
        );
    }

    private function triggerFor(
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        string $sequence,
        string $receivedTimestamp = '2026-08-01T10:01:01.000000Z',
        string $exchangeTimestamp = '2026-08-01T10:01:00.000000Z',
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            $network,
            $venue,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_1M,
            new \DateTimeImmutable($exchangeTimestamp),
            new \DateTimeImmutable($receivedTimestamp),
            $sequence,
            $venue === PaperMarketDataVenue::OKX
                ? [
                    'native_symbol' => 'BTC-USDT-SWAP', 'bar' => '1m',
                    'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100',
                    'volume_contracts' => '10', 'volume_base' => '1', 'volume_quote' => '100',
                    'confirmed' => true, 'origin' => 'rest_history',
                ]
                : [
                    'native_symbol' => 'BTC', 'interval' => '1m',
                    'start_time' => '1785578460000', 'close_time' => '1785578519999',
                    'open' => '100', 'high' => '101', 'low' => '99', 'close' => '100',
                    'volume' => '1', 'trade_count' => '1', 'confirmed' => true,
                    'origin' => 'rest_candle_snapshot',
                ],
        );
    }

    private function cell(): PaperExecutionCell
    {
        return $this->modernCell(PaperMarketDataNetwork::MAINNET, PaperMarketDataVenue::OKX);
    }

    private function hyperliquidCell(): PaperExecutionCell
    {
        return $this->modernCell(PaperMarketDataNetwork::TESTNET, PaperMarketDataVenue::HYPERLIQUID);
    }

    private function modernCell(PaperMarketDataNetwork $network, PaperMarketDataVenue $venue): PaperExecutionCell
    {
        return PaperExecutionCell::createModern(
            $network,
            $venue,
            'sha256:' . str_repeat('a', 64),
            PaperModernStrategyIdentity::fromDurableIdentity(
                $network,
                $venue,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('b', 64),
                'sha256:' . str_repeat('c', 64),
            ),
            'paper-instrument-source-run',
        );
    }
}
