<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Backtesting;

use App\Trading\Paper\Backtesting\NormalizedBacktestCandle;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\Trading\Paper\Backtesting\PaperBacktestAdapterException;
use App\Trading\Paper\Backtesting\PaperBacktestDataset;
use App\Trading\Paper\Backtesting\PaperBacktestDatasetAdapter;
use App\Trading\Paper\Backtesting\PaperBacktestDatasetEncoder;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\VerifiedPaperDatasetSnapshot;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperBacktestDatasetAdapter::class)]
#[CoversClass(PaperBacktestDataset::class)]
#[CoversClass(NormalizedBacktestCandle::class)]
#[CoversClass(NormalizedBacktestPublicBook::class)]
#[CoversClass(NormalizedBacktestPublicTrade::class)]
#[CoversClass(PaperBacktestAdapterException::class)]
#[CoversClass(PaperBacktestDatasetEncoder::class)]
final class PaperBacktestDatasetAdapterTest extends TestCase
{
    public function testPublicBookValueAndDatasetAreStrictlySourceBound(): void
    {
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($this->okxEvent()));
        $book = new NormalizedBacktestPublicBook(
            str_repeat('b', 64),
            $dataset->sourceIdentity['source_checksum'],
            'mainnet',
            'okx',
            'BTCUSDT',
            '2026-08-13T10:00:30.000000Z',
            '2026-08-13T10:00:30.250000Z',
            '30000',
            '2.5',
            '30001',
            '3.5',
            'contracts',
            '2',
            '3',
            'ws_books',
        );

        self::assertSame([
            'schema_version' => 'backtest-public-book.v1',
            'source_record_id' => str_repeat('b', 64),
            'source_checksum' => $dataset->sourceIdentity['source_checksum'],
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'happened_at' => '2026-08-13T10:00:30.000000Z',
            'available_at' => '2026-08-13T10:00:30.250000Z',
            'bid_price' => '30000',
            'bid_quantity' => '2.5',
            'ask_price' => '30001',
            'ask_quantity' => '3.5',
            'quantity_unit' => 'contracts',
            'bid_order_count' => '2',
            'ask_order_count' => '3',
            'origin' => 'ws_books',
        ], $book->toArray());

        $withBook = new PaperBacktestDataset(
            $dataset->sourceIdentity,
            $dataset->candles,
            [],
            [$book],
        );
        self::assertSame([$book], $withBook->publicBooks);

