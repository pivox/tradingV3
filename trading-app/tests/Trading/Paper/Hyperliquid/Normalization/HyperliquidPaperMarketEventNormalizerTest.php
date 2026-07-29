<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Normalization;

use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPrudentBookModel;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidPaperMarketEventNormalizer::class)]
#[CoversClass(HyperliquidPaperSourceOrdinal::class)]
final class HyperliquidPaperMarketEventNormalizerTest extends TestCase
{
    public function testCandleProducesTheExactCanonicalHistoricalEvent(): void
    {
        $event = $this->normalizer()->candle($this->candle());

        self::assertSame(PaperMarketDataNetwork::MAINNET, $event->sourceNetwork);
        self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $event->sourceVenue);
        self::assertSame('BTCUSDT', $event->symbol);
        self::assertSame(PaperMarketDataChannel::CANDLE_15M, $event->channel);
        self::assertSame(
            '2023-04-19T17:14:59.999000Z',
            $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'),
        );
        self::assertEquals($event->exchangeTimestamp, $event->receivedTimestamp);
        self::assertSame('1', $event->sequence);
        self::assertSame([
            'schema_version' => 2,
            'event_id' => '5e21241e4f96cdb6530f4ae7e3ee757c3aa4acbaec91c31144f7d6314d7b31e2',
            'source_network' => 'mainnet',
            'source_venue' => 'hyperliquid',
            'symbol' => 'BTCUSDT',
            'channel' => 'candle_15m',
            'exchange_timestamp' => '2023-04-19T17:14:59.999000Z',
            'received_timestamp' => '2023-04-19T17:14:59.999000Z',
            'sequence' => '1',
            'payload' => [
                'native_symbol' => 'BTC',
                'interval' => '15m',
                'start_time' => '1681923600000',
                'close_time' => '1681924499999',
                'open' => '100',
                'high' => '101',
                'low' => '99',
                'close' => '100',
                'volume' => '5',
                'trade_count' => '10',
                'confirmed' => true,
                'origin' => 'rest_candle_snapshot',
            ],
            'payload_hash' => '864865089fa32d7695a860d2a1b0d58407802cdfd0c112c5fcc198db27fbd222',
        ], $event->toArray());
        PaperMarketEventRedactor::assertSafe($event->payload);
    }

    /**
     * @return iterable<string, array{string, PaperMarketDataChannel, int}>
     */
    public static function channelProvider(): iterable
    {
        yield 'one minute' => ['1m', PaperMarketDataChannel::CANDLE_1M, 60_000];
        yield 'five minutes' => ['5m', PaperMarketDataChannel::CANDLE_5M, 300_000];
        yield 'fifteen minutes' => ['15m', PaperMarketDataChannel::CANDLE_15M, 900_000];
        yield 'one hour' => ['1h', PaperMarketDataChannel::CANDLE_1H, 3_600_000];
    }

    #[DataProvider('channelProvider')]
    public function testCandleMapsEveryExactHistoricalIntervalAndCloseMillisecond(
        string $interval,
        PaperMarketDataChannel $expectedChannel,
        int $duration,
    ): void {
        $event = $this->normalizer()->candle($this->candle(
            interval: $interval,
            start: 0,
        ));

        self::assertSame($expectedChannel, $event->channel);
        self::assertSame($interval, $event->payload['interval']);
        self::assertSame((string) ($duration - 1), $event->payload['close_time']);
        self::assertSame(
            $duration === 3_600_000
                ? '1970-01-01T00:59:59.999000Z'
                : (new \DateTimeImmutable('@' . (string) intdiv($duration - 1, 1_000)))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s') . '.999000Z',
            $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'),
        );
    }

    public function testNetworkIdentityIsStableAcrossReconstructionAndDiffersAcrossNetworks(): void
    {
        $candle = $this->candle();

        $mainnet = $this->normalizer(PaperMarketDataNetwork::MAINNET)->candle($candle);
        $mainnetReconstructed = $this->normalizer(PaperMarketDataNetwork::MAINNET)->candle($candle);
        $testnet = $this->normalizer(PaperMarketDataNetwork::TESTNET)->candle($candle);

        self::assertSame($mainnet->toArray(), $mainnetReconstructed->toArray());
        self::assertSame('mainnet', $mainnet->toArray()['source_network']);
        self::assertSame('testnet', $testnet->toArray()['source_network']);
        self::assertNotSame($mainnet->eventId, $testnet->eventId);
        self::assertSame($mainnet->payloadHash, $testnet->payloadHash);
    }

    public function testDenseSequencesAreIndependentPerNetworkSymbolAndChannel(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $mainnet = $this->normalizer(PaperMarketDataNetwork::MAINNET, $ordinals);
        $testnet = $this->normalizer(PaperMarketDataNetwork::TESTNET, $ordinals);

        $firstCandle = $mainnet->candle($this->candle(interval: '1m', start: 0));
        $secondCandle = $mainnet->candle($this->candle(interval: '1m', start: 60_000));
        $otherChannel = $mainnet->candle($this->candle(interval: '5m', start: 0));
        $otherSymbol = $mainnet->candle($this->candle(
            coin: 'ETH',
            interval: '1m',
            start: 0,
        ));
        $otherNetwork = $testnet->candle($this->candle(interval: '1m', start: 0));

        self::assertSame('1', $firstCandle->sequence);
        self::assertSame('2', $secondCandle->sequence);
        self::assertSame('1', $otherChannel->sequence);
        self::assertSame('1', $otherSymbol->sequence);
        self::assertSame('1', $otherNetwork->sequence);
        self::assertSame([
            'mainnet/hyperliquid/BTCUSDT/candle_1m',
            'mainnet/hyperliquid/BTCUSDT/candle_5m',
            'mainnet/hyperliquid/ETHUSDT/candle_1m',
            'testnet/hyperliquid/BTCUSDT/candle_1m',
        ], array_keys($ordinals->snapshot()['scopes']));
    }

    public function testSameCandleReplaysTheExactEventAndConflictingAssignmentDoesNotConsume(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $candle = $this->candle(interval: '1m', start: 0);

        $first = $normalizer->candle($candle);
        $replay = $normalizer->candle($candle);
        self::assertSame($first, $replay);

        try {
            $normalizer->candle($this->candle(
                interval: '1m',
                start: 0,
                open: '100.25',
                high: '101',
            ));
            self::fail('The same natural candle identity cannot map to different values.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_natural_identity_conflict', $exception->getMessage());
        }

        self::assertSame('2', $normalizer->candle(
            $this->candle(interval: '1m', start: 60_000),
        )->sequence);
    }

    public function testModelledTopOfBookProducesTheExactPinnedSyntheticEvent(): void
    {
        $candle = $this->candle();
        $book = (new HyperliquidPrudentBookModel())->push($candle);
        self::assertNotNull($book);

        $event = $this->normalizer()->modelledTopOfBook($candle, $book);

        self::assertInstanceOf(PaperMarketEvent::class, $event);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $event->channel);
        self::assertSame([
            'schema_version' => 2,
            'event_id' => '6425d9db51ace1f12c06f85e60b92e409f350fef69ee96873ece4deb0942f843',
            'source_network' => 'mainnet',
            'source_venue' => 'hyperliquid',
            'symbol' => 'BTCUSDT',
            'channel' => 'top_of_book',
            'exchange_timestamp' => '2023-04-19T17:14:59.999000Z',
            'received_timestamp' => '2023-04-19T17:14:59.999000Z',
            'sequence' => '1',
            'payload' => [
                'bid_price' => '99.85',
                'bid_size' => '0.5',
                'ask_price' => '100.15',
                'ask_size' => '0.5',
                'model_name' => 'hl_candle_atr_top_v1',
                'model_version' => '1.0.0',
                'origin' => 'historical_candle_model',
                'source_candle_start' => '1681923600000',
                'synthetic' => true,
            ],
            'payload_hash' => 'e32e9b5920e7404f3d8903f5105f2798e524a48be51399a1e49c2ad1079e268a',
        ], $event->toArray());
        self::assertArrayNotHasKey('bids', $event->payload);
        self::assertArrayNotHasKey('asks', $event->payload);
        self::assertArrayNotHasKey('depth', $event->payload);
        self::assertArrayNotHasKey('native_symbol', $event->payload);
        self::assertArrayNotHasKey('spread_bps', $event->payload);
        self::assertArrayNotHasKey('atr', $event->payload);
        self::assertArrayNotHasKey('source_candle_close', $event->payload);
        self::assertArrayNotHasKey('source_interval', $event->payload);
    }

    public function testNullModelOutputDoesNotConsumeAnOrdinal(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $candle = $this->candle();

        self::assertNull($normalizer->modelledTopOfBook($candle, null));
        self::assertSame(['schema_version' => 2, 'scopes' => []], $ordinals->snapshot());

        $book = (new HyperliquidPrudentBookModel())->push($candle);
        self::assertNotNull($book);
        self::assertSame('1', $normalizer->modelledTopOfBook($candle, $book)?->sequence);
    }

    /** @return iterable<string, array{array<array-key, mixed>}|array{array<string, mixed>}> */
    public static function invalidBookProvider(): iterable
    {
        $valid = [
            'bid' => '99.85',
            'ask' => '100.15',
            'size' => '0.5',
            'spread_bps' => '30',
            'atr' => '2',
            'model_name' => HyperliquidPrudentBookModel::NAME,
            'model_version' => HyperliquidPrudentBookModel::VERSION,
        ];

        $missing = $valid;
        unset($missing['atr']);
        yield 'missing key' => [$missing];
        yield 'extra key' => [$valid + ['depth' => []]];
        yield 'list' => [array_values($valid)];

        foreach (['bid', 'ask', 'size', 'spread_bps', 'atr'] as $field) {
            $wrongType = $valid;
            $wrongType[$field] = 1.0;
            yield $field . ' wrong type' => [$wrongType];

            $notCanonical = $valid;
            $notCanonical[$field] = '1.0';
            yield $field . ' not canonical' => [$notCanonical];

            $exponent = $valid;
            $exponent[$field] = '1e2';
            yield $field . ' exponent' => [$exponent];

            $oversize = $valid;
            $oversize[$field] = str_repeat('9', 129);
            yield $field . ' oversize' => [$oversize];
        }

        foreach (['bid', 'ask', 'size'] as $field) {
            $zero = $valid;
            $zero[$field] = '0';
            yield $field . ' zero' => [$zero];

            $negative = $valid;
            $negative[$field] = '-1';
            yield $field . ' negative' => [$negative];
        }

        foreach (['spread_bps', 'atr'] as $field) {
            $negative = $valid;
            $negative[$field] = '-1';
            yield $field . ' negative' => [$negative];
        }

        $bidAtClose = $valid;
        $bidAtClose['bid'] = '100';
        yield 'bid at close' => [$bidAtClose];

        $askAtClose = $valid;
        $askAtClose['ask'] = '100';
        yield 'ask at close' => [$askAtClose];

        $crossed = $valid;
        $crossed['bid'] = '101';
        $crossed['ask'] = '99';
        yield 'crossed around close' => [$crossed];

        $wrongName = $valid;
        $wrongName['model_name'] = 'l2_book';
        yield 'wrong model name' => [$wrongName];

        $wrongVersion = $valid;
        $wrongVersion['model_version'] = '2.0.0';
        yield 'wrong model version' => [$wrongVersion];

        $wrongNameType = $valid;
        $wrongNameType['model_name'] = null;
        yield 'wrong model name type' => [$wrongNameType];

        $inconsistentSpread = $valid;
        $inconsistentSpread['spread_bps'] = '29';
        yield 'spread inconsistent with candle range and atr' => [$inconsistentSpread];

        $inconsistentAtr = $valid;
        $inconsistentAtr['atr'] = '3';
        yield 'atr inconsistent with the supplied spread' => [$inconsistentAtr];

        $inconsistentSize = $valid;
        $inconsistentSize['size'] = '1';
        yield 'size inconsistent with volume per trade' => [$inconsistentSize];

        $outsideClamp = $valid;
        $outsideClamp['spread_bps'] = '51';
        yield 'spread outside model clamp' => [$outsideClamp];

        $extremeBook = $valid;
        $extremeBook['bid'] = '1';
        $extremeBook['ask'] = '200';
        yield 'extreme prices inconsistent with spread' => [$extremeBook];

        $asymmetricBook = $valid;
        $asymmetricBook['bid'] = '99.84';
        yield 'asymmetric bid inconsistent with spread' => [$asymmetricBook];
    }

    /** @param array<array-key, mixed> $book */
    #[DataProvider('invalidBookProvider')]
    public function testMalformedModelOutputFailsBeforeOrdinalMutation(array $book): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);

        try {
            $normalizer->modelledTopOfBook($this->candle(), $book);
            self::fail('Malformed model output must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_paper_modelled_book_invalid', $exception->getMessage());
        }

        self::assertSame(['schema_version' => 2, 'scopes' => []], $ordinals->snapshot());
    }

    public function testModelledBookReplaysAndInvalidProofFailsWithoutConsumption(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $candle = $this->candle();
        $book = (new HyperliquidPrudentBookModel())->push($candle);
        self::assertNotNull($book);

        $first = $normalizer->modelledTopOfBook($candle, $book);
        self::assertNotNull($first);
        self::assertSame($first, $normalizer->modelledTopOfBook($candle, $book));

        $conflicting = $book;
        $conflicting['size'] = '1';
        try {
            $normalizer->modelledTopOfBook($candle, $conflicting);
            self::fail('A model identity cannot accept a nondeterministic proof.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_paper_modelled_book_invalid', $exception->getMessage());
        }

        $nextCandle = $this->candle(start: 1_681_924_500_000);
        $nextBook = [
            'bid' => '99.85',
            'ask' => '100.15',
            'size' => '0.5',
            'spread_bps' => '30',
            'atr' => '2',
            'model_name' => HyperliquidPrudentBookModel::NAME,
            'model_version' => HyperliquidPrudentBookModel::VERSION,
        ];
        self::assertSame(
            '2',
            $normalizer->modelledTopOfBook($nextCandle, $nextBook)?->sequence,
        );
    }

    public function testTimestampRangeFailureUsesStableCodeAndDoesNotConsumeAnOrdinal(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $normalizer = $this->normalizer(ordinals: $ordinals);
        $start = \PHP_INT_MAX - 59_999;
        $candle = $this->candle(interval: '1m', start: $start);

        try {
            $normalizer->candle($candle);
            self::fail('An epoch outside the serializable DateTime range must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_paper_timestamp_invalid', $exception->getMessage());
        }

        self::assertSame(['schema_version' => 2, 'scopes' => []], $ordinals->snapshot());
        self::assertSame(
            '1',
            $normalizer->candle($this->candle(interval: '1m', start: 0))->sequence,
        );
    }

    public function testSnapshotReconstructionProducesByteIdenticalReplayAndNextEvents(): void
    {
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $continuous = $this->normalizer(ordinals: $ordinals);
        $firstCandle = $this->candle(interval: '1m', start: 0);
        $first = $continuous->candle($firstCandle);
        $checkpoint = $ordinals->snapshot();

        $resumed = $this->normalizer(
            ordinals: HyperliquidPaperSourceOrdinal::restore($checkpoint),
        );
        self::assertSame(
            json_encode($first->toArray(), \JSON_THROW_ON_ERROR),
            json_encode($resumed->candle($firstCandle)->toArray(), \JSON_THROW_ON_ERROR),
        );

        $nextCandle = $this->candle(interval: '1m', start: 60_000);
        self::assertSame(
            json_encode($continuous->candle($nextCandle)->toArray(), \JSON_THROW_ON_ERROR),
            json_encode($resumed->candle($nextCandle)->toArray(), \JSON_THROW_ON_ERROR),
        );
    }

    public function testModelWitnessRejectsRehashedForgedBookOnRestoreAndCommit(): void
    {
        $candle = $this->candle();
        $book = (new HyperliquidPrudentBookModel())->push($candle);
        self::assertNotNull($book);
        $ordinals = new HyperliquidPaperSourceOrdinal();
        $event = $this->normalizer(ordinals: $ordinals)
            ->modelledTopOfBook($candle, $book);
        self::assertNotNull($event);

        $scope = 'mainnet/hyperliquid/BTCUSDT/top_of_book';
        $naturalIdentity = implode('|', [
            'BTC',
            '15m',
            '1681923600000',
            '1681924499999',
            HyperliquidPrudentBookModel::NAME,
            HyperliquidPrudentBookModel::VERSION,
        ]);
        $snapshot = $ordinals->snapshot();
        self::assertSame(2, $snapshot['schema_version']);
        $witness = $snapshot['scopes'][$scope]['latest']['validation_witness'];
        self::assertSame([
            'source_candle' => [
                'native_symbol' => 'BTC',
                'interval' => '15m',
                'start_time' => '1681923600000',
                'close_time' => '1681924499999',
                'open' => '100',
                'high' => '101',
                'low' => '99',
                'close' => '100',
                'volume' => '5',
                'trade_count' => '10',
            ],
            'spread_bps' => '30',
            'atr' => '2',
        ], $witness);

        $forgedPayload = $event->payload;
        $forgedPayload['bid_price'] = '99';
        $forgedPayload['ask_price'] = '101';
        $forged = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $event->exchangeTimestamp,
            $event->receivedTimestamp,
            '1',
            $forgedPayload,
        );
        $digest = HyperliquidPaperSourceOrdinal::assignmentDigest(
            $naturalIdentity,
            $forged->exchangeTimestamp,
            $forged->payload,
        );
        $forgedState = $snapshot;
        $forgedState['scopes'][$scope]['latest']['event'] = $forged->toArray();
        $forgedState['scopes'][$scope]['latest']['assignment_digest'] = $digest;

        try {
            HyperliquidPaperSourceOrdinal::restore($forgedState);
            self::fail('A rehashed model event without matching deterministic proof must fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame(
                'hyperliquid_paper_source_ordinal_state_invalid',
                $exception->getMessage(),
            );
        }

        $fresh = new HyperliquidPaperSourceOrdinal();
        self::assertSame('1', $fresh->preview($scope, $naturalIdentity, $digest)['sequence']);
        try {
            $fresh->commit($scope, $naturalIdentity, $digest, $forged, $witness);
            self::fail('A forged model event commit must fail before ordinal mutation.');
        } catch (\LogicException $exception) {
            self::assertSame(
                'hyperliquid_paper_source_ordinal_transaction_invalid',
                $exception->getMessage(),
            );
        }
        self::assertSame(['schema_version' => 2, 'scopes' => []], $fresh->snapshot());

        $missingProof = $snapshot;
        $missingProof['scopes'][$scope]['latest']['validation_witness'] = null;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_source_ordinal_state_invalid');
        HyperliquidPaperSourceOrdinal::restore($missingProof);
    }

    public function testConstructorIsNetworkOnlyWithOptionalOrdinalsAndLegacyIsRejected(): void
    {
        $constructor = new \ReflectionMethod(
            HyperliquidPaperMarketEventNormalizer::class,
            '__construct',
        );
        $parameters = $constructor->getParameters();

        self::assertSame(['network', 'ordinals', 'clock'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));
        self::assertSame(PaperMarketDataNetwork::class, (string) $parameters[0]->getType());
        self::assertFalse($parameters[0]->isOptional());
        self::assertSame(
            '?' . HyperliquidPaperSourceOrdinal::class,
            (string) $parameters[1]->getType(),
        );
        self::assertTrue($parameters[1]->isOptional());
        self::assertNull($parameters[1]->getDefaultValue());
        self::assertSame(
            '?' . \Symfony\Component\Clock\ClockInterface::class,
            (string) $parameters[2]->getType(),
        );
        self::assertTrue($parameters[2]->isOptional());
        self::assertNull($parameters[2]->getDefaultValue());

        foreach ([
            'trade',
            'historyTrade',
            'fills',
            'l2Book',
            'order',
            'wallet',
            'action',
            'account',
            'accountState',
            'private',
            'privateRead',
            'privateRequest',
            'positions',
            'openOrders',
            'userFills',
            'orderStatus',
            'clearinghouseState',
        ] as $method) {
            self::assertFalse(method_exists(HyperliquidPaperMarketEventNormalizer::class, $method));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_network_invalid');
        new HyperliquidPaperMarketEventNormalizer(PaperMarketDataNetwork::LEGACY_UNKNOWN);
    }

    private function normalizer(
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
        ?HyperliquidPaperSourceOrdinal $ordinals = null,
    ): HyperliquidPaperMarketEventNormalizer {
        return new HyperliquidPaperMarketEventNormalizer($network, $ordinals);
    }

    private function candle(
        string $coin = 'BTC',
        string $interval = '15m',
        int $start = 1_681_923_600_000,
        string $open = '100',
        string $high = '101',
        string $low = '99',
        string $close = '100',
        string $volume = '5',
        int $trades = 10,
    ): HyperliquidCandle {
        $duration = match ($interval) {
            '1m' => 60_000,
            '5m' => 300_000,
            '15m' => 900_000,
            '1h' => 3_600_000,
            default => throw new \InvalidArgumentException('Unsupported test interval.'),
        };

        return HyperliquidCandle::fromApiRow([
            'T' => $start + ($duration - 1),
            'c' => $close,
            'h' => $high,
            'i' => $interval,
            'l' => $low,
            'n' => $trades,
            'o' => $open,
            's' => $coin,
            't' => $start,
            'v' => $volume,
        ], $coin, $interval);
    }
}
