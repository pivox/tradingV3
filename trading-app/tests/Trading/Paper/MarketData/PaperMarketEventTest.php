<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\MarketData;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(PaperMarketEvent::class)]
#[CoversClass(CanonicalJson::class)]
final class PaperMarketEventTest extends TestCase
{
    public function testContractEnumsExposeOnlyTheApprovedValues(): void
    {
        self::assertSame(['okx', 'hyperliquid'], array_column(PaperMarketDataVenue::cases(), 'value'));
        self::assertSame([
            'candle_1m',
            'candle_5m',
            'candle_15m',
            'candle_1h',
            'top_of_book',
            'public_trade',
            'connection_state',
            'snapshot_boundary',
        ], array_column(PaperMarketDataChannel::cases(), 'value'));
        self::assertSame([
            'recorded_public_book_and_trades',
            'public_historical_candles_and_trades',
            'incomplete',
        ], array_column(PaperMarketDataQuality::cases(), 'value'));
    }

    public function testSourceInterfaceExposesVenueAndIterableEvents(): void
    {
        $event = self::event();
        $source = new class($event) implements PaperMarketDataSourceInterface {
            public function __construct(private readonly PaperMarketEvent $event)
            {
            }

            public function venue(): PaperMarketDataVenue
            {
                return PaperMarketDataVenue::OKX;
            }

            public function events(): iterable
            {
                yield $this->event;
            }
        };

        self::assertSame(PaperMarketDataVenue::OKX, $source->venue());
        self::assertSame([$event], iterator_to_array($source->events()));
    }