        foreach ([
            ['bidPrice' => '30001'],
            ['quantityUnit' => 'base_asset'],
            ['sourceChecksum' => 'sha256:' . str_repeat('f', 64)],
        ] as $override) {
            try {
                $candidate = new NormalizedBacktestPublicBook(
                    str_repeat('c', 64),
                    $override['sourceChecksum'] ?? $dataset->sourceIdentity['source_checksum'],
                    'mainnet',
                    'okx',
                    'BTCUSDT',
                    '2026-08-13T10:00:30.000000Z',
                    '2026-08-13T10:00:30.250000Z',
                    $override['bidPrice'] ?? '30000',
                    '2.5',
                    '30001',
                    '3.5',
                    $override['quantityUnit'] ?? 'contracts',
                    '2',
                    '3',
                    'ws_books',
                );
                new PaperBacktestDataset(
                    $dataset->sourceIdentity,
                    $dataset->candles,
                    [],
                    [$candidate, $candidate],
                );
                self::fail('Expected strict public-book rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertContains($exception->getMessage(), [
                    'paper_backtest_public_book_invalid',
                    'paper_backtest_public_books_invalid',
                ]);
            }
        }
    }

    public function testNormalizesExactOkxGoldenCandle(): void
    {
        $event = $this->okxEvent();
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($event));

        self::assertSame([
            'source' => 'paper_market_dataset',
            'source_schema_version' => 'paper-market-dataset.v2',
            'source_build_version' => 'paper-recorder.v2',
            'source_checksum' => 'sha256:' . $this->eventsChecksum([$event]),
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
        ], $dataset->sourceIdentity);
        self::assertSame([[
            'schema_version' => 'backtest-candle.v1',
            'source_record_id' => $event->eventId,
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'open_at' => '2026-08-13T10:00:00.000000Z',
            'close_at' => '2026-08-13T10:01:00.000000Z',
            'available_at' => '2026-08-13T10:01:00.000000Z',
            'open' => '30000',
            'high' => '30100',
            'low' => '29900',
            'close' => '30050',
            'volume' => '12.5',
            'complete' => true,
        ]], array_map(static fn ($candle): array => $candle->toArray(), $dataset->candles));
    }

    public function testNormalizesExactHyperliquidGoldenCandle(): void
    {
        $event = $this->hyperliquidEvent();
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($event));

        self::assertSame('hyperliquid', $dataset->sourceIdentity['market_data_venue']);
        self::assertSame([
            'schema_version' => 'backtest-candle.v1',
            'source_record_id' => $event->eventId,
            'source_network' => 'testnet',
            'market_data_venue' => 'hyperliquid',
            'market_type' => 'perpetual',
            'symbol' => 'ETHUSDT',
            'timeframe' => '5m',
            'open_at' => '2026-08-13T10:00:00.000000Z',
            'close_at' => '2026-08-13T10:05:00.000000Z',
            'available_at' => '2026-08-13T10:05:00.500000Z',
            'open' => '4000',
            'high' => '4010',
            'low' => '3990',
            'close' => '4005',
            'volume' => '7.25',
            'complete' => true,
        ], $dataset->candles[0]->toArray());
    }

    public function testNormalizesPublicTradesWithExplicitVenueQuantityUnits(): void
    {
        $candle = $this->okxEvent();
        $okxTrade = $this->okxTrade();
        $okx = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($candle, $okxTrade));
        self::assertSame([[
            'schema_version' => 'backtest-public-trade.v1',
            'source_record_id' => $okxTrade->eventId,
            'source_checksum' => $okx->sourceIdentity['source_checksum'],
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'venue_trade_id' => '42',
            'happened_at' => '2026-08-13T10:00:30.000000Z',
            'available_at' => '2026-08-13T10:00:30.250000Z',
            'aggressor_side' => 'buy',
            'price' => '30000.5',
            'quantity' => '2.5',
            'quantity_unit' => 'contracts',
        ]], array_map(static fn (NormalizedBacktestPublicTrade $trade): array => $trade->toArray(), $okx->publicTrades));

        $hlCandle = $this->hyperliquidEvent();
        $hlTrade = $this->hyperliquidTrade();
        $hl = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($hlCandle, $hlTrade));
        self::assertSame('base_asset', $hl->publicTrades[0]->quantityUnit);
        self::assertSame('sell', $hl->publicTrades[0]->aggressorSide);
        self::assertSame('1786615230000:84', $hl->publicTrades[0]->venueTradeId);

        $this->assertAdapterFailure(
            $this->snapshot($hlCandle, $this->hyperliquidTrade([
                'block_time' => str_repeat('9', 129),
            ])),
            'paper_backtest_public_trade_invalid',
        );
        $this->assertAdapterFailure(
            $this->snapshot($hlCandle, $this->hyperliquidTrade([
                'block_time' => str_repeat('9', 64),
                'trade_id' => str_repeat('8', 64),
            ])),
            'paper_backtest_public_trade_invalid',
        );
        $this->assertAdapterFailure(
            $this->snapshot($hlCandle, $this->hyperliquidTrade([
                'block_time' => '1786615230001',
            ])),
            'paper_backtest_public_trade_invalid',
        );
    }

    public function testAcceptsEveryVerifiedOkxPublicTradeOrigin(): void
    {
        foreach (['rest_history', 'rest_recovery', 'ws_aggregated'] as $origin) {
            $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
                $this->okxEvent(),
                $this->okxTrade(['origin' => $origin]),
            ));

            self::assertSame('42', $dataset->publicTrades[0]->venueTradeId);
        }
    }

    public function testNormalizesAuthenticatedPublicBooksWithVenueSpecificFacts(): void
    {
        $okxBook = $this->okxBook();
        $okx = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $this->okxEvent(),
            $okxBook,
        ));
        self::assertSame([[
            'schema_version' => 'backtest-public-book.v1',
            'source_record_id' => $okxBook->eventId,
            'source_checksum' => $okx->sourceIdentity['source_checksum'],
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'happened_at' => '2026-08-13T10:00:25.000000Z',
            'available_at' => '2026-08-13T10:00:25.125000Z',
            'bid_price' => '30000',
            'bid_quantity' => '2.5',
            'ask_price' => '30001',
            'ask_quantity' => '3.5',
            'quantity_unit' => 'contracts',
            'bid_order_count' => '2',
            'ask_order_count' => '3',
            'origin' => 'ws_books',
        ]], array_map(
            static fn (NormalizedBacktestPublicBook $book): array => $book->toArray(),
            $okx->publicBooks,
        ));

        $hyperliquidBook = $this->hyperliquidBook();
        $hyperliquid = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $this->hyperliquidEvent(),
            $hyperliquidBook,
        ));
        self::assertSame('base_asset', $hyperliquid->publicBooks[0]->quantityUnit);
        self::assertNull($hyperliquid->publicBooks[0]->bidOrderCount);
        self::assertNull($hyperliquid->publicBooks[0]->askOrderCount);
        self::assertSame('ws_l2_book', $hyperliquid->publicBooks[0]->origin);
    }

    public function testPublicBookProjectionAndEncoderFailClosed(): void
    {
        $candle = $this->okxEvent();
        $later = $this->okxBook(sequence: '3');
        $earlier = $this->okxBook(
            ['source_seq_id' => '41'],
            new \DateTimeImmutable('2026-08-13T10:00:20.000000Z'),
            '2',
        );
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $candle,
            $later,
            $earlier,
        ));
        $encoded = (new PaperBacktestDatasetEncoder())->publicBooks($dataset);
        self::assertSame([$earlier->eventId, $later->eventId], array_map(
            static fn (NormalizedBacktestPublicBook $book): string => $book->sourceRecordId,
            $dataset->publicBooks,
        ));
        self::assertSame(2, substr_count($encoded, "\n"));
        foreach (['mode', 'setup', 'profile', 'strategy'] as $forbidden) {
            self::assertStringNotContainsString('"' . $forbidden . '"', $encoded);
        }

        foreach ([
            ['origin' => 'private'],
            ['bid_price' => '30002'],
            ['bid_size_contracts' => 2.5],
            ['bid_order_count' => '0'],
            ['bid_order_count' => '-1'],
            ['private-sentinel' => 'must-not-leak'],
        ] as $override) {
            $this->assertAdapterFailure(
                $this->snapshot($candle, $this->okxBook($override)),
                array_key_exists('private-sentinel', $override)
                    ? 'paper_backtest_payload_shape_invalid'
                    : 'paper_backtest_public_book_invalid',
            );
        }

        foreach ([
            ['synthetic' => true],
            ['origin' => 'historical_candle_model'],
            ['source_time' => '1786615230001'],
            ['source_book_hash' => str_repeat('g', 64)],
            ['bid_level_count' => '0'],
        ] as $override) {
            $this->assertAdapterFailure(
                $this->snapshot($this->hyperliquidEvent(), $this->hyperliquidBook($override)),
                'paper_backtest_public_book_invalid',
            );
        }
    }

    public function testPublicTradeEncoderIsDeterministicAndRejectsSemanticDrift(): void
    {
        $candle = $this->okxEvent();
        $later = $this->okxTrade(sequence: '3');
        $earlier = $this->okxTrade(
            ['trade_id' => '41', 'taker_side' => 'sell'],
            new \DateTimeImmutable('2026-08-13T10:00:20.000000Z'),
            '2',
        );
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($candle, $later, $earlier));
        $encoded = (new PaperBacktestDatasetEncoder())->publicTrades($dataset);

        self::assertSame(['41', '42'], array_map(
            static fn (NormalizedBacktestPublicTrade $trade): string => $trade->venueTradeId,
            $dataset->publicTrades,
        ));
        self::assertSame(2, substr_count($encoded, "\n"));
        foreach (['mode', 'setup', 'profile', 'strategy'] as $forbidden) {
            self::assertStringNotContainsString('"' . $forbidden . '"', $encoded);
        }

        foreach ([
            ['taker_side' => 'unknown'],
            ['price' => '3e4'],
            ['size_contracts' => 2.5],
            ['origin' => 'private'],
            ['trade_id' => str_repeat('9', 129)],
            ['private-sentinel' => 'must-not-leak'],
        ] as $override) {
            $this->assertAdapterFailure(
                $this->snapshot($candle, $this->okxTrade($override)),
                str_contains(json_encode($override, JSON_THROW_ON_ERROR), 'private-sentinel')
                    ? 'paper_backtest_payload_shape_invalid'
                    : 'paper_backtest_public_trade_invalid',
            );
        }
    }

    public function testIgnoresNonCandleEventsButRejectsAnEmptyCandleSet(): void
    {
        $book = $this->okxBook();
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($this->okxEvent(), $book));
        self::assertCount(1, $dataset->candles);
        self::assertCount(1, $dataset->publicBooks);

        try {
            (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($book));
            self::fail('Expected an empty-candle rejection.');
        } catch (PaperBacktestAdapterException $exception) {
            self::assertSame('paper_backtest_candles_empty', $exception->getMessage());
        }
    }

    public function testRejectsMixedProvenanceEvenForIgnoredNonCandleEvents(): void
    {
        $foreignBook = PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-13T10:00:01.000000Z'),
            new \DateTimeImmutable('2026-08-13T10:00:02.000000Z'),
            '2',
            ['native_symbol' => 'BTC'],
        );
        $candle = $this->okxEvent();
        $manifest = $this->manifest($candle, [$candle, $foreignBook]);

        $this->assertAdapterFailure(
            new VerifiedPaperDatasetSnapshot($manifest, [$candle, $foreignBook]),
            'paper_backtest_event_provenance_invalid',
        );
    }

    public function testIgnoresCertifiedHyperliquidModelledBookWithoutNativeSymbol(): void
    {
        $candle = $this->hyperliquidEvent();
        $book = PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'ETHUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-13T10:05:00.000000Z'),
            new \DateTimeImmutable('2026-08-13T10:05:00.000000Z'),
            '2',
            [
                'bid_price' => '3999', 'bid_size' => '1', 'ask_price' => '4001',
                'ask_size' => '1', 'model_name' => 'hl_candle_atr_top_v1',
                'model_version' => '1.0.0', 'origin' => 'historical_candle_model',
                'source_candle_start' => '1786615200000', 'synthetic' => true,
            ],
        );

        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshotWithQuality(
            PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
            $candle,
            $book,
        ));
        self::assertCount(1, $dataset->candles);
        self::assertSame([], $dataset->publicBooks);
    }

    public function testRejectsForgedManifestCountAndNativeSymbolProvenance(): void
    {
        $event = $this->okxEvent();
        $snapshot = $this->snapshot($event);
        $forged = new VerifiedPaperDatasetSnapshot($this->manifest($event, [$event], eventCount: 2), [$event]);
        $this->assertAdapterFailure($forged, 'paper_backtest_manifest_invalid');

        $checksumManifest = $this->manifest($event, [$event]);
        $forgedChecksumManifest = new PaperDatasetManifest(
            schemaVersion: $checksumManifest->schemaVersion,
            recorderVersion: $checksumManifest->recorderVersion,
            datasetId: $checksumManifest->datasetId,
            venue: $checksumManifest->venue,
            network: $checksumManifest->network,
            symbols: $checksumManifest->symbols,
            startExchangeTimestamp: $checksumManifest->startExchangeTimestamp,
            endExchangeTimestamp: $checksumManifest->endExchangeTimestamp,
            channels: $checksumManifest->channels,
            eventCount: $checksumManifest->eventCount,
            sequenceGaps: $checksumManifest->sequenceGaps,
            quality: $checksumManifest->quality,
            modelName: $checksumManifest->modelName,
            modelVersion: $checksumManifest->modelVersion,
            eventsFileSha256: str_repeat('b', 64),
            state: $checksumManifest->state,
            lastEventId: $checksumManifest->lastEventId,
        );
        $this->assertAdapterFailure(
            new VerifiedPaperDatasetSnapshot($forgedChecksumManifest, [$event]),
            'paper_backtest_manifest_invalid',
        );

        $nativeMismatch = new VerifiedPaperDatasetSnapshot(
            $this->manifest($event, [$event], nativeSymbol: 'ETH-USDT-SWAP'),
            [$event],
        );
        $this->assertAdapterFailure($nativeMismatch, 'paper_backtest_event_provenance_invalid');

        $colluding = $this->okxEvent(['native_symbol' => 'BOGUS']);
        $colludingSnapshot = new VerifiedPaperDatasetSnapshot(
            $this->manifest($colluding, [$colluding], nativeSymbol: 'BOGUS'),
            [$colluding],
        );
        $this->assertAdapterFailure($colludingSnapshot, 'paper_backtest_event_provenance_invalid');

        $colludingHyperliquid = $this->hyperliquidEvent(['native_symbol' => 'BOGUS']);
        $this->assertAdapterFailure(new VerifiedPaperDatasetSnapshot(
            $this->manifest(
                $colludingHyperliquid,
                [$colludingHyperliquid],
                nativeSymbol: 'BOGUS',
            ),
            [$colludingHyperliquid],
        ), 'paper_backtest_event_provenance_invalid');
        self::assertSame(1, $snapshot->manifest->eventCount);
    }

    public function testRejectsExactPayloadShapeAndSemanticDrift(): void
    {
        $extra = $this->okxEvent(['private-sentinel' => 'must-not-leak']);
        $this->assertAdapterFailure($this->snapshot($extra), 'paper_backtest_payload_shape_invalid');

        $badOrigin = $this->okxEvent(['origin' => 'legacy']);
        $this->assertAdapterFailure($this->snapshot($badOrigin), 'paper_backtest_okx_payload_invalid');

        $unconfirmed = $this->okxEvent(['confirmed' => false]);
        $this->assertAdapterFailure($this->snapshot($unconfirmed), 'paper_backtest_okx_payload_invalid');

        $wrongBar = $this->okxEvent(['bar' => '5m']);
        $this->assertAdapterFailure($this->snapshot($wrongBar), 'paper_backtest_okx_payload_invalid');
    }

    public function testRejectsMalformedDecimalsAndGeometry(): void
    {
        foreach ([
            ['open' => '3e4'],
            ['open' => '030000'],
            ['open' => '-0'],
            ['open' => '0'],
            ['volume_base' => '-1'],
            ['volume_contracts' => 10],
        ] as $override) {
            $event = $this->okxEvent($override);
            $this->assertAdapterFailure($this->snapshot($event), 'paper_backtest_decimal_invalid');
        }

        $geometry = $this->okxEvent(['low' => '30001']);
        $this->assertAdapterFailure($this->snapshot($geometry), 'paper_backtest_candle_geometry_invalid');
    }

    public function testRejectsOkxGridAndHyperliquidTimeDrift(): void
    {
        $offGrid = $this->okxEvent([], new \DateTimeImmutable('2026-08-13T10:00:00.001000Z'));
        $this->assertAdapterFailure($this->snapshot($offGrid), 'paper_backtest_candle_time_invalid');

        $wrongClose = $this->hyperliquidEvent(['close_time' => '1786615499998']);
        $this->assertAdapterFailure($this->snapshot($wrongClose), 'paper_backtest_candle_time_invalid');

        $wrongEventTimestamp = $this->hyperliquidEvent(
            [],
            new \DateTimeImmutable('2026-08-13T10:04:59.998000Z'),
        );
        $this->assertAdapterFailure($this->snapshot($wrongEventTimestamp), 'paper_backtest_candle_time_invalid');
    }

    public function testNormalizesOneHourAndSortsWithoutDeduplication(): void
    {
        $oneHour = $this->okxEvent(
            ['bar' => '1h'],
            new \DateTimeImmutable('2026-08-13T10:00:00.000000Z'),
            PaperMarketDataChannel::CANDLE_1H,
            '3',
        );
        $oneMinute = $this->okxEvent([], sequence: '2');
        $dataset = (new PaperBacktestDatasetAdapter())->adapt(
            $this->snapshot($oneHour, $oneMinute, $oneMinute),
        );

        self::assertSame(['1m', '1m', '1h'], array_map(
            static fn (NormalizedBacktestCandle $candle): string => $candle->timeframe,
            $dataset->candles,
        ));
        self::assertSame($dataset->candles[0]->sourceRecordId, $dataset->candles[1]->sourceRecordId);
    }

    public function testAcceptsCanonicalSubUnitPricesAndVolume(): void
    {
        $event = $this->okxEvent([
            'open' => '0.5', 'high' => '1.0', 'low' => '0.25', 'close' => '0.75',
            'volume_base' => '0.001',
        ]);
        $candle = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($event))->candles[0];

        self::assertSame(['0.5', '1', '0.25', '0.75', '0.001'], [
            $candle->open, $candle->high, $candle->low, $candle->close, $candle->volume,
        ]);
    }

    public function testStrictValuesRejectEmptyOrContradictorySourcesAndBadTimestamp(): void
    {
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot($this->okxEvent()));
        try {
            new PaperBacktestDataset($dataset->sourceIdentity, [], [], []);
            self::fail('Expected empty result rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_backtest_candles_empty', $exception->getMessage());
        }

        $source = $dataset->sourceIdentity;
        $source['source_network'] = 'testnet';
        try {
            new PaperBacktestDataset($source, $dataset->candles, [], []);
            self::fail('Expected source mismatch rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_backtest_candle_source_mismatch', $exception->getMessage());
        }

        $withTrade = (new PaperBacktestDatasetAdapter())->adapt(
            $this->snapshot($this->okxEvent(), $this->okxTrade()),
        );
        try {
            new PaperBacktestDataset(
                $withTrade->sourceIdentity,
                $withTrade->candles,
                [$withTrade->publicTrades[0], $withTrade->publicTrades[0]],
                [],
            );
            self::fail('Expected duplicate trade rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_backtest_public_trades_invalid', $exception->getMessage());
        }

        $foreignSource = $withTrade->sourceIdentity;
        $foreignSource['source_checksum'] = 'sha256:' . str_repeat('f', 64);
        try {
            new PaperBacktestDataset(
                $foreignSource,
                $withTrade->candles,
                $withTrade->publicTrades,
                [],
            );
            self::fail('Expected trade source checksum mismatch rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_backtest_public_trades_invalid', $exception->getMessage());
        }

        $candle = $dataset->candles[0];
        try {
            new NormalizedBacktestCandle(
                $candle->sourceRecordId, $candle->sourceNetwork, $candle->marketDataVenue,
                $candle->symbol, $candle->timeframe, "private-sentinel\0", $candle->closeAt,
                $candle->availableAt, $candle->open, $candle->high, $candle->low,
                $candle->close, $candle->volume,
            );
            self::fail('Expected timestamp rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_backtest_candle_time_invalid', $exception->getMessage());
            self::assertStringNotContainsString('private-sentinel', $exception->getMessage());
        }
    }

    public function testPublicTradeValueRejectsNonCanonicalOrOversizedVenueIds(): void
    {
        $trade = (new PaperBacktestDatasetAdapter())->adapt(
            $this->snapshot($this->okxEvent(), $this->okxTrade()),
        )->publicTrades[0];

        foreach (['042', str_repeat('9', 129)] as $venueTradeId) {
            try {
                new NormalizedBacktestPublicTrade(
                    $trade->sourceRecordId,
                    $trade->sourceChecksum,
                    $trade->sourceNetwork,
                    $trade->marketDataVenue,
                    $trade->symbol,
                    $venueTradeId,
                    $trade->happenedAt,
                    $trade->availableAt,
                    $trade->aggressorSide,
                    $trade->price,
                    $trade->quantity,
                    $trade->quantityUnit,
                );
                self::fail('Expected a non-canonical venue trade ID rejection.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_backtest_public_trade_invalid', $exception->getMessage());
            }
        }
    }

    public function testEncoderEmitsCanonicalCrossRuntimeBytes(): void
    {
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $this->okxMetadata(),
            $this->okxEvent(sequence: '2'),
            $this->okxTrade(sequence: '3'),
        ));
        $encoder = new PaperBacktestDatasetEncoder();
        $source = $encoder->sourceIdentity($dataset);
        $candles = $encoder->candles($dataset);
        $metadata = $encoder->instrumentMetadata($dataset);
        $conversions = $encoder->quantityConversions($dataset);

        self::assertSame(CanonicalJson::encode($dataset->sourceIdentity) . "\n", $source);
        self::assertSame(CanonicalJson::encode($dataset->candles[0]->toArray()) . "\n", $candles);
        self::assertStringEndsWith("\n", $source);
        self::assertStringEndsWith("\n", $candles);
        self::assertSame(CanonicalJson::encode($dataset->instrumentMetadata[0]->toArray()) . "\n", $metadata);
        self::assertSame(CanonicalJson::encode($dataset->tradeQuantityConversions[0]->toArray()) . "\n", $conversions);
        foreach (['mode', 'setup', 'profile', 'strategy'] as $forbidden) {
            self::assertStringNotContainsString('"' . $forbidden . '"', $source . $candles . $metadata . $conversions);
        }
    }

    public function testCheckedInCrossRuntimeFixturesComeFromThePublicEncoder(): void
    {
        $event = $this->okxEvent(['volume_base' => '0.001']);
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $this->okxMetadata(),
            $event,
            $this->okxTrade(),
            $this->okxBook(),
        ));
        $encoder = new PaperBacktestDatasetEncoder();
        $fixtureRoot = dirname(__DIR__, 3) . '/Fixtures/paper-backtesting';

        self::assertSame(
            $encoder->sourceIdentity($dataset),
            file_get_contents($fixtureRoot . '/source-identity.json'),
        );
        self::assertSame(
            $encoder->candles($dataset),
            file_get_contents($fixtureRoot . '/candles.ndjson'),
        );
        self::assertSame(
            $encoder->publicTrades($dataset),
            file_get_contents($fixtureRoot . '/public-trades.ndjson'),
        );
        self::assertSame(
            $encoder->publicBooks($dataset),
            file_get_contents($fixtureRoot . '/public-books.ndjson'),
        );
        self::assertSame(
            $encoder->instrumentMetadata($dataset),
            file_get_contents($fixtureRoot . '/instrument-metadata.ndjson'),
        );
        self::assertSame(
            $encoder->quantityConversions($dataset),
            file_get_contents($fixtureRoot . '/quantity-conversions.ndjson'),
        );
    }

    public function testProjectsOkxMetadataAndExactEventTimeQuantityConversions(): void
    {
        $metadata = $this->okxMetadata();
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $metadata,
            $this->okxEvent(sequence: '2'),
            $this->okxTrade(sequence: '3'),
            $this->okxBook(sequence: '4'),
        ));

        self::assertCount(1, $dataset->instrumentMetadata);
        self::assertSame($metadata->eventId, $dataset->instrumentMetadata[0]->sourceRecordId);
        self::assertSame(0, $dataset->instrumentMetadata[0]->sourceEventPosition);
        self::assertSame('contracts', $dataset->instrumentMetadata[0]->quantityUnit);
        self::assertSame('0.01', $dataset->instrumentMetadata[0]->contractValue);
        self::assertSame('2', $dataset->instrumentMetadata[0]->contractMultiplier);

        self::assertCount(1, $dataset->tradeQuantityConversions);
        $trade = $dataset->tradeQuantityConversions[0];
        self::assertSame($dataset->publicTrades[0]->sourceRecordId, $trade->sourceRecordId);
        self::assertSame(2, $trade->sourceEventPosition);
        self::assertSame($metadata->eventId, $trade->metadataRecordId);
        self::assertSame(0, $trade->metadataEventPosition);
        self::assertSame('2.5', $trade->sourceQuantity);
        self::assertSame('0.05', $trade->baseQuantity);

        self::assertCount(1, $dataset->bookQuantityConversions);
        $book = $dataset->bookQuantityConversions[0];
        self::assertSame($dataset->publicBooks[0]->sourceRecordId, $book->sourceRecordId);
        self::assertSame(3, $book->sourceEventPosition);
        self::assertSame('2.5', $book->bidSourceQuantity);
        self::assertSame('0.05', $book->bidBaseQuantity);
        self::assertSame('3.5', $book->askSourceQuantity);
        self::assertSame('0.07', $book->askBaseQuantity);
    }

    public function testProjectsHyperliquidMetadataAndIdentityQuantityConversions(): void
    {
        $metadata = $this->hyperliquidMetadata();
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $metadata,
            $this->hyperliquidEvent(),
            $this->hyperliquidTrade(),
            $this->hyperliquidBook(),
        ));

        self::assertSame('base_asset', $dataset->instrumentMetadata[0]->quantityUnit);
        self::assertSame('1', $dataset->instrumentMetadata[0]->contractValue);
        self::assertSame('1', $dataset->instrumentMetadata[0]->contractMultiplier);
        self::assertSame('0.25', $dataset->tradeQuantityConversions[0]->sourceQuantity);
        self::assertSame('0.25', $dataset->tradeQuantityConversions[0]->baseQuantity);
        self::assertSame('0.25', $dataset->bookQuantityConversions[0]->bidBaseQuantity);
        self::assertSame('0.5', $dataset->bookQuantityConversions[0]->askBaseQuantity);
    }

    public function testLegacyRawPublicRowsRemainReadableButHaveNoConversions(): void
    {
        $dataset = (new PaperBacktestDatasetAdapter())->adapt($this->snapshot(
            $this->okxEvent(),
            $this->okxTrade(),
            $this->okxBook(),
        ));

        self::assertCount(1, $dataset->publicTrades);
        self::assertCount(1, $dataset->publicBooks);
        self::assertSame([], $dataset->instrumentMetadata);
        self::assertSame([], $dataset->tradeQuantityConversions);
        self::assertSame([], $dataset->bookQuantityConversions);
    }

    public function testInstrumentMetadataProjectionRejectsInvalidVenuePrecisionFields(): void
    {
        $this->assertAdapterFailure($this->snapshot(
            $this->okxMetadata(['quantity_step' => '0']),
            $this->okxEvent(sequence: '2'),
        ), 'paper_backtest_instrument_metadata_invalid');

        $this->assertAdapterFailure($this->snapshot(
            $this->hyperliquidMetadata(['price_max_decimals' => 3]),
            $this->hyperliquidEvent(),
        ), 'paper_backtest_instrument_metadata_invalid');
    }

    /** @param array<string, mixed> $override */
    private function okxEvent(
        array $override = [],
        ?\DateTimeImmutable $exchangeTimestamp = null,
        PaperMarketDataChannel $channel = PaperMarketDataChannel::CANDLE_1M,
        string $sequence = '1',
    ): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            $channel,
            $exchangeTimestamp ?? new \DateTimeImmutable('2026-08-13T10:00:00.000000Z'),
            new \DateTimeImmutable('2026-08-13T10:00:01.000000Z'),
            $sequence,
            array_replace([
                'native_symbol' => 'BTC-USDT-SWAP', 'bar' => '1m',
                'open' => '30000.00', 'high' => '30100.0', 'low' => '29900.000',
                'close' => '30050.00', 'volume_contracts' => '10.0',
                'volume_base' => '12.500', 'volume_quote' => '375625.00',
                'confirmed' => true, 'origin' => 'rest_history',
            ], $override),
        );
    }

    /** @param array<string, mixed> $override */
    private function hyperliquidEvent(
        array $override = [],
        ?\DateTimeImmutable $exchangeTimestamp = null,
    ): PaperMarketEvent
    {
        $start = 1_786_615_200_000;
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'ETHUSDT',
            PaperMarketDataChannel::CANDLE_5M,
            $exchangeTimestamp ?? new \DateTimeImmutable('2026-08-13T10:04:59.999000Z'),
            new \DateTimeImmutable('2026-08-13T10:05:00.500000Z'),
            '1',
            array_replace([
                'native_symbol' => 'ETH', 'interval' => '5m',
                'start_time' => (string) $start, 'close_time' => (string) ($start + 299_999),
                'open' => '4000.00', 'high' => '4010.0', 'low' => '3990.000',
                'close' => '4005.00', 'volume' => '7.250', 'trade_count' => '12',
                'confirmed' => true, 'origin' => 'ws_candle',
            ], $override),
        );
    }

    /** @param array<string, mixed> $override */
    private function okxTrade(
        array $override = [],
        ?\DateTimeImmutable $exchangeTimestamp = null,
        string $sequence = '2',
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            $exchangeTimestamp ?? new \DateTimeImmutable('2026-08-13T10:00:30.000000Z'),
            ($exchangeTimestamp ?? new \DateTimeImmutable('2026-08-13T10:00:30.000000Z'))->modify('+250 milliseconds'),
            $sequence,
            array_replace([
                'native_symbol' => 'BTC-USDT-SWAP', 'trade_id' => '42',
                'price' => '30000.500', 'size_contracts' => '2.500',
                'taker_side' => 'buy', 'aggregate_count' => null,
                'source' => '0', 'source_seq_id' => null, 'origin' => 'rest_history',
            ], $override),
        );
    }

    /** @param array<string, mixed> $override */
    private function hyperliquidTrade(array $override = []): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'ETHUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            new \DateTimeImmutable('2026-08-13T10:00:30.000000Z'),
            new \DateTimeImmutable('2026-08-13T10:00:30.100000Z'),
            '2',
            array_replace([
                'native_symbol' => 'ETH', 'side' => 'sell', 'price' => '4000.50',
                'size' => '0.250', 'transaction_hash' => '0xabc',
                'block_time' => '1786615230000', 'trade_id' => '84',
                'origin' => 'ws_trades',
            ], $override),
        );
    }

    /** @param array<string, mixed> $override */
    private function okxBook(
        array $override = [],
        ?\DateTimeImmutable $exchangeTimestamp = null,
        string $sequence = '3',
    ): PaperMarketEvent {
        $happenedAt = $exchangeTimestamp
            ?? new \DateTimeImmutable('2026-08-13T10:00:25.000000Z');
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $happenedAt,
            $happenedAt->modify('+125 milliseconds'),
            $sequence,
            array_replace([
                'native_symbol' => 'BTC-USDT-SWAP',
                'bid_price' => '30000.00', 'bid_size_contracts' => '2.500',
                'bid_order_count' => '2', 'ask_price' => '30001.0',
                'ask_size_contracts' => '3.500', 'ask_order_count' => '3',
                'source_seq_id' => '42', 'source_prev_seq_id' => '41',
                'source_epoch' => 1, 'origin' => 'ws_books',
            ], $override),
        );
    }

    /** @param array<string, mixed> $override */
    private function hyperliquidBook(array $override = []): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'ETHUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-08-13T10:00:30.000000Z'),
            new \DateTimeImmutable('2026-08-13T10:00:30.100000Z'),
            '2',
            array_replace([
                'native_symbol' => 'ETH', 'bid_price' => '3999.50',
                'bid_size' => '0.250', 'ask_price' => '4000.50',
                'ask_size' => '0.500', 'bid_level_count' => '2',
                'ask_level_count' => '3', 'source_time' => '1786615230000',
                'source_epoch' => '1', 'source_book_hash' => str_repeat('a', 64),
                'origin' => 'ws_l2_book', 'synthetic' => false,
            ], $override),
        );
    }

    /** @param array<string, mixed> $override */
    private function okxMetadata(
        array $override = [],
        ?\DateTimeImmutable $receivedAt = null,
    ): PaperMarketEvent {
        $timestamp = $receivedAt ?? new \DateTimeImmutable('2026-08-13T09:59:59.000000Z');
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            $timestamp,
            $timestamp,
            '1',
            array_replace([
                'metadata_schema_version' => 'paper-instrument-metadata.v1',
                'native_symbol' => 'BTC-USDT-SWAP',
                'instrument_type' => 'perpetual',
                'base_asset' => 'BTC',
                'quote_asset' => 'USDT',
                'settlement_asset' => 'USDT',
                'status' => 'live',
                'quantity_unit' => 'contracts',
                'quantity_step' => '0.1',
                'minimum_quantity' => '0.1',
                'maximum_market_quantity' => '1000',
                'maximum_limit_quantity' => '2000',
                'contract_value' => '0.01',
                'contract_multiplier' => '2',
                'contract_value_unit' => 'BTC',
                'price_tick' => '0.1',
                'source_epoch' => 1,
                'origin' => 'rest_public_instruments',
            ], $override),
        );
    }

    /** @param array<string, mixed> $override */
    private function hyperliquidMetadata(array $override = []): PaperMarketEvent
    {
        $timestamp = new \DateTimeImmutable('2026-08-13T09:59:59.000000Z');
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'ETHUSDT',
            PaperMarketDataChannel::INSTRUMENT_METADATA,
            $timestamp,
            $timestamp,
            '1',
            array_replace([
                'metadata_schema_version' => 'paper-instrument-metadata.v1',
                'native_symbol' => 'ETH',
                'instrument_type' => 'perpetual',
                'base_asset' => 'ETH',
                'quote_asset' => 'USDC',
                'settlement_asset' => 'USDC',
                'status' => 'live',
                'asset_id' => 1,
                'quantity_unit' => 'base_asset',
                'quantity_step' => '0.0001',
                'minimum_quantity' => '0.0001',
                'contract_value' => '1',
                'contract_multiplier' => '1',
                'contract_value_unit' => 'ETH',
                'size_decimals' => 4,
                'price_precision_digits' => 5,
                'price_max_decimals' => 2,
                'maximum_leverage' => '50',
                'source_epoch' => 1,
                'origin' => 'rest_meta',
            ], $override),
        );
    }

    private function snapshot(PaperMarketEvent ...$events): VerifiedPaperDatasetSnapshot
    {
        $event = $events[0];
        return new VerifiedPaperDatasetSnapshot($this->manifest($event, $events), $events);
    }

    private function snapshotWithQuality(
        PaperMarketDataQuality $quality,
        PaperMarketEvent ...$events,
    ): VerifiedPaperDatasetSnapshot {
        $event = $events[0];
        $manifest = $this->manifest($event, $events);
        return new VerifiedPaperDatasetSnapshot(new PaperDatasetManifest(
            schemaVersion: $manifest->schemaVersion,
            recorderVersion: $manifest->recorderVersion,
            datasetId: $manifest->datasetId,
            venue: $manifest->venue,
            network: $manifest->network,
            symbols: $manifest->symbols,
            startExchangeTimestamp: $manifest->startExchangeTimestamp,
            endExchangeTimestamp: $manifest->endExchangeTimestamp,
            channels: $manifest->channels,
            eventCount: $manifest->eventCount,
            sequenceGaps: $manifest->sequenceGaps,
            quality: $quality,
            modelName: 'hl_candle_atr_top_v1',
            modelVersion: '1.0.0',
            eventsFileSha256: $manifest->eventsFileSha256,
            state: $manifest->state,
            lastEventId: $manifest->lastEventId,
        ), $events);
    }

    /** @param list<PaperMarketEvent> $events */
    private function manifest(
        PaperMarketEvent $event,
        array $events,
        ?int $eventCount = null,
        ?string $nativeSymbol = null,
    ): PaperDatasetManifest {
        $exchangeTimes = array_map(
            static fn (PaperMarketEvent $item): \DateTimeImmutable => $item->exchangeTimestamp,
            $events,
        );
        usort($exchangeTimes, static fn (\DateTimeImmutable $left, \DateTimeImmutable $right): int => $left <=> $right);
        return new PaperDatasetManifest(
            schemaVersion: 2,
            recorderVersion: 'paper-recorder.v2',
            datasetId: 'adapter-test',
            venue: $event->sourceVenue,
            network: $event->sourceNetwork,
            symbols: [$event->symbol => $nativeSymbol ?? $event->payload['native_symbol']],
            startExchangeTimestamp: $exchangeTimes[0],
            endExchangeTimestamp: $exchangeTimes[array_key_last($exchangeTimes)],
            channels: $this->sortedChannels($events),
            eventCount: $eventCount ?? count($events),
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: $this->eventsChecksum($events),
            state: PaperDatasetState::COMPLETE,
            lastEventId: $events[array_key_last($events)]->eventId,
        );
    }

    /** @param list<PaperMarketEvent> $events
     *  @return list<string>
     */
    private function sortedChannels(array $events): array
    {
        $channels = array_values(array_unique(array_map(
            static fn (PaperMarketEvent $item): string => $item->channel->value,
            $events,
        )));
        sort($channels, SORT_STRING);
        return $channels;
    }

    private function assertAdapterFailure(
        VerifiedPaperDatasetSnapshot $snapshot,
        string $reason,
    ): void {
        try {
            (new PaperBacktestDatasetAdapter())->adapt($snapshot);
            self::fail('Expected adapter failure ' . $reason);
        } catch (PaperBacktestAdapterException $exception) {
            self::assertSame($reason, $exception->getMessage());
            self::assertStringNotContainsString('private-sentinel', $exception->getMessage());
        }
    }

    /** @param list<PaperMarketEvent> $events */
    private function eventsChecksum(array $events): string
    {
        $context = hash_init('sha256');
        foreach ($events as $event) {
            hash_update($context, CanonicalJson::encode($event->toArray()) . "\n");
        }

        return hash_final($context);
    }
}
