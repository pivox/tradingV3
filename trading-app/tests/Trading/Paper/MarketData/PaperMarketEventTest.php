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

    #[DataProvider('secondPrecisionOffsetInstantProvider')]
    public function testSecondPrecisionUtcOffsetsNormalizeToExactUtcMicroseconds(
        string $offsetTimestamp,
        string $utcTimestamp,
    ): void {
        $event = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable($offsetTimestamp),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T12:00:00.000000Z'),
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );

        self::assertSame($utcTimestamp, $event->toArray()['exchange_timestamp']);
    }

    #[DataProvider('secondPrecisionOffsetInstantProvider')]
    public function testEquivalentSecondPrecisionOffsetInstantsDeriveTheSameEventIdentity(
        string $offsetTimestamp,
        string $utcTimestamp,
    ): void {
        $eventWithOffset = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable($offsetTimestamp),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T12:00:00.000000Z'),
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
        $eventInUtc = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable($utcTimestamp),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T12:00:00.000000Z'),
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );

        self::assertSame($eventInUtc->eventId, $eventWithOffset->eventId);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secondPrecisionOffsetInstantProvider(): iterable
    {
        yield 'positive offset' => [
            '2026-07-19T10:00:00.123456+00:09:21',
            '2026-07-19T09:50:39.123456Z',
        ];
        yield 'negative offset' => [
            '2026-07-19T10:00:00.654321-00:44:30',
            '2026-07-19T10:44:30.654321Z',
        ];
    }

    public function testInitializedTimestampSubclassCannotLieAboutCapturedInstantOrEventIdentity(): void
    {
        $instant = '2026-07-19T10:00:00.123456+00:09:21';
        $lyingTimestamp = new class($instant) extends \DateTimeImmutable {
            public function format(string $format): string
            {
                return '946684800.000000';
            }
        };
        $baseEvent = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable($instant),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T12:00:00.000000Z'),
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
        $subclassEvent = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: $lyingTimestamp,
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T12:00:00.000000Z'),
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
        $baseIdentity = [
            'exchange_timestamp' => $baseEvent->toArray()['exchange_timestamp'],
            'event_id' => $baseEvent->eventId,
        ];

        self::assertSame('2026-07-19T09:50:39.123456Z', $baseIdentity['exchange_timestamp']);
        self::assertSame($baseIdentity, [
            'exchange_timestamp' => $subclassEvent->toArray()['exchange_timestamp'],
            'event_id' => $subclassEvent->eventId,
        ]);
    }

    public function testCreateOwnsBaseDateTimeImmutableTimestamps(): void
    {
        $formatState = (object) ['poisoned' => false];
        $exchangeTimestamp = new class('2026-07-19T10:00:00.123456Z', $formatState) extends \DateTimeImmutable {
            public function __construct(string $datetime, private readonly object $formatState)
            {
                parent::__construct($datetime);
            }

            public function format(string $format): string
            {
                return $this->formatState->poisoned
                    ? '2000-01-01T00:00:00.000000Z'
                    : parent::format($format);
            }
        };
        $receivedTimestamp = new class('2026-07-19T10:00:00.223456Z', $formatState) extends \DateTimeImmutable {
            public function __construct(string $datetime, private readonly object $formatState)
            {
                parent::__construct($datetime);
            }

            public function format(string $format): string
            {
                return $this->formatState->poisoned
                    ? '2000-01-01T00:00:00.000000Z'
                    : parent::format($format);
            }
        };

        $event = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: $exchangeTimestamp,
            receivedTimestamp: $receivedTimestamp,
            sequence: '42',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
        $serialized = $event->toArray();

        self::assertSame(\DateTimeImmutable::class, $event->exchangeTimestamp::class);
        self::assertSame(\DateTimeImmutable::class, $event->receivedTimestamp::class);

        $formatState->poisoned = true;

        self::assertSame($serialized, $event->toArray());
    }

    #[RunInSeparateProcess]
    public function testHostileUninitializedTimestampDoesNotLeakThroughAFullTraceChain(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $sentinel = 'synthetic-hostile-timestamp-sentinel';
        $hostileTimestamp = new class($sentinel) extends \DateTimeImmutable {
            public function __construct(public readonly string $sentinel)
            {
            }
        };

        try {
            PaperMarketEvent::create(
                venue: PaperMarketDataVenue::OKX,
                symbol: 'BTCUSDT',
                channel: PaperMarketDataChannel::TOP_OF_BOOK,
                exchangeTimestamp: $hostileTimestamp,
                receivedTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
                sequence: '42',
                payload: ['ask' => '30001.0', 'bid' => '29999.0'],
            );
            self::fail('An uninitialized DateTimeImmutable subclass must be rejected.');
        } catch (\Throwable $exception) {
            self::assertStringNotContainsString(
                $sentinel,
                self::renderExceptionTraceChain($exception),
            );
            self::assertInstanceOf(\InvalidArgumentException::class, $exception);
            self::assertSame('paper_market_timestamp_invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testRejectedBoundaryInputsAreHiddenFromFullExceptionTraceChains(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $script = sprintf(
            <<<'PHP'
require %s;

use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\MarketData\PaperMarketEventRedactor;

$sentinel = implode('', ['synthetic', '-trace-', 'sentinel']);
$payload = ['raw' => '{"public\\q_' . $sentinel . '":"price"}'];
$wireData = PaperMarketEvent::create(
    PaperMarketDataVenue::OKX,
    'BTCUSDT',
    PaperMarketDataChannel::TOP_OF_BOOK,
    new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
    new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
    '42',
    ['price' => '29999.0'],
)->toArray();
$wireData['payload'] = $payload;
$wireData['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

$operations = [
    static fn () => PaperMarketEventRedactor::assertSafe($payload),
    static fn () => PaperMarketEvent::create(
        PaperMarketDataVenue::OKX,
        'BTCUSDT',
        PaperMarketDataChannel::TOP_OF_BOOK,
        new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
        new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
        '42',
        $payload,
    ),
    static fn () => PaperMarketEvent::fromArray($wireData),
];

foreach ($operations as $index => $operation) {
    try {
        $operation();
        exit(10 + $index);
    } catch (\InvalidArgumentException $exception) {
        $current = $exception;
        do {
            $rendered = print_r([
                'message' => $current->getMessage(),
                'trace' => $current->getTrace(),
            ], true);
            if (str_contains($rendered, $sentinel)) {
                exit(20 + $index);
            }

            $current = $current->getPrevious();
        } while ($current !== null);
    }
}

$sensitiveKey = 'api_key_' . $sentinel;
$payload = [$sensitiveKey => $sentinel];
$wireData['payload'] = $payload;
$wireData['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));
$operations = [
    static fn () => PaperMarketEventRedactor::assertSafe($payload),
    static fn () => PaperMarketEvent::create(
        PaperMarketDataVenue::OKX,
        'BTCUSDT',
        PaperMarketDataChannel::TOP_OF_BOOK,
        new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
        new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
        '42',
        $payload,
    ),
    static fn () => PaperMarketEvent::fromArray($wireData),
];

foreach ($operations as $index => $operation) {
    try {
        $operation();
        exit(40 + $index);
    } catch (\InvalidArgumentException $exception) {
        $current = $exception;
        do {
            $rendered = print_r([
                'message' => $current->getMessage(),
                'trace' => $current->getTrace(),
            ], true);
            if (str_contains($rendered, $sentinel)) {
                exit(50 + $index);
            }

            $current = $current->getPrevious();
        } while ($current !== null);
    }
}

$credentialJson = json_encode(['api_key' => $sentinel], JSON_THROW_ON_ERROR);
$credentialBase64 = base64_encode($credentialJson);
$foldedCredentialBase64 = substr($credentialBase64, 0, 4)
    . "\r\n "
    . substr($credentialBase64, 4);
$invalidUtf8CredentialBase64 = base64_encode("\xFF" . $credentialJson);
$overDepthJson = str_repeat('[', 130)
    . json_encode($sentinel, JSON_THROW_ON_ERROR)
    . str_repeat(']', 130);
$encodedApiKeyHint = '';
foreach (str_split(bin2hex('api_key_hint'), 2) as $hexByte) {
    $encodedApiKeyHint .= chr(37) . $hexByte;
}

$additionalPayloads = [
    [
        ['header' => 'Basic ' . base64_encode('public-user:' . $sentinel)],
        ['Basic ' . base64_encode('public-user:' . $sentinel)],
    ],
    [
        ['raw' => '-----BEGIN PRIVATE KEY-----' . $sentinel],
        [$sentinel],
    ],
    [
        ['raw' => 'api_key=' . $sentinel],
        [$sentinel],
    ],
    [
        ['raw' => 'prefix {api\\u005fkey:"' . $sentinel . '"} suffix'],
        [$sentinel],
    ],
    [
        ['raw' => serialize(['api_key' => $sentinel])],
        [$sentinel],
    ],
    [
        ['raw' => $credentialBase64],
        [$credentialBase64],
    ],
    [
        ['raw' => $overDepthJson],
        [$sentinel, $overDepthJson],
    ],
    [
        ['raw' => $foldedCredentialBase64],
        [$foldedCredentialBase64],
    ],
    [
        ['raw' => 'prefix|' . $foldedCredentialBase64 . '|suffix'],
        [$foldedCredentialBase64],
    ],
    [
        ['raw' => $invalidUtf8CredentialBase64],
        [$invalidUtf8CredentialBase64],
    ],
    [
        ['raw' => 'public&api+key=' . $sentinel],
        [$sentinel],
    ],
    [
        ['authorization_status' => 'not_applicable'],
        ['authorization_status'],
    ],
    [
        ['raw' => $encodedApiKeyHint . '=not_present'],
        [$encodedApiKeyHint, 'api_key_hint'],
    ],
];

foreach ($additionalPayloads as $fixtureIndex => [$payload, $prohibitedFragments]) {
    $wireData['payload'] = $payload;
    $wireData['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));
    $operations = [
        static fn () => PaperMarketEventRedactor::assertSafe($payload),
        static fn () => PaperMarketEvent::create(
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
            new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
            '42',
            $payload,
        ),
        static fn () => PaperMarketEvent::fromArray($wireData),
    ];

    foreach ($operations as $operationIndex => $operation) {
        try {
            $operation();
            exit(70 + $fixtureIndex * 3 + $operationIndex);
        } catch (\InvalidArgumentException $exception) {
            $current = $exception;
            do {
                $rendered = print_r([
                    'message' => $current->getMessage(),
                    'trace' => $current->getTrace(),
                ], true);
                foreach ($prohibitedFragments as $prohibitedFragment) {
                    if (str_contains($rendered, $prohibitedFragment)) {
                        exit(100 + $fixtureIndex * 3 + $operationIndex);
                    }
                }

                $current = $current->getPrevious();
            } while ($current !== null);
        }
    }
}

$resource = fopen('php://memory', 'rb');
if ($resource === false) {
    exit(130);
}

$payload = ['note' => $sentinel, 'unsupported' => $resource];
$wireData['payload'] = $payload;
$operations = [
    static fn () => PaperMarketEvent::create(
        PaperMarketDataVenue::OKX,
        'BTCUSDT',
        PaperMarketDataChannel::TOP_OF_BOOK,
        new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
        new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
        '42',
        $payload,
    ),
    static fn () => PaperMarketEvent::fromArray($wireData),
];

foreach ($operations as $index => $operation) {
    try {
        $operation();
        exit(131 + $index);
    } catch (\InvalidArgumentException $exception) {
        $current = $exception;
        do {
            $rendered = print_r([
                'message' => $current->getMessage(),
                'trace' => $current->getTrace(),
            ], true);
            if (str_contains($rendered, $sentinel)) {
                exit(133 + $index);
            }

            $current = $current->getPrevious();
        } while ($current !== null);
    }
}
fclose($resource);

$invalidUtf8 = "\xFF" . $sentinel;
try {
    CanonicalJson::encode(['raw' => $invalidUtf8]);
    exit(150);
} catch (\InvalidArgumentException $exception) {
    $current = $exception;
    do {
        $rendered = print_r([
            'message' => $current->getMessage(),
            'trace' => $current->getTrace(),
        ], true);
        if (str_contains($rendered, $sentinel)) {
            exit(151);
        }

        $current = $current->getPrevious();
    } while ($current !== null);
}

try {
    PaperMarketEvent::fromArray(['unexpected' => $sentinel]);
    exit(60);
} catch (\InvalidArgumentException $exception) {
    $current = $exception;
    do {
        $rendered = print_r([
            'message' => $current->getMessage(),
            'trace' => $current->getTrace(),
        ], true);
        if (str_contains($rendered, $sentinel)) {
            exit(61);
        }

        $current = $current->getPrevious();
    } while ($current !== null);
}

fwrite(STDOUT, 'trace_arguments_redacted');
PHP,
            var_export($autoload, true),
        );
        $process = new Process([
            PHP_BINARY,
            '-d',
            'zend.exception_ignore_args=0',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-r',
            $script,
        ]);
        $process->setTimeout(20.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), 'Trace secrecy subprocess failed.');
        self::assertSame('', $process->getErrorOutput(), 'Trace secrecy subprocess wrote to stderr.');
        self::assertSame('trace_arguments_redacted', $process->getOutput());
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

    public function testCanonicalJsonEncodingFailureDoesNotRetainRawJsonException(): void
    {
        try {
            CanonicalJson::encode([
                'raw' => "\xFFsynthetic-canonical-trace-sentinel",
            ]);
            self::fail('Invalid UTF-8 must fail canonical JSON encoding.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_canonical_json_encoding_failed', $exception->getMessage());
            self::assertNull($exception->getPrevious());
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

    #[RunInSeparateProcess]
    public function testNulTimestampDoesNotLeakItsRawValueThroughAFullTraceChain(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $sentinel = 'synthetic-timestamp-trace-sentinel';
        $data = self::event()->toArray();
        $data['exchange_timestamp'] .= "\0" . $sentinel;

        try {
            PaperMarketEvent::fromArray($data);
            self::fail('A NUL-containing timestamp must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_timestamp_invalid', $exception->getMessage());
            self::assertStringNotContainsString(
                $sentinel,
                self::renderExceptionTraceChain($exception),
            );
            self::assertNull($exception->getPrevious());
        }
    }

    #[RunInSeparateProcess]
    public function testCreateDoesNotLeakInvalidRawSymbolOrSequenceThroughFullTraces(): void
    {
        ini_set('zend.exception_ignore_args', '0');
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
        $sentinel = 'synthetic-create-boundary-trace-sentinel';
        $operations = [
            'paper_market_symbol_not_allowed' => static fn () => PaperMarketEvent::create(
                PaperMarketDataVenue::OKX,
                'SOLUSDT-' . $sentinel,
                PaperMarketDataChannel::TOP_OF_BOOK,
                new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
                new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
                '42',
                ['price' => '29999.0'],
            ),
            'paper_market_sequence_invalid' => static fn () => PaperMarketEvent::create(
                PaperMarketDataVenue::OKX,
                'BTCUSDT',
                PaperMarketDataChannel::TOP_OF_BOOK,
                new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
                new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
                'invalid-' . $sentinel,
                ['price' => '29999.0'],
            ),
        ];

        foreach ($operations as $expectedCode => $operation) {
            try {
                $operation();
                self::fail(sprintf('%s input must be rejected.', $expectedCode));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($expectedCode, $exception->getMessage());
                self::assertStringNotContainsString(
                    $sentinel,
                    self::renderExceptionTraceChain($exception),
                );
            }
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

    #[DataProvider('jsonUnicodeEscapedDirectMapKeyProvider')]
    public function testCreateRejectsJsonUnicodeEscapedDirectMapKey(string $key): void
    {
        $sentinel = 'synthetic-redaction-sentinel';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: [
                $key => $sentinel,
            ]),
            [$key, $sentinel],
        );
    }

    #[DataProvider('jsonUnicodeEscapedDirectMapKeyProvider')]
    public function testFromArrayRejectsJsonUnicodeEscapedDirectMapKeyOnStrictWireInput(
        string $key,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $payload = [$key => $sentinel];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$key, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function jsonUnicodeEscapedDirectMapKeyProvider(): iterable
    {
        yield 'JSON Unicode escape' => ['api' . str_repeat('\\', 1) . 'u005fkey'];
        yield 'double-escaped JSON Unicode escape' => ['api' . str_repeat('\\', 2) . 'u005fkey'];
        yield 'quadruply escaped JSON Unicode escape' => ['api' . str_repeat('\\', 4) . 'u005fkey'];
    }

    public function testCreateRejectsBase64EncodedDirectMapKey(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $key = base64_encode('api_key');

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: [
                $key => $sentinel,
            ]),
            [$key, $sentinel],
        );
    }

    public function testFromArrayRejectsBase64EncodedDirectMapKeyOnStrictWireInput(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $key = base64_encode('api_key');
        $payload = [$key => $sentinel];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$key, $sentinel],
        );
    }

    #[DataProvider('composedEncodedDirectMapKeyProvider')]
    public function testCreateRejectsComposedEncodedDirectMapKey(string $key): void
    {
        $sentinel = 'synthetic-redaction-sentinel';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: [$key => $sentinel]),
            [$key, $sentinel],
        );
    }

    #[DataProvider('composedEncodedDirectMapKeyProvider')]
    public function testFromArrayRejectsComposedEncodedDirectMapKeyOnStrictWireInput(
        string $key,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $payload = [$key => $sentinel];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$key, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function composedEncodedDirectMapKeyProvider(): iterable
    {
        yield 'percent-encoded Base64 key' => [rawurlencode(base64_encode('api_key'))];
        yield 'percent-encoded JSON Unicode escape' => [rawurlencode('api\\u005fkey')];
    }

    #[DataProvider('prefixedComposedDirectMapKeyProvider')]
    public function testCreateRejectsPrefixedComposedDirectMapKey(
        #[\SensitiveParameter] string $prefix,
        #[\SensitiveParameter] string $composedKey,
    ): void {
        $sentinel = 'synthetic-prefixed-map-key-sentinel';
        $key = $prefix . $composedKey;

        self::assertSensitiveRejectionWithFullTraceWithoutDisclosure(
            static fn () => self::event(payload: [$key => $sentinel]),
            [$key, $sentinel],
        );
    }

    #[DataProvider('prefixedComposedDirectMapKeyProvider')]
    public function testFromArrayRejectsPrefixedComposedDirectMapKey(
        #[\SensitiveParameter] string $prefix,
        #[\SensitiveParameter] string $composedKey,
    ): void {
        $sentinel = 'synthetic-prefixed-map-key-sentinel';
        $key = $prefix . $composedKey;
        $payload = [$key => $sentinel];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithFullTraceWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$key, $sentinel],
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function prefixedComposedDirectMapKeyProvider(): iterable
    {
        $composedKeys = [
            'percent-composed JSON Unicode key' => '%22api%5Cu005fkey%22',
            'Base64 key' => 'YXBpX2tleQ==',
            'Base64 key with non-ASCII suffix' => base64_encode("api_key\u{1F4A5}"),
            'Base64 key with non-ASCII separator' => base64_encode("api\u{1F4A5}key"),
            'Base64 key with invalid UTF-8 prefix' => base64_encode("\xFFapi_key"),
            'Base64 key with invalid UTF-8 suffix' => base64_encode("api_key\xFF"),
            'unpadded Base64 key with invalid UTF-8 prefix' => rtrim(base64_encode("\xFFapi_key"), '='),
            'folded Base64 key' => 'YX Bp X2 tl eQ==',
            'Base64 key in malformed quoted trailing escape' => '"YXBpX2tleQ==\\q"',
            'Base64 key in malformed quoted leading escape' => '"\\qYXBpX2tleQ=="',
            'Base64 key in unterminated quoted escape' => \chr(34) . 'YXBpX2tleQ==' . \chr(92),
        ];

        foreach ([
            'dot' => '.',
            'bang' => '!',
            'at sign' => '@',
            'colon' => ':',
            'pipe' => '|',
            'double quote' => \chr(34),
            'encoded double quote' => '%22',
            'double then single quote' => \chr(34) . \chr(39),
            'single then double quote' => \chr(39) . \chr(34),
            'token then double quote' => 'x' . \chr(34),
            'double quote then token' => \chr(34) . 'x',
            'token then encoded double quote' => 'x%22',
            'encoded double quote then token' => '%22x',
            'encoded bang' => '%21',
            'encoded NUL' => '%00',
            'repeated punctuation' => '!!',
            'token then punctuation' => 'x!',
            'punctuation then token' => '!x',
            'token then encoded punctuation' => 'x%21',
            'encoded punctuation then token' => '%21x',
            'mixed encoded bytes' => '%21%00',
            'repeated mixed prefix' => 'x_%21-.',
        ] as $prefixLabel => $prefix) {
            foreach ($composedKeys as $keyLabel => $composedKey) {
                yield $prefixLabel . ', ' . $keyLabel => [$prefix, $composedKey];
            }
        }
    }

    #[DataProvider('ordinaryComposedDirectMapKeyProvider')]
    public function testCreateAndFromArrayAllowOrdinaryComposedDirectMapKeys(string $key): void
    {
        $event = self::event(payload: [$key => '29999.0']);

        self::assertSame(
            $event->toArray(),
            PaperMarketEvent::fromArray($event->toArray())->toArray(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function ordinaryComposedDirectMapKeyProvider(): iterable
    {
        yield 'Base64 public key with invalid UTF-8 prefix' => [
            '.' . base64_encode("\xFFprice"),
        ];
        yield 'folded Base64 public key' => ['.cH Jp Y2 U='];
        yield 'Base64 public key in malformed quoted trailing escape' => [
            '."cHJpY2U=\\q"',
        ];
        yield 'Base64 public key in malformed quoted leading escape' => [
            '."\\qcHJpY2U="',
        ];
        yield 'Base64 public key in unterminated quoted escape' => [
            '.' . \chr(34) . 'cHJpY2U=' . \chr(92),
        ];
        yield 'embedded JSON Unicode public key' => ['.!"pr\\u0069ce"'];
    }

    #[DataProvider('composedSensitiveFormKeyProvider')]
    public function testCreateRejectsComposedSensitiveFormKeysWithoutDisclosure(string $key): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = $key . '=' . $sentinel;

        self::assertSensitiveFormRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            $raw,
            $sentinel,
        );
    }

    #[DataProvider('composedSensitiveFormKeyProvider')]
    public function testFromArrayRejectsComposedSensitiveFormKeysWithoutDisclosure(
        string $key,
    ): void {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = $key . '=' . $sentinel;
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveFormRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            $raw,
            $sentinel,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function composedSensitiveFormKeyProvider(): iterable
    {
        yield 'JSON Unicode escape' => ['api\\u005fkey'];
        yield 'percent-encoded JSON Unicode escape' => ['api%5Cu005fkey'];
        yield 'JSON-wrapped Base64 key' => ['"YXBpX2tleQ=="'];
        yield 'percent-encoded JSON-wrapped Base64 key' => ['%22YXBpX2tleQ%3D%3D'];

        $jsonUnicodeKey = '"api\\u005fkey"';
        $jsonPaddedBase64Key = '"YXBpX2tleQ\\u003d\\u003d"';
        foreach ([
            'space' => ' ',
            'horizontal tab' => "\t",
            'line feed' => "\n",
            'vertical tab' => "\v",
            'form feed' => "\f",
            'carriage return' => "\r",
            'form space' => '+',
            'percent-encoded form space' => '%2B',
        ] as $label => $whitespace) {
            yield 'JSON-wrapped Unicode escape before assignment with ' . $label => [
                $jsonUnicodeKey . $whitespace,
            ];
            yield 'percent-composed JSON-wrapped Unicode escape before assignment with ' . $label => [
                rawurlencode($jsonUnicodeKey) . $whitespace,
            ];
            yield 'JSON-wrapped Base64 padding before assignment with ' . $label => [
                $jsonPaddedBase64Key . $whitespace,
            ];
            yield 'percent-composed JSON-wrapped Base64 padding before assignment with ' . $label => [
                rawurlencode($jsonPaddedBase64Key) . $whitespace,
            ];
        }

        $singleQuote = "'";
        $singleQuotedUnicodeKey = $singleQuote . 'api\\u005fkey' . $singleQuote;
        $singleQuotedBase64Key = $singleQuote . 'YXBpX2tleQ==' . $singleQuote;
        yield 'single-quoted JSON Unicode escape' => [$singleQuotedUnicodeKey];
        yield 'unmatched opening single-quoted JSON Unicode escape' => [
            $singleQuote . 'api\\u005fkey',
        ];
        yield 'unmatched closing single-quoted JSON Unicode escape' => [
            'api\\u005fkey' . $singleQuote,
        ];
        yield 'single-quoted Base64 key with padding' => [$singleQuotedBase64Key];
        yield 'unmatched opening single-quoted Base64 key with padding' => [
            $singleQuote . 'YXBpX2tleQ==',
        ];
        yield 'unmatched closing single-quoted Base64 key with padding' => [
            'YXBpX2tleQ==' . $singleQuote,
        ];
        yield 'percent-composed single-quoted JSON Unicode escape' => [
            rawurlencode($singleQuotedUnicodeKey),
        ];
        yield 'percent-composed single-quoted Base64 key with padding' => [
            rawurlencode($singleQuotedBase64Key),
        ];
        yield 'single-quoted JSON Unicode escape before assignment with form space' => [
            $singleQuotedUnicodeKey . '+',
        ];
        yield 'single-quoted Base64 key before assignment with form space' => [
            $singleQuotedBase64Key . '+',
        ];
        yield 'percent-composed single-quoted JSON Unicode escape before assignment with form space' => [
            rawurlencode($singleQuotedUnicodeKey) . '+',
        ];
        yield 'percent-composed single-quoted Base64 key before assignment with form space' => [
            rawurlencode($singleQuotedBase64Key) . '+',
        ];
    }

    #[DataProvider('laterFormPairLeadingPrefixAndComposedKeyProvider')]
    public function testCreateRejectsLeadingFormSpaceBeforeComposedSensitiveKeyInLaterPair(
        string $prefix,
        string $composedKey,
    ): void {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'symbol=BTCUSDT&' . $prefix . $composedKey . '=' . $sentinel;

        self::assertSensitiveFormRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            $raw,
            $sentinel,
        );
    }

    #[DataProvider('laterFormPairLeadingPrefixAndComposedKeyProvider')]
    public function testFromArrayRejectsLeadingFormSpaceBeforeComposedSensitiveKeyInLaterPair(
        string $prefix,
        string $composedKey,
    ): void {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'symbol=BTCUSDT&' . $prefix . $composedKey . '=' . $sentinel;
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveFormRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            $raw,
            $sentinel,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function laterFormPairLeadingPrefixAndComposedKeyProvider(): iterable
    {
        $composedKeys = [
            'percent-composed JSON Unicode key' => '%22api%5Cu005fkey%22',
            'percent-composed Base64 key' => '%22YXBpX2tleQ%3D%3D%22',
        ];

        foreach ([
            'space' => ' ',
            'horizontal tab' => "\t",
            'line feed' => "\n",
            'vertical tab' => "\v",
            'form feed' => "\f",
            'carriage return' => "\r",
            'form space' => '+',
            'percent-encoded form space' => '%2B',
        ] as $prefixLabel => $prefix) {
            foreach ($composedKeys as $keyLabel => $composedKey) {
                yield $prefixLabel . ', ' . $keyLabel => [$prefix, $composedKey];
            }
        }
    }

    #[DataProvider('prefixedComposedSensitiveFormKeyProvider')]
    public function testCreateRejectsPrefixedComposedSensitiveKeyInLaterFormPair(
        #[\SensitiveParameter] string $prefix,
        #[\SensitiveParameter] string $composedKey,
    ): void {
        $sentinel = 'synthetic-prefixed-form-key-sentinel';
        $raw = 'symbol=BTCUSDT&' . $prefix . $composedKey . '=' . $sentinel;

        self::assertSensitiveFormRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            $raw,
            $sentinel,
        );
    }

    #[DataProvider('prefixedComposedSensitiveFormKeyProvider')]
    public function testFromArrayRejectsPrefixedComposedSensitiveKeyInLaterFormPair(
        #[\SensitiveParameter] string $prefix,
        #[\SensitiveParameter] string $composedKey,
    ): void {
        $sentinel = 'synthetic-prefixed-form-key-sentinel';
        $raw = 'symbol=BTCUSDT&' . $prefix . $composedKey . '=' . $sentinel;
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveFormRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            $raw,
            $sentinel,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function prefixedComposedSensitiveFormKeyProvider(): iterable
    {
        $composedKeys = [
            'percent-composed JSON Unicode key' => '%22api%5Cu005fkey%22',
            'percent-composed Base64 key' => '%22YXBpX2tleQ%3D%3D%22',
        ];

        foreach (self::composedKeyPrefixProvider() as $prefixLabel => [$prefix]) {
            foreach ($composedKeys as $keyLabel => $composedKey) {
                yield $prefixLabel . ', ' . $keyLabel => [$prefix, $composedKey];
            }
        }
    }

    #[DataProvider('composedKeyPrefixProvider')]
    public function testCreateAndFromArrayAllowOrdinaryKeysAndRelationsWithCompositionPrefixes(
        string $prefix,
    ): void {
        $event = self::event(payload: [
            $prefix . 'price' => '29999.0',
            'raw' => 'symbol=BTCUSDT&' . $prefix . 'price=29999.0',
        ]);

        self::assertSame(
            $event->toArray(),
            PaperMarketEvent::fromArray($event->toArray())->toArray(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function composedKeyPrefixProvider(): iterable
    {
        yield 'ASCII lowercase letter' => ['x'];
        yield 'ASCII uppercase letter' => ['Z'];
        yield 'ASCII digit' => ['0'];
        yield 'underscore' => ['_'];
        yield 'hyphen' => ['-'];
        yield 'dot' => ['.'];
        yield 'bang' => ['!'];
        yield 'at sign' => ['@'];
        yield 'colon' => [':'];
        yield 'slash' => ['/'];
        yield 'backslash' => ['\\'];
        yield 'pipe' => ['|'];
        yield 'comma' => [','];
        yield 'semicolon' => [';'];
        yield 'open parenthesis' => ['('];
        yield 'close parenthesis' => [')'];
        yield 'open bracket' => ['['];
        yield 'close bracket' => [']'];
        yield 'open brace' => ['{'];
        yield 'close brace' => ['}'];
        yield 'question mark' => ['?'];
        yield 'hash' => ['#'];
        yield 'dollar' => ['$'];
        yield 'caret' => ['^'];
        yield 'asterisk' => ['*'];
        yield 'double quote' => [\chr(34)];
        yield 'single quote' => [\chr(39)];
        yield 'encoded double quote' => ['%22'];
        yield 'encoded single quote' => ['%27'];
        yield 'repeated double quote' => [\chr(34) . \chr(34)];
        yield 'repeated single quote' => [\chr(39) . \chr(39)];
        yield 'double then single quote' => [\chr(34) . \chr(39)];
        yield 'single then double quote' => [\chr(39) . \chr(34)];
        yield 'token then double quote' => ['x' . \chr(34)];
        yield 'double quote then token' => [\chr(34) . 'x'];
        yield 'token then encoded double quote' => ['x%22'];
        yield 'encoded double quote then token' => ['%22x'];
        yield 'equals' => ['='];
        yield 'encoded equals' => ['%3D'];
        yield 'ampersand' => ['&'];
        yield 'encoded ampersand' => ['%26'];
        yield 'percent' => ['%'];
        yield 'malformed percent' => ['%2'];
        yield 'backtick' => [\chr(96)];
        yield 'space' => [' '];
        yield 'horizontal tab' => ["\t"];
        yield 'line feed' => ["\n"];
        yield 'vertical tab' => ["\v"];
        yield 'form feed' => ["\f"];
        yield 'carriage return' => ["\r"];
        yield 'form plus' => ['+'];
        yield 'encoded bang' => ['%21'];
        yield 'encoded NUL' => ['%00'];
        yield 'encoded dot' => ['%2E'];
        yield 'encoded space' => ['%20'];
        yield 'encoded plus' => ['%2B'];
        yield 'encoded tab' => ['%09'];
        yield 'encoded slash' => ['%2F'];
        yield 'encoded backslash' => ['%5C'];
        yield 'repeated token' => ['xx'];
        yield 'repeated punctuation' => ['!!'];
        yield 'token then punctuation' => ['x!'];
        yield 'punctuation then token' => ['!x'];
        yield 'token then encoded punctuation' => ['x%21'];
        yield 'encoded punctuation then token' => ['%21x'];
        yield 'mixed underscore and hyphen' => ['_-'];
        yield 'mixed dot and hyphen' => ['.-'];
        yield 'mixed encoded bytes' => ['%21%00'];
        yield 'repeated mixed prefix' => ['x_%21-.'];
        yield 'form plus then token' => ['+x'];
        yield 'encoded space then token' => ['%20x'];
    }

    #[DataProvider('sensitiveStructuralStringProvider')]
    public function testCreateRejectsSensitiveStructuralKeysAcrossQuoteEncodings(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            [$raw, 'synthetic-redaction-sentinel'],
        );
    }

    #[DataProvider('sensitiveStructuralStringProvider')]
    public function testFromArrayRejectsSensitiveStructuralKeysAcrossQuoteEncodings(string $raw): void
    {
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$raw, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveStructuralStringProvider(): iterable
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $slash = '\\';

        yield 'escaped structural quotes and escaped quote inside key' => [
            'prefix {'
            . $slash . '"api' . str_repeat($slash, 3) . '"key' . $slash . '":'
            . $slash . '"' . $sentinel . $slash . '"} suffix',
        ];
        yield 'single-quoted Unicode-escaped sensitive member' => [
            "prefix {'api" . $slash . "u005fkey':'" . $sentinel . "'} suffix",
        ];
    }

    #[DataProvider('nonCanonicalBase64SensitiveMapKeyProvider')]
    public function testCreateRejectsLenientlyDecodableBase64SensitiveMapKeys(string $key): void
    {
        self::assertSame('api_key', base64_decode($key, false));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: [$key => 'synthetic-redaction-sentinel']),
            [$key, 'synthetic-redaction-sentinel'],
        );
    }

    #[DataProvider('nonCanonicalBase64SensitiveMapKeyProvider')]
    public function testFromArrayRejectsLenientlyDecodableBase64SensitiveMapKeys(string $key): void
    {
        self::assertSame('api_key', base64_decode($key, false));
        $payload = [$key => 'synthetic-redaction-sentinel'];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$key, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalBase64SensitiveMapKeyProvider(): iterable
    {
        $base64 = base64_encode('api_key');
        foreach ([
            'space' => ' ',
            'horizontal tab' => "\t",
            'line feed' => "\n",
            'vertical tab' => "\v",
            'form feed' => "\f",
            'carriage return' => "\r",
        ] as $label => $whitespace) {
            yield 'folded with ' . $label => [substr($base64, 0, 4) . $whitespace . substr($base64, 4)];
        }

        yield 'internal padding' => [substr($base64, 0, 4) . '=' . substr($base64, 4)];
        yield 'excess internal padding' => [substr($base64, 0, 4) . '===' . substr($base64, 4)];
    }

    #[DataProvider('jsonWrappedBase64SensitiveMapKeyProvider')]
    public function testCreateRejectsJsonWrappedBase64SensitiveMapKeys(string $key): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: [$key => 'synthetic-redaction-sentinel']),
            [$key, 'synthetic-redaction-sentinel'],
        );
    }

    #[DataProvider('jsonWrappedBase64SensitiveMapKeyProvider')]
    public function testFromArrayRejectsJsonWrappedBase64SensitiveMapKeys(string $key): void
    {
        $payload = [$key => 'synthetic-redaction-sentinel'];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$key, 'synthetic-redaction-sentinel'],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function jsonWrappedBase64SensitiveMapKeyProvider(): iterable
    {
        $wrapped = json_encode(base64_encode('api_key'), JSON_THROW_ON_ERROR);

        yield 'JSON string wrapper' => [$wrapped];
        yield 'percent-encoded JSON string wrapper' => [rawurlencode($wrapped)];
    }

    public function testCreateAndFromArrayRejectSignatureCountFormMetadata(): void
    {
        $payload = ['raw' => 'signature_count=0'];
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: $payload),
            ['signature_count'],
        );

        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            ['signature_count'],
        );
    }

    public function testCreateAndFromArrayAllowOrdinaryPublicRatioNotation(): void
    {
        $event = self::event(payload: ['raw' => 'public ratio a:1 currently']);

        self::assertSame($event->toArray(), PaperMarketEvent::fromArray($event->toArray())->toArray());
    }

    public function testCreateRejectsRawJsonPayloadStringsWithEscapedSensitiveKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        self::event(payload: ['raw' => '{"api\u005fkey":"synthetic-secret-sentinel"}']);
    }

    public function testCreateRejectsSensitiveJsonObjectFragmentsWithEscapedStructuralQuotes(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';

        try {
            self::event(payload: [
                'raw' => 'prefix {\\"api\\u005fkey\\":\\"' . $sentinel . '\\"} suffix',
            ]);
            self::fail('An escaped sensitive JSON object fragment must be rejected by create().');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertStringNotContainsString($sentinel, $exception->getMessage());
        }
    }

    #[DataProvider('escapedSensitiveMemberPrefixProvider')]
    public function testCreateRejectsEscapedSensitiveMemberAfterAlphanumericOrUnderscorePrefix(
        string $prefix,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix' . $prefix . '\\"api\\u005fkey\\":\\"' . $sentinel . '\\" suffix';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    #[DataProvider('escapedSensitiveMemberPrefixProvider')]
    public function testFromArrayRejectsEscapedSensitiveMemberAfterAlphanumericOrUnderscorePrefix(
        string $prefix,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix' . $prefix . '\\"api\\u005fkey\\":\\"' . $sentinel . '\\" suffix';
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$raw, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function escapedSensitiveMemberPrefixProvider(): iterable
    {
        yield 'alphanumeric prefix' => ['a'];
        yield 'underscore prefix' => ['_'];
    }

    public function testCreateRejectsUnquotedJsonUnicodeEscapedSensitiveMember(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix {api\\u005fkey:"' . $sentinel . '"} suffix';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    public function testFromArrayRejectsUnquotedJsonUnicodeEscapedSensitiveMember(): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix {api\\u005fkey:"' . $sentinel . '"} suffix';
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$raw, $sentinel],
        );
    }

    #[DataProvider('embeddedSensitiveRepresentationProvider')]
    public function testCreateRejectsDelimiterBoundedEmbeddedCredentialRepresentations(string $raw): void
    {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            [$raw, 'synthetic-redaction-sentinel'],
        );
    }

    #[DataProvider('embeddedSensitiveRepresentationProvider')]
    public function testFromArrayRejectsDelimiterBoundedEmbeddedCredentialsOnStrictWireInput(
        string $raw,
    ): void {
        $data = self::event(payload: ['raw' => 'public-market-data'])->toArray();
        $data['payload'] = ['raw' => $raw];
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($data['payload']));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$raw, 'synthetic-redaction-sentinel'],
        );
    }

    #[DataProvider('malformedEmbeddedBase64CredentialPaddingProvider')]
    public function testCreateRejectsEmbeddedCredentialsWithMalformedBase64Padding(
        string $malformed,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix|' . $malformed . '|suffix';

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    #[DataProvider('malformedEmbeddedBase64CredentialPaddingProvider')]
    public function testFromArrayRejectsEmbeddedCredentialsWithMalformedBase64Padding(
        string $malformed,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'prefix|' . $malformed . '|suffix';
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$raw, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function malformedEmbeddedBase64CredentialPaddingProvider(): iterable
    {
        $requiresTwoPadding = self::base64CredentialWithPaddingCount(2);
        $requiresOnePadding = self::base64CredentialWithPaddingCount(1);

        yield 'classic partial padding' => [substr($requiresTwoPadding, 0, -1)];
        yield 'classic excessive padding' => [$requiresOnePadding . '='];
        yield 'URL-safe partial padding' => [strtr(substr($requiresTwoPadding, 0, -1), '+/', '-_')];
        yield 'URL-safe excessive padding' => [strtr($requiresOnePadding . '=', '+/', '-_')];
    }

    #[DataProvider('foldedBase64AlphabetBoundaryProvider')]
    public function testCreateRejectsFoldedCredentialBase64AcrossAlphabetAlignmentBoundaries(
        string $credential,
        string $public,
    ): void {
        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $credential]),
            [$credential, 'synthetic-fold-alignment-sentinel'],
        );
    }

    #[DataProvider('foldedBase64AlphabetBoundaryProvider')]
    public function testFromArrayRejectsFoldedCredentialBase64AcrossAlphabetAlignmentBoundaries(
        string $credential,
        string $public,
    ): void {
        $payload = ['raw' => $credential];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$credential, 'synthetic-fold-alignment-sentinel'],
        );
    }

    #[DataProvider('foldedBase64AlphabetBoundaryProvider')]
    public function testCreateAndFromArrayAllowFoldedPublicBase64AcrossAlphabetAlignmentBoundaries(
        string $credential,
        string $public,
    ): void {
        $event = self::event(payload: ['raw' => $public]);

        self::assertSame(
            $event->toArray(),
            PaperMarketEvent::fromArray($event->toArray())->toArray(),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function foldedBase64AlphabetBoundaryProvider(): iterable
    {
        $credentialJson = json_encode(
            [
                'api_key' => 'synthetic-fold-alignment-sentinel',
                'price' => '1',
            ],
            JSON_THROW_ON_ERROR,
        );
        $publicJson = json_encode(
            [
                'price' => 1,
            ],
            JSON_THROW_ON_ERROR,
        );

        foreach (range(1, 3) as $prefixLength) {
            foreach (range(0, 4) as $suffixLength) {
                $prefix = str_repeat('A', $prefixLength);
                $suffix = str_repeat('A', $suffixLength);
                $fold = static fn (string $encoded): string => substr($encoded, 0, 4)
                    . "\r\n "
                    . substr($encoded, 4);

                yield sprintf('prefix %d, suffix %d', $prefixLength, $suffixLength) => [
                    $prefix . $fold(rtrim(base64_encode($credentialJson), '=')) . $suffix,
                    $prefix . $fold(rtrim(base64_encode($publicJson), '=')) . $suffix,
                ];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function embeddedSensitiveRepresentationProvider(): iterable
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $slash = '\\';

        yield 'escaped JSON member after opening bracket' => [
            'prefix [\\\"api\\u005fkey\\\":\\\"' . $sentinel . '\\\"] suffix',
        ];
        yield 'escaped JSON member at string start' => [
            '\\\"api\\u005fkey\\\":\\\"' . $sentinel . '\\"',
        ];
        yield 'escaped JSON member after ASCII whitespace' => [
            "prefix\f\\\"api\\u005fkey\\\":\\\"" . $sentinel . '\\"',
        ];
        yield 'escaped JSON member between punctuation delimiters' => [
            'prefix|'
            . $slash . '"api' . $slash . 'u005fkey' . $slash . '":'
            . $slash . '"' . $sentinel . $slash . '"|suffix',
        ];
        yield 'escaped opening and plain closing credential key quote' => [
            'prefix ['
            . $slash . '"api' . $slash . 'u005fkey":'
            . $slash . '"' . $sentinel . $slash . '"] suffix',
        ];
        yield 'plain opening and escaped closing credential key quote' => [
            'prefix ["api' . $slash . 'u005fkey' . $slash . '":'
            . $slash . '"' . $sentinel . $slash . '"] suffix',
        ];
        yield 'PHP serialized credential map' => [
            'prefix [' . serialize(['api_key' => $sentinel]) . '] suffix',
        ];
        yield 'PHP serialized credential map between punctuation delimiters' => [
            'prefix|' . serialize(['api_key' => $sentinel]) . '|suffix',
        ];
        yield 'canonical Base64 credential JSON' => [
            'prefix [' . base64_encode('{"api_key":"' . $sentinel . '"}') . '] suffix',
        ];
        yield 'canonical Base64 credential JSON between token delimiters' => [
            'prefix|' . base64_encode('{"api_key":"' . $sentinel . '"}') . '|suffix',
        ];

        $urlFixture = json_encode(
            ['api_key' => $sentinel, 'note' => "\u{1003E}"],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        $classicPadded = base64_encode($urlFixture);
        $urlPadded = strtr($classicPadded, '+/', '-_');

        yield 'unpadded canonical Base64 credential JSON' => [
            'prefix [' . rtrim($classicPadded, '=') . '] suffix',
        ];
        yield 'padded canonical Base64url credential JSON' => [
            'prefix [' . $urlPadded . '] suffix',
        ];
        yield 'unpadded canonical Base64url credential JSON' => [
            'prefix [' . rtrim($urlPadded, '=') . '] suffix',
        ];
    }

    public function testCreateAndWireRoundTripAllowWindowsPathProseAndEscapedPublicJson(): void
    {
        $event = self::event(payload: [
            'note' => 'Ordinary note: the public folder "C:\\prices": contains BTCUSDT snapshots.',
            'raw' => 'prefix {\\"symbol\\":\\"BTCUSDT\\",\\"price\\":\\"29999.0\\"} suffix',
        ]);

        self::assertSame($event->toArray(), PaperMarketEvent::fromArray($event->toArray())->toArray());
    }

    #[DataProvider('quotedColonPublicProseProvider')]
    public function testCreateAndFromArrayAllowQuotedColonPublicProseWithBackslashes(
        string $raw,
    ): void {
        $event = self::event(payload: ['note' => $raw]);

        self::assertSame(
            $event->toArray(),
            PaperMarketEvent::fromArray($event->toArray())->toArray(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function quotedColonPublicProseProvider(): iterable
    {
        yield 'bid-backslash-ask prose' => [
            'Market note: "bid\\ask": public spread.',
        ];
        yield 'UNC public folder prose' => [
            'Market note: "\\\\market-server\\public": BTCUSDT snapshots.',
        ];
    }

    public function testCreateRejectsRawFormPayloadStringsWithEncodedSensitiveKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_market_sensitive_field_rejected');

        self::event(payload: ['raw' => 'api%5Fkey=synthetic-secret-sentinel']);
    }

    #[DataProvider('sensitiveAssignmentAsciiWhitespaceProvider')]
    public function testCreateRejectsSensitiveAssignmentWithAsciiWhitespaceAroundEquals(
        string $whitespace,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'api_key' . $whitespace . '=' . $whitespace . $sentinel;

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => self::event(payload: ['raw' => $raw]),
            [$raw, $sentinel],
        );
    }

    #[DataProvider('sensitiveAssignmentAsciiWhitespaceProvider')]
    public function testFromArrayRejectsSensitiveAssignmentWithAsciiWhitespaceAroundEquals(
        string $whitespace,
    ): void
    {
        $sentinel = 'synthetic-redaction-sentinel';
        $raw = 'api_key' . $whitespace . '=' . $whitespace . $sentinel;
        $payload = ['raw' => $raw];
        $data = self::event(payload: ['price' => '29999.0'])->toArray();
        $data['payload'] = $payload;
        $data['payload_hash'] = hash('sha256', CanonicalJson::encode($payload));

        self::assertSensitiveRejectionWithoutDisclosure(
            static fn () => PaperMarketEvent::fromArray($data),
            [$raw, $sentinel],
        );
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveAssignmentAsciiWhitespaceProvider(): iterable
    {
        yield 'newline' => ["\n"];
        yield 'form-feed' => ["\f"];
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
        yield 'escaped structural quotes around raw JSON' => [[
            'raw' => 'prefix {\\"api\\u005fkey\\":\\"synthetic-secret-sentinel\\"} suffix',
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
        #[\SensitiveParameter] array $payload = ['ask' => '30001.0', 'bid' => '29999.0'],
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

    private static function base64CredentialWithPaddingCount(int $paddingCount): string
    {
        for ($suffixLength = 0; $suffixLength < 3; ++$suffixLength) {
            $json = json_encode(
                [
                    'api_key' => 'synthetic-redaction-sentinel',
                    'suffix' => str_repeat('x', $suffixLength),
                ],
                JSON_THROW_ON_ERROR,
            );
            $encoded = base64_encode($json);
            if (\strlen($encoded) - \strlen(rtrim($encoded, '=')) === $paddingCount) {
                return $encoded;
            }
        }

        throw new \LogicException('paper_market_test_base64_padding_fixture_unavailable');
    }

    /**
     * @param callable(): mixed $operation
     * @param list<string>       $prohibitedFragments
     */
    private static function assertSensitiveRejectionWithoutDisclosure(
        callable $operation,
        array $prohibitedFragments,
    ): void {
        try {
            $operation();
            self::fail('Embedded credential material must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());

            $current = $exception;
            do {
                foreach ($prohibitedFragments as $fragment) {
                    self::assertStringNotContainsString($fragment, $current->getMessage());
                }

                $current = $current->getPrevious();
            } while ($current !== null);
        }
    }

    /**
     * @param callable(): mixed $operation
     * @param list<string>       $prohibitedFragments
     */
    private static function assertSensitiveRejectionWithFullTraceWithoutDisclosure(
        #[\SensitiveParameter] callable $operation,
        #[\SensitiveParameter] array $prohibitedFragments,
    ): void {
        try {
            $operation();
            self::fail('A prefixed composed credential key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $trace = self::renderExceptionTraceChain($exception);
            foreach ($prohibitedFragments as $fragment) {
                self::assertStringNotContainsString($fragment, $trace);
            }
        }
    }

    /** @param callable(): mixed $operation */
    private static function assertSensitiveFormRejectionWithoutDisclosure(
        #[\SensitiveParameter] callable $operation,
        #[\SensitiveParameter] string $raw,
        #[\SensitiveParameter] string $sentinel,
    ): void {
        try {
            $operation();
            self::fail('A composed sensitive form key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_market_sensitive_field_rejected', $exception->getMessage());
            self::assertNull($exception->getPrevious());
            $trace = self::renderExceptionTraceChain($exception);
            self::assertStringNotContainsString($raw, $trace);
            self::assertStringNotContainsString($sentinel, $trace);
        }
    }

    private static function renderExceptionTraceChain(\Throwable $exception): string
    {
        $rendered = '';
        $current = $exception;
        do {
            $rendered .= print_r([
                'message' => $current->getMessage(),
                'trace' => $current->getTrace(),
            ], true);
            $current = $current->getPrevious();
        } while ($current !== null);

        return $rendered;
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