    public function testCreatesDeterministicImmutableEventAndStrictlyRoundTrips(): void
    {
        $event = self::event();
        $expectedEventId = hash(
            'sha256',
            '1|okx|BTCUSDT|top_of_book|2026-07-19T10:00:00.123456Z|42',
        );
        $expectedPayloadHash = hash(
            'sha256',
            '{"ask":"30001.0","bid":"29999.0"}',
        );

        self::assertSame(1, $event->schemaVersion);
        self::assertSame($expectedEventId, $event->eventId);
        self::assertSame(PaperMarketDataVenue::OKX, $event->sourceVenue);
        self::assertSame('BTCUSDT', $event->symbol);
        self::assertSame(PaperMarketDataChannel::TOP_OF_BOOK, $event->channel);
        self::assertSame('2026-07-19T10:00:00.123456Z', $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('2026-07-19T10:00:00.223456Z', $event->receivedTimestamp->format('Y-m-d\TH:i:s.u\Z'));
        self::assertSame('42', $event->sequence);
        self::assertSame(['ask' => '30001.0', 'bid' => '29999.0'], $event->payload);
        self::assertSame($expectedPayloadHash, $event->payloadHash);
        self::assertSame([
            'schema_version' => 1,
            'event_id' => $expectedEventId,
            'source_venue' => 'okx',
            'symbol' => 'BTCUSDT',
            'channel' => 'top_of_book',
            'exchange_timestamp' => '2026-07-19T10:00:00.123456Z',
            'received_timestamp' => '2026-07-19T10:00:00.223456Z',
            'sequence' => '42',
            'payload' => ['ask' => '30001.0', 'bid' => '29999.0'],
            'payload_hash' => $expectedPayloadHash,
        ], $event->toArray());

        $restored = PaperMarketEvent::fromArray($event->toArray());

        self::assertNotSame($event, $restored);
        self::assertEquals($event, $restored);
        self::assertSame($event->toArray(), $restored->toArray());
        self::assertTrue((new \ReflectionClass(PaperMarketEvent::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(PaperMarketEvent::class))->getConstructor()?->isPrivate());
    }

    public function testCreateDetachesSharedAcyclicExternalReferencesFromThePayload(): void
    {
        $externalPrice = '29999.0';
        $externalBook = ['price' => &$externalPrice, 'size' => '1.2'];
        $event = self::event(
            sequence: null,
            payload: [
                'primary' => &$externalBook,
                'mirror' => &$externalBook,
            ],
        );
        $expectedPayload = $event->payload;

        $externalPrice = '1.0';
        $externalBook['size'] = '999.0';

        self::assertSame([
            'primary' => ['price' => '29999.0', 'size' => '1.2'],
            'mirror' => ['price' => '29999.0', 'size' => '1.2'],
        ], $expectedPayload);
        self::assertSame($expectedPayload, $event->payload);
        self::assertSame(
            $event->payloadHash,
            hash('sha256', CanonicalJson::encode($event->payload)),
        );
    }

    public function testFromArrayDetachesSharedAcyclicExternalReferencesFromThePayload(): void
    {
        $data = self::event(
            sequence: null,
            payload: [
                'primary' => ['price' => '29999.0', 'size' => '1.2'],
                'mirror' => ['price' => '29999.0', 'size' => '1.2'],
            ],
        )->toArray();
        $externalPrice = '29999.0';
        $externalBook = ['price' => &$externalPrice, 'size' => '1.2'];
        $data['payload']['primary'] = &$externalBook;
        $data['payload']['mirror'] = &$externalBook;

        $event = PaperMarketEvent::fromArray($data);
        $expectedPayload = $event->payload;
        $externalPrice = '1.0';
        $externalBook['size'] = '999.0';

        self::assertSame([
            'primary' => ['price' => '29999.0', 'size' => '1.2'],
            'mirror' => ['price' => '29999.0', 'size' => '1.2'],
        ], $expectedPayload);
        self::assertSame($expectedPayload, $event->payload);
        self::assertSame(
            $event->payloadHash,
            hash('sha256', CanonicalJson::encode($event->payload)),
        );
    }

    public function testRejectsExponentiallyExpandingSharedDagWithinSixtyFourMegabytes(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = sprintf(
            <<<'PHP'
require %s;

$layers = [['price' => '29999.0']];
for ($level = 1; $level <= 20; ++$level) {
    $previous = &$layers[$level - 1];
    $layers[$level] = ['left' => &$previous, 'right' => &$previous];
    unset($previous);
}

try {
    \App\Trading\Paper\MarketData\PaperMarketEvent::create(
        \App\Trading\Paper\MarketData\PaperMarketDataVenue::OKX,
        'BTCUSDT',
        \App\Trading\Paper\MarketData\PaperMarketDataChannel::TOP_OF_BOOK,
        new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
        new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
        '42',
        $layers[20],
    );
} catch (\InvalidArgumentException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit(0);
}

fwrite(STDOUT, 'unexpected_success');
exit(2);
PHP,
            var_export($autoload, true),
        );
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            '-d',
            'xdebug.mode=off',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-r',
            $script,
        ]);
        $process->setTimeout(20.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame('paper_market_payload_nodes_exceeded', $process->getOutput());
    }

    public function testTimestampsNormalizeToUtcWithMicroseconds(): void
    {
        $event = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::HYPERLIQUID,
            symbol: 'ethusdt',
            channel: PaperMarketDataChannel::PUBLIC_TRADE,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T12:00:00.123456+02:00'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T05:00:00.654321-05:00'),
            sequence: null,
            payload: ['price' => '3500.0', 'size' => '0.5'],
        );

        self::assertSame('UTC', $event->exchangeTimestamp->getTimezone()->getName());
        self::assertSame('UTC', $event->receivedTimestamp->getTimezone()->getName());
        self::assertSame('2026-07-19T10:00:00.123456Z', $event->toArray()['exchange_timestamp']);
        self::assertSame('2026-07-19T10:00:00.654321Z', $event->toArray()['received_timestamp']);
    }

    #[DataProvider('unserializableTimestampYearProvider')]
    public function testCreateRejectsTimestampYearsOutsideTheStrictWireFormat(string $field, int $year): void
    {
        $exchangeTimestamp = new \DateTimeImmutable('2026-07-19T10:00:00.123456Z');
        $receivedTimestamp = new \DateTimeImmutable('2026-07-19T10:00:00.223456Z');
        if ($field === 'exchange_timestamp') {
            $exchangeTimestamp = $exchangeTimestamp->setDate($year, 7, 19);
        } else {
            $receivedTimestamp = $receivedTimestamp->setDate($year, 7, 19);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_timestamp_invalid');

        PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $receivedTimestamp,
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unserializableTimestampYearProvider(): iterable
    {
        yield 'extended exchange year' => ['exchange_timestamp', 10_000];
        yield 'negative received year' => ['received_timestamp', -1];
    }

    #[DataProvider('serializableBoundaryYearProvider')]
    public function testCreateRoundTripsStrictFourDigitBoundaryYears(int $year): void
    {
        $timestamp = (new \DateTimeImmutable('2026-01-01T00:00:00.123456Z'))->setDate($year, 1, 1);
        $event = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: $timestamp,
            receivedTimestamp: $timestamp,
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );

        self::assertEquals($event, PaperMarketEvent::fromArray($event->toArray()));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function serializableBoundaryYearProvider(): iterable
    {
        yield 'year zero' => [0];
        yield 'year 9999' => [9999];
    }

    public function testCanonicalPayloadHashIgnoresAssociativeKeyOrderRecursively(): void
    {
        $first = self::event(
            sequence: null,
            payload: [
                'book' => ['ask' => '30001.0', 'bid' => '29999.0'],
                'meta' => ['source' => 'public', 'depth' => 1],
            ],
        );
        $second = self::event(
            sequence: null,
            payload: [
                'meta' => ['depth' => 1, 'source' => 'public'],
                'book' => ['bid' => '29999.0', 'ask' => '30001.0'],
            ],
        );

        self::assertSame($first->payloadHash, $second->payloadHash);
        self::assertSame($first->eventId, $second->eventId);
    }

    public function testCanonicalPayloadHashPreservesListOrder(): void
    {
        $first = self::event(sequence: null, payload: ['levels' => [['29999.0', '1'], ['29998.0', '2']]]);
        $second = self::event(sequence: null, payload: ['levels' => [['29998.0', '2'], ['29999.0', '1']]]);

        self::assertNotSame($first->payloadHash, $second->payloadHash);
        self::assertNotSame($first->eventId, $second->eventId);
    }

    public function testCanonicalJsonUsesTheRequiredStableFlags(): void
    {
        self::assertSame(
            '{"a":["é","https://example.test/public",1.0],"z":{"a":1,"b":2}}',
            CanonicalJson::encode([
                'z' => ['b' => 2, 'a' => 1],
                'a' => ['é', 'https://example.test/public', 1.0],
            ]),
        );
    }

    public function testCanonicalJsonUsesShortestRoundTripFloatsIndependentlyOfIniPrecision(): void
    {
        $previousPrecision = ini_get('serialize_precision');
        if (!\is_string($previousPrecision)) {
            self::fail('serialize_precision must be readable for this test.');
        }

        self::assertNotFalse(ini_set('serialize_precision', '3'));

        try {
            $expectedJson = '{"price":1.2345678901234567}';
            $encoded = CanonicalJson::encode(['price' => 1.2345678901234567]);

            self::assertSame($expectedJson, $encoded);
            self::assertSame(hash('sha256', $expectedJson), hash('sha256', $encoded));
            self::assertSame('3', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', $previousPrecision);
        }
    }

    public function testCanonicalJsonRestoresIniPrecisionWhenEncodingFails(): void
    {
        $previousPrecision = ini_get('serialize_precision');
        if (!\is_string($previousPrecision)) {
            self::fail('serialize_precision must be readable for this test.');
        }

        self::assertNotFalse(ini_set('serialize_precision', '3'));

        try {
            try {
                CanonicalJson::encode(['invalid_utf8' => "\xB1\x31"]);
                self::fail('Invalid UTF-8 must fail canonical JSON encoding.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_canonical_json_encoding_failed', $exception->getMessage());
            }

            self::assertSame('3', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', $previousPrecision);
        }
    }

    public function testCanonicalJsonFailsClosedWhenIniSetIsDisabled(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = sprintf(
            <<<'PHP'
require %s;

try {
    \App\Trading\Paper\MarketData\CanonicalJson::encode(['price' => 1.2345678901234567]);
} catch (\InvalidArgumentException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit(0);
}

fwrite(STDOUT, 'unexpected_success');
exit(2);
PHP,
            var_export($autoload, true),
        );
        $process = new Process([
            PHP_BINARY,
            '-d',
            'serialize_precision=3',
            '-d',
            'disable_functions=ini_set',
            '-r',
            $script,
        ]);
        $process->setTimeout(10.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame(
            'paper_canonical_json_serialize_precision_unavailable',
            $process->getOutput(),
        );
    }

    public function testCanonicalJsonPreservesNonContiguousIntegerKeyMapsAcrossTheAssociativeWire(): void
    {
        $map = [2 => 'two', 0 => 'zero'];
        $encoded = CanonicalJson::encode($map);
        $decoded = json_decode(
            $encoded,
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );

        self::assertSame('{"0":"zero","2":"two"}', $encoded);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));
        self::assertSame($encoded, CanonicalJson::encode($decoded));
    }

    public function testCanonicalJsonPreservesLeadingZeroStringKeysInSortStringOrder(): void
    {
        $map = [1 => 'one', 0 => 'zero', '01' => 'leading-zero'];
        $encoded = CanonicalJson::encode($map);
        $decoded = json_decode(
            $encoded,
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );

        self::assertSame('{"0":"zero","01":"leading-zero","1":"one"}', $encoded);
        self::assertIsArray($decoded);
        self::assertFalse(array_is_list($decoded));
        self::assertSame($encoded, CanonicalJson::encode($decoded));
    }

    /** @param array<int, string> $map */
    #[DataProvider('ambiguousIntegerKeyMapProvider')]
    public function testCanonicalJsonRejectsAmbiguousContiguousIntegerKeyMaps(array $map): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_json_ambiguous_integer_key_map');

        CanonicalJson::encode($map);
    }

    /** @return iterable<string, array{array<int, string>}> */
    public static function ambiguousIntegerKeyMapProvider(): iterable
    {
        yield 'two keys in reverse order' => [[1 => 'one', 0 => 'zero']];
        yield 'three contiguous keys in non-list order' => [[2 => 'two', 0 => 'zero', 1 => 'one']];
    }

    public function testCanonicalJsonTreatsSequentialIntegerKeysAsAListInThePhpPayloadModel(): void
    {
        $sequentialIntegerKeys = [0 => 'zero', 1 => 'one'];
        $list = ['zero', 'one'];

        self::assertTrue(array_is_list($sequentialIntegerKeys));
        self::assertSame('["zero","one"]', CanonicalJson::encode($sequentialIntegerKeys));
        self::assertSame(CanonicalJson::encode($list), CanonicalJson::encode($sequentialIntegerKeys));
    }

    #[RunInSeparateProcess]
    public function testCanonicalJsonRejectsCyclicArraysWithAStableCode(): void
    {
        $payload = [];
        $payload['self'] = &$payload;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_json_cycle_detected');

        CanonicalJson::encode($payload);
    }

    public function testCanonicalJsonRejectsArraysBeyondTheBoundedNestingDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_json_depth_exceeded');

        CanonicalJson::encode(self::nestedPayload(129));
    }

    public function testCanonicalJsonAllowsArraysAtTheBoundedNestingDepth(): void
    {
        self::assertNotSame('', CanonicalJson::encode(self::nestedPayload(128)));
    }

    public function testCanonicalJsonRejectsAggregateNodeExpansionWithAStableCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_json_nodes_exceeded');

        CanonicalJson::encode(array_fill(0, 20_000, null));
    }

    public function testCanonicalJsonRejectsAggregateStringBytesWithAStableCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_json_bytes_exceeded');

        CanonicalJson::encode(str_repeat('x', 1_048_577));
    }

    public function testCanonicalJsonRejectsAggregateMapKeysWithAStableCode(): void
    {
        $map = [];
        for ($index = 0; $index < 10_001; ++$index) {
            $map[sprintf('key_%05d', $index)] = null;
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_json_keys_exceeded');

        CanonicalJson::encode($map);
    }

    public function testCanonicalJsonAcceptsValuesAtEveryAggregateBudgetBoundary(): void
    {
        $map = [];
        for ($index = 0; $index < 10_000; ++$index) {
            $map[sprintf('key_%05d', $index)] = null;
        }

        self::assertNotSame('', CanonicalJson::encode(array_fill(0, 19_999, null)));
        self::assertNotSame('', CanonicalJson::encode(str_repeat('x', 1_048_576)));
        self::assertNotSame('', CanonicalJson::encode($map));
    }

    public function testCanonicalJsonBoundsSharedDagExpansionBeforeMemoryExhaustion(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = sprintf(
            <<<'PHP'
require %s;

$layers = [['price' => '29999.0']];
for ($level = 1; $level <= 20; ++$level) {
    $previous = &$layers[$level - 1];
    $layers[$level] = ['left' => &$previous, 'right' => &$previous];
    unset($previous);
}

try {
    \App\Trading\Paper\MarketData\CanonicalJson::encode($layers[20]);
} catch (\InvalidArgumentException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit(0);
}

fwrite(STDOUT, 'unexpected_success');
exit(2);
PHP,
            var_export($autoload, true),
        );
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=64M',
            '-d',
            'xdebug.mode=off',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-r',
            $script,
        ]);
        $process->setTimeout(20.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame('paper_canonical_json_keys_exceeded', $process->getOutput());
    }

    #[DataProvider('unsupportedCanonicalValueProvider')]
    public function testCanonicalJsonRejectsObjectsAndNonFiniteNumbers(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalJson::encode(['nested' => ['value' => $value]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsupportedCanonicalValueProvider(): iterable
    {
        yield 'object' => [new \stdClass()];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    public function testCanonicalJsonRejectsResources(): void
    {
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);

        try {
            $this->expectException(\InvalidArgumentException::class);
            CanonicalJson::encode(['resource' => $resource]);
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('allowedSymbolProvider')]
    public function testOnlyBtcAndEthUsdtSymbolsAreAccepted(string $input, string $expected): void
    {
        self::assertSame($expected, self::event(symbol: $input)->symbol);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedSymbolProvider(): iterable
    {
        yield 'BTC lowercase' => ['btcusdt', 'BTCUSDT'];
        yield 'ETH mixed case' => ['EthUsdt', 'ETHUSDT'];
    }

    #[DataProvider('rejectedSymbolProvider')]
    public function testRejectsEverySymbolOutsideTheExactAllowlist(string $symbol): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_symbol_not_allowed');

        self::event(symbol: $symbol);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedSymbolProvider(): iterable
    {
        yield 'other asset' => ['SOLUSDT'];
        yield 'other quote' => ['BTCUSD'];
        yield 'separator' => ['BTC-USDT'];
        yield 'surrounding whitespace' => [' BTCUSDT '];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidSequenceProvider')]
    public function testNonNullSequenceMustContainDecimalDigitsOnly(string $sequence): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sequence_invalid');

        self::event(sequence: $sequence);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSequenceProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['1.5'];
        yield 'whitespace' => [' 42'];
        yield 'letters' => ['seq-42'];
    }

    public function testSequenceAcceptsTheDocumentedConservativeBoundary(): void
    {
        $boundary = str_repeat('9', 128);

        self::assertSame($boundary, self::event(sequence: $boundary)->sequence);
        self::assertSame(
            '18446744073709551615',
            self::event(sequence: '18446744073709551615')->sequence,
        );
    }

    public function testSequenceRejectsOneDigitBeyondTheConservativeBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sequence_too_large');

        self::event(sequence: str_repeat('9', 129));
    }

    public function testNullSequenceUsesTheCanonicalPayloadHashAsIdentityTail(): void
    {
        $event = self::event(sequence: null);

        self::assertSame(
            hash(
                'sha256',
                '1|okx|BTCUSDT|top_of_book|2026-07-19T10:00:00.123456Z|' . $event->payloadHash,
            ),
            $event->eventId,
        );
    }

    public function testFromArrayRejectsForgedPayloadHash(): void
    {
        $data = self::event()->toArray();
        $data['payload_hash'] = str_repeat('0', 64);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_hash_mismatch');

        PaperMarketEvent::fromArray($data);
    }

    public function testFromArrayRejectsPayloadChangedBehindItsHash(): void
    {
        $data = self::event()->toArray();
        $data['payload']['ask'] = '99999.0';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_hash_mismatch');

        PaperMarketEvent::fromArray($data);
    }

    public function testFromArrayRejectsForgedEventId(): void
    {
        $data = self::event()->toArray();
        $data['event_id'] = str_repeat('0', 64);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_event_id_mismatch');

        PaperMarketEvent::fromArray($data);
    }

    #[DataProvider('unsupportedEnumValueProvider')]
    public function testFromArrayRejectsUnsupportedVenueOrChannel(string $key, string $value, string $reason): void
    {
        $data = self::event()->toArray();
        $data[$key] = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        PaperMarketEvent::fromArray($data);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function unsupportedEnumValueProvider(): iterable
    {
        yield 'unsupported venue' => ['source_venue', 'other_venue', 'paper_market_venue_unsupported'];
        yield 'unsupported channel' => ['channel', 'private_order', 'paper_market_channel_unsupported'];
    }

    public function testFromArrayRejectsMissingAndUnknownContractKeys(): void
    {
        $missing = self::event()->toArray();
        unset($missing['received_timestamp']);

        try {
            PaperMarketEvent::fromArray($missing);
            self::fail('A missing contract key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_event_shape_invalid', $exception->getMessage());
        }

        $unknown = self::event()->toArray();
        $unknown['exchange'] = 'fake';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_event_shape_invalid');

        PaperMarketEvent::fromArray($unknown);
    }

    public function testCanonicalNdjsonWireRoundTripsEveryAcceptedPayloadShape(): void
    {
        $event = self::event(
            sequence: '18446744073709551615',
            payload: [
                'levels' => [
                    ['price' => '29999.0', 'size' => '1.2'],
                    ['price' => '30001.0', 'size' => '0.8'],
                ],
                'metadata' => [
                    '01' => 'string-key-preserved',
                    'active' => true,
                    'count' => 2,
                    'ratio' => 1.0,
                    'status' => null,
                ],
            ],
        );
        $ndjsonLine = CanonicalJson::encode($event->toArray()) . "\n";
        $decoded = json_decode(
            rtrim($ndjsonLine, "\n"),
            associative: true,
            depth: 129,
            flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );

        self::assertIsArray($decoded);
        $restored = PaperMarketEvent::fromArray($decoded);

        self::assertSame($event->toArray(), $restored->toArray());
        self::assertSame($ndjsonLine, CanonicalJson::encode($restored->toArray()) . "\n");
    }

    public function testCreateRejectsAmbiguousIntegerKeyMapsBeforeReturningAnEvent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_canonical_json_ambiguous_integer_key_map');

        self::event(payload: [
            'levels' => [1 => 'one', 0 => 'zero'],
        ]);
    }

    public function testEventWireRoundTripsNonContiguousIntegerKeyMapsWithoutChangingLists(): void
    {
        $event = self::event(payload: [
            'integer_map' => [2 => 'two', 0 => 'zero'],
            'leading_zero_map' => [1 => 'one', 0 => 'zero', '01' => 'leading-zero'],
            'levels' => [['29999.0', '1'], ['30001.0', '2']],
        ]);
        $wire = CanonicalJson::encode($event->toArray());
        $decoded = json_decode(
            $wire,
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );

        self::assertIsArray($decoded);
        $restored = PaperMarketEvent::fromArray($decoded);

        self::assertNotSame($event, $restored);
        self::assertEquals($event, $restored);
        self::assertTrue(array_is_list($restored->payload['levels']));
        self::assertFalse(array_is_list($restored->payload['integer_map']));
        self::assertFalse(array_is_list($restored->payload['leading_zero_map']));
        self::assertSame($wire, CanonicalJson::encode($restored->toArray()));
    }

    #[DataProvider('invalidStrictFieldProvider')]
    public function testFromArrayRejectsInvalidStrictFieldTypes(string $key, mixed $value, string $reason): void
    {
        $data = self::event()->toArray();
        $data[$key] = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        PaperMarketEvent::fromArray($data);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidStrictFieldProvider(): iterable
    {
        yield 'schema version string' => ['schema_version', '1', 'paper_market_schema_version_unsupported'];
        yield 'future schema version' => ['schema_version', 2, 'paper_market_schema_version_unsupported'];
        yield 'event id not a string' => ['event_id', 123, 'paper_market_event_shape_invalid'];
        yield 'venue not a string' => ['source_venue', ['okx'], 'paper_market_event_shape_invalid'];
        yield 'symbol not a string' => ['symbol', 123, 'paper_market_event_shape_invalid'];
        yield 'channel not a string' => ['channel', true, 'paper_market_event_shape_invalid'];
        yield 'exchange timestamp lacks microseconds' => ['exchange_timestamp', '2026-07-19T10:00:00Z', 'paper_market_timestamp_invalid'];
        yield 'received timestamp has offset' => ['received_timestamp', '2026-07-19T12:00:00.223456+02:00', 'paper_market_timestamp_invalid'];
        yield 'sequence integer' => ['sequence', 42, 'paper_market_sequence_invalid'];
        yield 'payload scalar' => ['payload', 'public-data', 'paper_market_event_shape_invalid'];
        yield 'payload hash not a string' => ['payload_hash', false, 'paper_market_event_shape_invalid'];
    }

    #[DataProvider('timestampFieldProvider')]
    public function testFromArrayNormalizesNulTimestampFailures(string $field): void
    {
        $data = self::event()->toArray();
        $data[$field] .= "\0synthetic";

        try {
            PaperMarketEvent::fromArray($data);
            self::fail('A NUL-containing timestamp must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_timestamp_invalid', $exception->getMessage());
        } catch (\ValueError $exception) {
            self::fail(sprintf('ValueError escaped the event boundary: %s', $exception->getMessage()));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timestampFieldProvider(): iterable
    {
        yield 'exchange timestamp' => ['exchange_timestamp'];
        yield 'received timestamp' => ['received_timestamp'];
    }

    public function testCreateRejectsSensitivePayloadKeysAtAnyDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        self::event(payload: ['book' => [['authorization' => 'must-not-be-stored']]]);
    }

    public function testCreateRejectsCompoundSensitivePayloadKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        self::event(payload: ['HYPERLIQUID_PRIVATE_KEY' => 'synthetic-secret-sentinel']);
    }

    public function testCreateRejectsRawJsonPayloadStringsWithEscapedSensitiveKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        self::event(payload: ['raw' => '{"api\u005fkey":"synthetic-secret-sentinel"}']);
    }

    public function testCreateRejectsRawFormPayloadStringsWithEncodedSensitiveKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        self::event(payload: ['raw' => 'api%5Fkey=synthetic-secret-sentinel']);
    }

    public function testCreateRejectsWhitespacePrefixedPhpSerializedPayloadStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        self::event(payload: [
            'raw' => " \n\t" . serialize(['api_key' => 'synthetic-secret-sentinel']),
        ]);
    }

    public function testCreateRejectsOversizedPayloadKeysBeforeNormalization(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_key_too_large');

        self::event(payload: [str_repeat('k', 1_048_577) => 'public-value']);
    }

    public function testCreateRejectsPayloadsBeyondTheAggregateByteBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_payload_bytes_exceeded');

        self::event(payload: [
            'levels' => array_fill(0, 1_025, str_repeat('x', 1_024)),
        ]);
    }

    /** @param array<array-key, mixed> $payload */
    #[DataProvider('unsafePayloadProvider')]
    public function testFromArrayRejectsUnsafePayloadsWithoutDisclosingInput(array $payload): void
    {
        $data = self::event()->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        try {
            PaperMarketEvent::fromArray($data);
            self::fail('An unsafe payload must be rejected by fromArray().');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString('synthetic-secret-sentinel', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function unsafePayloadProvider(): iterable
    {
        yield 'compound sensitive key' => [[
            'HYPERLIQUID_PRIVATE_KEY' => 'synthetic-secret-sentinel',
        ]];
        yield 'escaped raw JSON' => [[
            'raw' => '{"api\u005fkey":"synthetic-secret-sentinel"}',
        ]];
        yield 'encoded raw form data' => [[
            'raw' => 'api%5Fkey=synthetic-secret-sentinel',
        ]];
        yield 'whitespace-prefixed PHP serialization' => [[
            'raw' => " \n\t" . serialize(['api_key' => 'synthetic-secret-sentinel']),
        ]];
    }

    /**
     * @param array<mixed> $payload
     */
    private static function event(
        string $symbol = 'btcusdt',
        ?string $sequence = '42',
        array $payload = ['ask' => '30001.0', 'bid' => '29999.0'],
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: $symbol,
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
            sequence: $sequence,
            payload: $payload,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function nestedPayload(int $levels): array
    {
        $payload = ['price' => '29999.0'];
        for ($level = 0; $level < $levels; ++$level) {
            $payload = ['nested' => $payload];
        }

        return $payload;
    }
}
