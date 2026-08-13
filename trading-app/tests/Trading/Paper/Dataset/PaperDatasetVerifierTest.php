<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Trading\Paper\Dataset\PaperDatasetFormatLimits;
use App\Trading\Paper\Dataset\PaperDatasetLineReader;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetSnapshotLimits;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Dataset\VerifiedPaperDatasetSnapshot;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalRequest;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(PaperDatasetVerifier::class)]
#[CoversClass(PaperDatasetSnapshotLimits::class)]
#[CoversClass(VerifiedPaperDatasetSnapshot::class)]
#[CoversClass(PaperDatasetManifest::class)]
#[CoversClass(PaperDatasetRecorder::class)]
#[CoversClass(PaperMarketEvent::class)]
#[CoversClass(CanonicalJson::class)]
final class PaperDatasetVerifierTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-verifier-test-');
        if ($path === false || !unlink($path) || !mkdir($path, 0700)) {
            self::fail('Unable to create test directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            self::fail('Unable to resolve test directory.');
        }
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testRoot);
    }

    public function testRejectsTruncatedJsonLineWithoutDisclosingPayload(): void
    {
        $this->createCompleteDataset();
        file_put_contents($this->eventsPath(), '{"payload":{"bid":"private-sentinel"}');

        $this->assertVerificationFailsWithoutPayload('paper_dataset_event_invalid', ['private-sentinel']);
    }

    public function testBaselineSnapshotReturnsTheManifestAndExactOrderedVerifiedEvents(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $candle = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::CANDLE_1M,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.000000Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T10:01:00.000001Z'),
            sequence: '1',
            payload: [
                'native_symbol' => 'BTC-USDT-SWAP',
                'bar' => '1m',
                'open' => '30000',
                'high' => '30001',
                'low' => '29999',
                'close' => '30000.5',
                'volume_contracts' => '10',
                'volume_base' => '0.001',
                'volume_quote' => '30',
                'confirmed' => true,
                'origin' => 'rest_history_candles',
            ],
        );
        $book = $this->event(sequence: '1', microseconds: 1);
        $recorder->append($candle);
        $recorder->append($book);
        $expectedManifest = $recorder->complete();

        $snapshot = (new PaperDatasetVerifier())->verifyBaselineSnapshot(
            $recorder->datasetDirectory(),
        );

        self::assertSame($expectedManifest->toArray(), $snapshot->manifest->toArray());
        self::assertContainsOnlyInstancesOf(PaperMarketEvent::class, $snapshot->events);
        self::assertSame(
            [
                CanonicalJson::encode($candle->toArray()),
                CanonicalJson::encode($book->toArray()),
            ],
            array_map(
                static fn (PaperMarketEvent $event): string => CanonicalJson::encode($event->toArray()),
                $snapshot->events,
            ),
        );

        $callerEvents = $snapshot->events;
        array_pop($callerEvents);
        self::assertCount(2, $snapshot->events);
    }

    public function testBaselineSnapshotDefensivelyCopiesAndValidatesItsEventList(): void
    {
        $events = ['source-key' => $this->event(sequence: '1', microseconds: 1)];
        $snapshot = new VerifiedPaperDatasetSnapshot($this->manifest(), $events);
        array_pop($events);

        self::assertCount(1, $snapshot->events);
        self::assertSame([0], array_keys($snapshot->events));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('verified_paper_dataset_events_invalid');

        new VerifiedPaperDatasetSnapshot($this->manifest(), ['not-an-event']);
    }

    public function testManifestOnlyVerificationDoesNotCollectOrApplySnapshotLimits(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1', microseconds: 1));
        $recorder->append($this->event(sequence: '2', microseconds: 2));
        $expected = $recorder->complete();
        $verifier = new PaperDatasetVerifier(
            snapshotLimits: new PaperDatasetSnapshotLimits(1, 1, 1, 1),
        );

        self::assertSame($expected->toArray(), $verifier->verify($recorder->datasetDirectory())->toArray());
        self::assertSame(
            $expected->toArray(),
            $verifier->verifyForBaseline($recorder->datasetDirectory())->toArray(),
        );
    }

    public function testBaselineSnapshotRejectsManifestEventCountAboveItsBound(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1', microseconds: 1));
        $recorder->append($this->event(sequence: '2', microseconds: 2));
        $recorder->complete();
        $verifier = new PaperDatasetVerifier(
            snapshotLimits: new PaperDatasetSnapshotLimits(1, 1024 * 1024),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_snapshot_limit_exceeded');

        $verifier->verifyBaselineSnapshot($recorder->datasetDirectory());
    }

    public function testBaselineSnapshotRejectsEventsFileAboveItsByteBound(): void
    {
        $this->createCompleteDataset();
        $bytes = filesize($this->eventsPath());
        self::assertIsInt($bytes);
        self::assertGreaterThan(1, $bytes);
        $verifier = new PaperDatasetVerifier(
            snapshotLimits: new PaperDatasetSnapshotLimits(10, $bytes - 1),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_snapshot_limit_exceeded');

        $verifier->verifyBaselineSnapshot($this->datasetDirectory());
    }

    #[DataProvider('tightStructuralSnapshotLimitsProvider')]
    public function testBaselineSnapshotEnforcesItsStructuralBounds(
        int $maximumNodes,
        int $maximumKeys,
    ): void {
        $this->createCompleteDataset();
        $verifier = new PaperDatasetVerifier(
            snapshotLimits: new PaperDatasetSnapshotLimits(
                maximumNodes: $maximumNodes,
                maximumKeys: $maximumKeys,
            ),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_snapshot_limit_exceeded');

        $verifier->verifyBaselineSnapshot($this->datasetDirectory());
    }

    public function testBaselineSnapshotEnforcesItsEventBoundAgainstForgedManifestCount(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1', microseconds: 1));
        $recorder->append($this->event(sequence: '2', microseconds: 2));
        $recorder->complete();
        $this->rewriteManifest(['event_count' => 1]);
        $verifier = new PaperDatasetVerifier(
            snapshotLimits: new PaperDatasetSnapshotLimits(1, 1024 * 1024),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_snapshot_limit_exceeded');

        $verifier->verifyBaselineSnapshot($this->datasetDirectory());
    }

    public function testBaselineSnapshotDoesNotAcceptAPartialEventLimit(): void
    {
        $method = new \ReflectionMethod(PaperDatasetVerifier::class, 'verifyBaselineSnapshot');

        self::assertSame(1, $method->getNumberOfParameters());
    }

    #[DataProvider('invalidSnapshotLimitsProvider')]
    public function testSnapshotLimitsCanOnlyTightenTheGlobalBounds(
        int $events,
        int $bytes,
        int $nodes,
        int $keys,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_dataset_snapshot_limits_invalid');

        new PaperDatasetSnapshotLimits($events, $bytes, $nodes, $keys);
    }

    public function testDefaultSnapshotBudgetRejectsBeforePhpMemoryExhaustion(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $datasetRoot = $this->testRoot . '/snapshot-memory-guard';
        $script = sprintf(
            <<<'PHP'
require %s;

$root = %s;
$directory = $root . '/dataset-memory-guard';
mkdir($root, 0700);
mkdir($directory, 0700);
$eventsPath = $directory . '/events.ndjson';
$handle = fopen($eventsPath, 'wb');
chmod($eventsPath, 0600);
$checksum = hash_init('sha256');
$first = null;
$last = null;
$lastEventId = null;
$padding = str_repeat('x!', 350_000);
$payload = ['ask' => '30001', 'bid' => '29999', 'padding' => $padding];
$payloadHash = hash('sha256', App\Trading\Paper\MarketData\CanonicalJson::encode($payload));

for ($index = 0; $index < 25; ++$index) {
    $exchangeAt = (new DateTimeImmutable('2026-07-19T10:00:00.000000Z'))->modify('+' . $index . ' seconds');
    $exchangeTimestamp = $exchangeAt->format('Y-m-d\TH:i:s.u\Z');
    $sequence = (string) ($index + 1);
    $eventId = hash('sha256', implode('|', [
        '2',
        'mainnet',
        'okx',
        'BTCUSDT',
        'top_of_book',
        $exchangeTimestamp,
        $sequence,
    ]));
    $event = [
        'schema_version' => 2,
        'event_id' => $eventId,
        'source_network' => 'mainnet',
        'source_venue' => 'okx',
        'symbol' => 'BTCUSDT',
        'channel' => 'top_of_book',
        'exchange_timestamp' => $exchangeTimestamp,
        'received_timestamp' => $exchangeAt->modify('+1 second')->format('Y-m-d\TH:i:s.u\Z'),
        'sequence' => $sequence,
        'payload' => $payload,
        'payload_hash' => $payloadHash,
    ];
    $line = App\Trading\Paper\MarketData\CanonicalJson::encode($event) . "\n";
    fwrite($handle, $line);
    hash_update($checksum, $line);
    $first ??= $exchangeAt;
    $last = $exchangeAt;
    $lastEventId = $eventId;
}
fclose($handle);
if (filesize($eventsPath) <= App\Trading\Paper\Dataset\PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_BYTES) {
    fwrite(STDOUT, 'fixture_under_snapshot_byte_limit');
    exit(4);
}

$manifest = new App\Trading\Paper\Dataset\PaperDatasetManifest(
    schemaVersion: App\Trading\Paper\Dataset\PaperDatasetManifest::SCHEMA_VERSION,
    recorderVersion: '1.0.0',
    datasetId: 'dataset-memory-guard',
    venue: App\Trading\Paper\MarketData\PaperMarketDataVenue::OKX,
    network: App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
    symbols: ['BTCUSDT' => 'BTC-USDT-SWAP'],
    startExchangeTimestamp: $first,
    endExchangeTimestamp: $last,
    channels: ['top_of_book'],
    eventCount: 25,
    sequenceGaps: [],
    quality: App\Trading\Paper\MarketData\PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
    modelName: null,
    modelVersion: null,
    eventsFileSha256: hash_final($checksum),
    state: App\Trading\Paper\Dataset\PaperDatasetState::COMPLETE,
    lastEventId: $lastEventId,
);
$manifestPath = $directory . '/manifest.json';
file_put_contents(
    $manifestPath,
    (new App\Trading\Paper\Dataset\PaperDatasetManifestCodec())->encode($manifest),
);
chmod($manifestPath, 0600);

try {
    (new App\Trading\Paper\Dataset\PaperDatasetVerifier())->verifyBaselineSnapshot($directory);
} catch (RuntimeException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit($exception->getMessage() === 'paper_dataset_snapshot_limit_exceeded' ? 0 : 2);
}

fwrite(STDOUT, 'unexpected_success');
exit(3);
PHP,
            var_export($autoload, true),
            var_export($datasetRoot, true),
        );
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            '-d',
            'xdebug.mode=off',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-r',
            $script,
        ]);
        $process->setTimeout(60.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame('paper_dataset_snapshot_limit_exceeded', $process->getOutput());
    }

    public function testDenseSnapshotBudgetRejectsBeforePhpMemoryExhaustion(): void
    {
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $datasetRoot = $this->testRoot . '/snapshot-dense-memory-guard';
        $script = sprintf(
            <<<'PHP'
require %s;

$root = %s;
$directory = $root . '/dataset-dense-memory-guard';
mkdir($root, 0700);
mkdir($directory, 0700);
$eventsPath = $directory . '/events.ndjson';
$handle = fopen($eventsPath, 'wb');
chmod($eventsPath, 0600);
$checksum = hash_init('sha256');
$first = null;
$last = null;
$lastEventId = null;
$payload = ['data' => array_fill(0, 19_000, 0)];
$payloadHash = hash('sha256', App\Trading\Paper\MarketData\CanonicalJson::encode($payload));

for ($index = 0; $index < 400; ++$index) {
    $exchangeAt = (new DateTimeImmutable('2026-07-19T10:00:00.000000Z'))->modify('+' . $index . ' seconds');
    $exchangeTimestamp = $exchangeAt->format('Y-m-d\TH:i:s.u\Z');
    $sequence = (string) ($index + 1);
    $eventId = hash('sha256', implode('|', [
        '2', 'mainnet', 'okx', 'BTCUSDT', 'top_of_book', $exchangeTimestamp, $sequence,
    ]));
    $line = App\Trading\Paper\MarketData\CanonicalJson::encode([
        'schema_version' => 2,
        'event_id' => $eventId,
        'source_network' => 'mainnet',
        'source_venue' => 'okx',
        'symbol' => 'BTCUSDT',
        'channel' => 'top_of_book',
        'exchange_timestamp' => $exchangeTimestamp,
        'received_timestamp' => $exchangeAt->modify('+1 second')->format('Y-m-d\TH:i:s.u\Z'),
        'sequence' => $sequence,
        'payload' => $payload,
        'payload_hash' => $payloadHash,
    ]) . "\n";
    fwrite($handle, $line);
    hash_update($checksum, $line);
    $first ??= $exchangeAt;
    $last = $exchangeAt;
    $lastEventId = $eventId;
}
fclose($handle);
$bytes = filesize($eventsPath);
if ($bytes >= App\Trading\Paper\Dataset\PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_BYTES) {
    fwrite(STDOUT, 'fixture_over_snapshot_byte_limit:' . $bytes);
    exit(4);
}

$manifest = new App\Trading\Paper\Dataset\PaperDatasetManifest(
    schemaVersion: App\Trading\Paper\Dataset\PaperDatasetManifest::SCHEMA_VERSION,
    recorderVersion: '1.0.0',
    datasetId: 'dataset-dense-memory-guard',
    venue: App\Trading\Paper\MarketData\PaperMarketDataVenue::OKX,
    network: App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
    symbols: ['BTCUSDT' => 'BTC-USDT-SWAP'],
    startExchangeTimestamp: $first,
    endExchangeTimestamp: $last,
    channels: ['top_of_book'],
    eventCount: 400,
    sequenceGaps: [],
    quality: App\Trading\Paper\MarketData\PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
    modelName: null,
    modelVersion: null,
    eventsFileSha256: hash_final($checksum),
    state: App\Trading\Paper\Dataset\PaperDatasetState::COMPLETE,
    lastEventId: $lastEventId,
);
$manifestPath = $directory . '/manifest.json';
file_put_contents(
    $manifestPath,
    (new App\Trading\Paper\Dataset\PaperDatasetManifestCodec())->encode($manifest),
);
chmod($manifestPath, 0600);

try {
    (new App\Trading\Paper\Dataset\PaperDatasetVerifier())->verifyBaselineSnapshot($directory);
} catch (RuntimeException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit($exception->getMessage() === 'paper_dataset_snapshot_limit_exceeded' ? 0 : 2);
}

fwrite(STDOUT, 'unexpected_success');
exit(3);
PHP,
            var_export($autoload, true),
            var_export($datasetRoot, true),
        );
        $process = new Process([
            PHP_BINARY,
            '-d',
            'memory_limit=128M',
            '-d',
            'xdebug.mode=off',
            '-d',
            'display_errors=0',
            '-d',
            'log_errors=0',
            '-r',
            $script,
        ]);
        $process->setTimeout(60.0);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame('', $process->getErrorOutput());
        self::assertSame('paper_dataset_snapshot_limit_exceeded', $process->getOutput());
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function invalidSnapshotLimitsProvider(): iterable
    {
        yield 'zero events' => [0, 1, 1, 1];
        yield 'too many events' => [
            PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_EVENTS + 1,
            1,
            1,
            1,
        ];
        yield 'zero bytes' => [1, 0, 1, 1];
        yield 'too many bytes' => [
            1,
            PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_BYTES + 1,
            1,
            1,
        ];
        yield 'zero nodes' => [1, 1, 0, 1];
        yield 'too many nodes' => [
            1,
            1,
            PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_NODES + 1,
            1,
        ];
        yield 'zero keys' => [1, 1, 1, 0];
        yield 'too many keys' => [
            1,
            1,
            1,
            PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_KEYS + 1,
        ];
    }

    /** @return iterable<string, array{int, int}> */
    public static function tightStructuralSnapshotLimitsProvider(): iterable
    {
        yield 'nodes' => [1, PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_KEYS];
        yield 'keys' => [PaperDatasetFormatLimits::MAX_BACKTEST_SNAPSHOT_NODES, 1];
    }

    public function testBaselineAcceptsCertifiableHyperliquidModelledBookDataset(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->hyperliquidModelledBookManifest());
        foreach ([
            [PaperMarketDataChannel::CANDLE_1M, '1m', 60_000],
        ] as $index => [$channel, $interval, $duration]) {
            $start = 1_752_919_200_000;
            $close = $start + $duration - 1;
            $timestamp = \DateTimeImmutable::createFromFormat(
                '!U.u',
                sprintf('%d.%03d000', intdiv($close, 1_000), $close % 1_000),
                new \DateTimeZone('UTC'),
            );
            self::assertInstanceOf(\DateTimeImmutable::class, $timestamp);
            $recorder->append(PaperMarketEvent::create(
                PaperMarketDataNetwork::MAINNET,
                venue: PaperMarketDataVenue::HYPERLIQUID,
                symbol: 'BTCUSDT',
                channel: $channel,
                exchangeTimestamp: $timestamp,
                receivedTimestamp: $timestamp,
                sequence: (string) ($index + 1),
                payload: [
                    'native_symbol' => 'BTC',
                    'open' => '30000',
                    'high' => '30000',
                    'low' => '30000',
                    'close' => '30000.0',
                    'volume' => '0',
                    'trade_count' => '0',
                    'close_time' => (string) $close,
                    'confirmed' => true,
                    'interval' => $interval,
                    'origin' => 'rest_candle_snapshot',
                    'start_time' => (string) $start,
                ],
            ));
        }
        $recorder->complete();

        $manifest = (new PaperDatasetVerifier())->verifyForBaseline($recorder->datasetDirectory());

        self::assertSame(PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK, $manifest->quality);
        self::assertSame('hl_candle_atr_top_v1', $manifest->modelName);
        self::assertSame('1.0.0', $manifest->modelVersion);
        self::assertSame(PaperMarketDataNetwork::MAINNET, $manifest->network);
    }

    public function testBoundedLineReaderAcceptsTerminatedValidJsonExactlyAtEventLineLimit(): void
    {
        $line = '['
            . str_repeat(' ', PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES - 3)
            . "]\n";
        self::assertSame(PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES, strlen($line));
        self::assertIsArray(json_decode($line, true, 2, JSON_THROW_ON_ERROR));
        $handle = tmpfile();
        self::assertIsResource($handle);
        try {
            self::assertSame(strlen($line), fwrite($handle, $line));
            self::assertTrue(rewind($handle));
            $reader = new PaperDatasetLineReader(new PaperDatasetRecorderFilesystem());

            self::assertSame(
                $line,
                $reader->read(
                    $handle,
                    'paper_dataset_events_read_failed',
                    'paper_dataset_event_invalid',
                ),
            );
        } finally {
            fclose($handle);
        }
    }

    public function testVerifierReadsEventLinesThroughTheSharedBoundedContract(): void
    {
        $this->createCompleteDataset();
        $filesystem = new BoundedVerifierLineFilesystem();

        (new PaperDatasetVerifier(filesystem: $filesystem))->verify($this->datasetDirectory());

        self::assertNotEmpty($filesystem->lineReadLengths);
        self::assertSame(
            [PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES + 1],
            array_values(array_unique($filesystem->lineReadLengths)),
        );
    }

    public function testVerifierRejectsAnEventLineExceedingTheSharedBound(): void
    {
        $this->createCompleteDataset();
        $oversized = str_repeat(' ', PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES + 1) . "\n";
        self::assertSame(strlen($oversized), file_put_contents($this->eventsPath(), $oversized));

        $this->assertVerificationFailsWithoutPayload('paper_dataset_event_invalid', []);
    }

    public function testVerifierRejectsAMaximumLengthFragmentWithoutNewline(): void
    {
        $this->createCompleteDataset();
        $fragment = str_repeat(' ', PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES);
        self::assertSame(strlen($fragment), file_put_contents($this->eventsPath(), $fragment));

        $this->assertVerificationFailsWithoutPayload('paper_dataset_event_invalid', []);
    }

    public function testRejectsOversizedManifestBeforeReadingItsContents(): void
    {
        $this->createCompleteDataset();
        $handle = fopen($this->manifestPath(), 'r+b');
        self::assertIsResource($handle);
        try {
            self::assertTrue(ftruncate($handle, PaperDatasetFormatLimits::MAX_MANIFEST_BYTES + 1));
        } finally {
            fclose($handle);
        }

        $this->assertVerificationFailsWithoutPayload('paper_dataset_manifest_unreadable', []);
    }

    public function testRejectsForgedEventPayloadHash(): void
    {
        $this->createCompleteDataset();
        $line = file_get_contents($this->eventsPath());
        self::assertIsString($line);
        $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        self::assertIsArray($event);
        $event['payload_hash'] = str_repeat('0', 64);
        file_put_contents($this->eventsPath(), CanonicalJson::encode($event) . "\n");
        $this->rewriteManifest(['events_file_sha256' => hash_file('sha256', $this->eventsPath())]);

        $this->assertVerificationFailsWithoutPayload('paper_dataset_event_invalid', ['29999.0']);
    }

    public function testRejectsWrongManifestEventCount(): void
    {
        $this->createCompleteDataset();
        $this->rewriteManifest(['event_count' => 2]);

        $this->assertVerificationFailsWithoutPayload('paper_dataset_event_count_mismatch', ['29999.0']);
    }

    public function testRejectsAnOtherwiseValidEventFromAnotherNetwork(): void
    {
        $this->createCompleteDataset();
        $event = PaperMarketEvent::create(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::TESTNET,
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.000001Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T10:00:01.000001Z'),
            sequence: '1',
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
        self::assertNotFalse(file_put_contents(
            $this->eventsPath(),
            CanonicalJson::encode($event->toArray()) . "\n",
        ));
        $this->rewriteManifest([
            'events_file_sha256' => hash_file('sha256', $this->eventsPath()),
            'last_event_id' => $event->eventId,
        ]);

        $this->assertVerificationFailsWithoutPayload(
            'paper_dataset_event_network_mismatch',
            ['29999.0'],
        );
    }

    public function testRejectsEventFileChangedAfterCompletion(): void
    {
        $this->createCompleteDataset();
        file_put_contents($this->eventsPath(), "\n", FILE_APPEND);

        $this->assertVerificationFailsWithoutPayload('paper_dataset_checksum_mismatch', ['29999.0']);
    }

    public function testRejectsMutationOfParsedBytesBeforeFinalDescriptorRehash(): void
    {
        $this->createCompleteDataset();
        $eventsPath = $this->eventsPath();
        $replacement = CanonicalJson::encode($this->event(sequence: '9', microseconds: 9)->toArray()) . "\n";
        self::assertSame(filesize($eventsPath), strlen($replacement));
        $filesystem = new VerifierFaultInjectingPaperDatasetFilesystem();
        $filesystem->overwriteEventsBeforeVerifierRehash($eventsPath, $replacement);

        try {
            (new PaperDatasetVerifier(filesystem: $filesystem))->verify($this->datasetDirectory());
            self::fail('Verifier must reject bytes changed after they were parsed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_verifier_snapshot_changed', $exception->getMessage());
        }
    }

    public function testRejectsSameSizeManifestMutationDuringEventsRehash(): void
    {
        $this->createCompleteDataset();
        $manifestPath = $this->manifestPath();
        $original = file_get_contents($manifestPath);
        self::assertIsString($original);
        $decoded = json_decode($original, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        self::assertIsArray($decoded);
        $decoded['recorder_version'] = '9.9.9';
        $replacement = CanonicalJson::encode($decoded) . "\n";
        self::assertSame(strlen($original), strlen($replacement));
        $filesystem = new VerifierFaultInjectingPaperDatasetFilesystem();
        $filesystem->overwriteManifestDuringEventsRehash($manifestPath, $replacement);

        try {
            (new PaperDatasetVerifier(filesystem: $filesystem))->verify($this->datasetDirectory());
            self::fail('Verifier must reject a manifest changed after its initial read.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_verifier_snapshot_changed', $exception->getMessage());
        }
    }

    public function testRejectsSameSizeEventsMutationDuringFinalManifestValidation(): void
    {
        $this->createCompleteDataset();
        $eventsPath = $this->eventsPath();
        $replacement = CanonicalJson::encode($this->event(sequence: '9', microseconds: 9)->toArray()) . "\n";
        self::assertSame(filesize($eventsPath), strlen($replacement));
        $filesystem = new VerifierFaultInjectingPaperDatasetFilesystem();
        $filesystem->overwriteEventsDuringFinalManifestValidation($eventsPath, $replacement);

        try {
            (new PaperDatasetVerifier(filesystem: $filesystem))->verify($this->datasetDirectory());
            self::fail('Verifier must reject events changed during final manifest validation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_verifier_snapshot_changed', $exception->getMessage());
        }
    }

    public function testFullVerifierTraceRedactsRawLineWhenExceptionArgumentsAreEnabled(): void
    {
        $this->createCompleteDataset();
        $sentinel = 'PAPER_VERIFIER_RAW_TRACE_SENTINEL_14c9e7';
        $rawLine = '{"payload":{"note":"' . $sentinel . '"}}';
        self::assertSame(strlen($rawLine), file_put_contents($this->eventsPath(), $rawLine));
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);

        try {
            (new PaperDatasetVerifier())->verify($this->datasetDirectory());
            self::fail('Malformed raw event line must fail verification.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_event_invalid', $exception->getMessage());
            $fullTrace = (string) $exception . "\n" . print_r($exception->getTrace(), true);
            self::assertStringNotContainsString($sentinel, $fullTrace);
            self::assertStringNotContainsString($rawLine, $fullTrace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function testVerifyRedactsDatasetDirectoryWhenExceptionArgumentsAreEnabled(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $sentinel = 'PAPER_DATASET_DIRECTORY_TRACE_' . 'SENTINEL_6b42d1';
        $datasetDirectory = $this->testRoot . '/' . $sentinel;

        try {
            (new PaperDatasetVerifier())->verify($datasetDirectory);
            self::fail('A missing dataset directory must fail verification.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_invalid', $exception->getMessage());
            $fullTrace = (string) $exception . "\n" . print_r($exception->getTrace(), true);
            self::assertStringNotContainsString($sentinel, $fullTrace);
            self::assertStringNotContainsString($datasetDirectory, $fullTrace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }

        $parameter = new \ReflectionParameter([PaperDatasetVerifier::class, 'verify'], 'datasetDirectory');
        self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testDeepVerifierTraceRedactsDatasetPathWhenExceptionArgumentsAreEnabled(): void
    {
        $this->createCompleteDataset();
        $safe = $this->testRoot . '/safe';
        self::assertTrue(mkdir($safe, 0700));
        $sentinel = 'PAPER_DATASET_DEEP_PATH_TRACE_' . 'SENTINEL_2d9f81';
        self::assertTrue(symlink($this->datasetRoot(), $safe . '/' . $sentinel));
        $aliasedDataset = $safe . '/' . $sentinel . '/dataset-okx-001';
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);

        try {
            (new PaperDatasetVerifier())->verify($aliasedDataset);
            self::fail('A dataset path containing an intermediate symlink must fail verification.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_symlink_rejected', $exception->getMessage());
            $fullTrace = (string) $exception . "\n" . print_r($exception->getTrace(), true);
            self::assertStringNotContainsString($sentinel, $fullTrace);
            self::assertStringNotContainsString($aliasedDataset, $fullTrace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function testEveryPathBearingVerifierParameterIsSensitive(): void
    {
        $pathBearingParameters = [
            'verify' => 'datasetDirectory',
            'verifyForBaseline' => 'datasetDirectory',
            'verifyBaselineSnapshot' => 'datasetDirectory',
            'verifySnapshot' => 'datasetDirectory',
            'assertNoSymlinkComponents' => 'path',
            'readRegularFile' => 'path',
            'assertRegularFileSnapshot' => 'path',
            'openRegularFile' => 'path',
            'assertHandleMatchesPath' => 'path',
            'pathStat' => 'path',
            'openPinnedDirectory' => 'path',
            'assertDirectoryHandleMatchesPath' => 'path',
            'pinDirectoryIdentity' => 'path',
            'scan' => 'eventsPath',
        ];

        foreach ($pathBearingParameters as $method => $parameterName) {
            $parameter = new \ReflectionParameter([PaperDatasetVerifier::class, $method], $parameterName);
            self::assertNotEmpty(
                $parameter->getAttributes(\SensitiveParameter::class),
                sprintf('%s::%s() must redact $%s from exception traces.', PaperDatasetVerifier::class, $method, $parameterName),
            );
        }
    }

    #[DataProvider('forgedManifestFactsProvider')]
    public function testRejectsForgedLastIdentityAndTimestamps(string $field, mixed $value, string $error): void
    {
        $this->createCompleteDataset();
        $this->rewriteManifest([$field => $value]);

        $this->assertVerificationFailsWithoutPayload($error, ['29999.0']);
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function forgedManifestFactsProvider(): iterable
    {
        yield 'last identity' => ['last_event_id', str_repeat('a', 64), 'paper_dataset_last_event_id_mismatch'];
        yield 'start timestamp' => ['start_exchange_timestamp', '2026-07-19T09:59:59.000000Z', 'paper_dataset_start_timestamp_mismatch'];
        yield 'end timestamp' => ['end_exchange_timestamp', '2026-07-19T10:00:01.000000Z', 'paper_dataset_end_timestamp_mismatch'];
    }

    public function testRejectsSequenceRegressionEvenWithMatchingChecksumAndManifestFacts(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1', microseconds: 1));
        $recorder->append($this->event(sequence: '2', microseconds: 2));
        $recorder->complete();

        $lines = file($this->eventsPath(), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        file_put_contents($this->eventsPath(), $lines[1] . "\n" . $lines[0] . "\n");
        $this->rewriteManifest([
            'events_file_sha256' => hash_file('sha256', $this->eventsPath()),
            'last_event_id' => json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR)['event_id'],
        ]);

        $this->assertVerificationFailsWithoutPayload('paper_dataset_sequence_regression', ['29999.0']);
    }

    public function testRejectsSymlinkedDatasetFile(): void
    {
        $this->createCompleteDataset();
        $realEvents = $this->datasetDirectory() . '/events.real.ndjson';
        self::assertTrue(rename($this->eventsPath(), $realEvents));
        self::assertTrue(symlink($realEvents, $this->eventsPath()));

        $this->assertVerificationFailsWithoutPayload('paper_dataset_symlink_rejected', ['29999.0']);
    }

    public function testRejectsHardlinkedEventsFileEvenWhenBytesAndChecksumMatch(): void
    {
        $this->createCompleteDataset();
        $eventsPath = $this->eventsPath();
        $contents = file_get_contents($eventsPath);
        self::assertIsString($contents);
        $victimPath = $this->testRoot . '/external-verifier-events-victim.ndjson';
        self::assertSame(strlen($contents), file_put_contents($victimPath, $contents));
        self::assertTrue(chmod($victimPath, 0640));
        self::assertTrue(unlink($eventsPath));
        self::assertTrue(link($victimPath, $eventsPath));

        $this->assertVerificationFailsWithoutPayload(
            'paper_dataset_file_validation_failed',
            ['29999.0'],
        );
        self::assertSame($contents, file_get_contents($victimPath));
        self::assertSame(0640, fileperms($victimPath) & 0777);
    }

    public function testRejectsMissingEventsFileWithStableCode(): void
    {
        $this->createCompleteDataset();
        self::assertTrue(unlink($this->eventsPath()));

        $this->assertVerificationFailsWithoutPayload('paper_dataset_file_unreadable', ['29999.0']);
    }

    public function testRejectsDatasetDirectoryThroughIntermediateSymlink(): void
    {
        $this->createCompleteDataset();
        $safe = $this->testRoot . '/safe';
        self::assertTrue(mkdir($safe, 0700));
        self::assertTrue(symlink($this->datasetRoot(), $safe . '/link'));
        $aliasedDataset = $safe . '/link/dataset-okx-001';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_symlink_rejected');

        (new PaperDatasetVerifier())->verify($aliasedDataset);
    }

    public function testRejectsRootReplacementBeforeFirstDirectoryPin(): void
    {
        $this->createCompleteDataset();
        $root = $this->datasetRoot();
        $replacementRoot = $this->testRoot . '/replacement-paper-market-data';
        $this->copyDirectory($root, $replacementRoot);
        $filesystem = new VerifierFaultInjectingPaperDatasetFilesystem();
        $filesystem->swapRootBeforeVerifierFirstPin($root, $replacementRoot);

        try {
            (new PaperDatasetVerifier(filesystem: $filesystem))->verify($this->datasetDirectory());
            self::fail('Verifier must not adopt a root replacement after canonicalization.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }
    }

    #[DataProvider('managedDirectoryProvider')]
    public function testRejectsManagedDirectoryPermissionDrift(string $directory): void
    {
        $this->createCompleteDataset();
        $path = $directory === 'root' ? $this->datasetRoot() : $this->datasetDirectory();
        self::assertTrue(chmod($path, 0750));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_directory_invalid');

        (new PaperDatasetVerifier())->verify($this->datasetDirectory());
    }

    /** @return iterable<string, array{string}> */
    public static function managedDirectoryProvider(): iterable
    {
        yield 'dataset root' => ['root'];
        yield 'dataset directory' => ['dataset'];
    }

    public function testStrictlyRejectsUnknownManifestFields(): void
    {
        $this->createCompleteDataset();
        $json = file_get_contents($this->manifestPath());
        self::assertIsString($json);
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        self::assertIsArray($manifest);
        $manifest['payload'] = ['bid' => 'private-sentinel'];
        file_put_contents($this->manifestPath(), CanonicalJson::encode($manifest) . "\n");

        $this->assertVerificationFailsWithoutPayload('paper_dataset_manifest_shape_invalid', ['private-sentinel']);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidManifestProvider')]
    public function testManifestEnforcesRequiredInvariants(array $overrides, string $error): void
    {
        $arguments = [
            'schemaVersion' => PaperDatasetManifest::SCHEMA_VERSION,
            'recorderVersion' => '1.0.0',
            'datasetId' => 'dataset-okx-001',
            'venue' => PaperMarketDataVenue::OKX,
            'network' => \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            'symbols' => ['BTCUSDT' => 'BTC-USDT-SWAP'],
            'startExchangeTimestamp' => null,
            'endExchangeTimestamp' => null,
            'channels' => [],
            'eventCount' => 0,
            'sequenceGaps' => [],
            'quality' => PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            'modelName' => null,
            'modelVersion' => null,
            'eventsFileSha256' => null,
            'state' => PaperDatasetState::RECORDING,
            'lastEventId' => null,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        new PaperDatasetManifest(...array_replace($arguments, $overrides));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidManifestProvider(): iterable
    {
        yield 'dataset ID' => [['datasetId' => '../escape'], 'paper_dataset_id_invalid'];
        yield 'legacy network in v2' => [[
            'network' => \App\Trading\Paper\MarketData\PaperMarketDataNetwork::LEGACY_UNKNOWN,
        ], 'paper_dataset_network_provenance_invalid'];
        yield 'known network in v1' => [[
            'schemaVersion' => PaperDatasetManifest::LEGACY_SCHEMA_VERSION,
        ], 'paper_dataset_network_provenance_invalid'];
        yield 'empty symbols' => [['symbols' => []], 'paper_dataset_symbols_invalid'];
        yield 'normalized symbol' => [['symbols' => ['SOLUSDT' => 'SOL-USDT-SWAP']], 'paper_dataset_symbols_invalid'];
        yield 'historical model name and version' => [[
            'quality' => PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
        ], 'paper_dataset_model_required'];
        yield 'complete checksum' => [[
            'state' => PaperDatasetState::COMPLETE,
            'endExchangeTimestamp' => new \DateTimeImmutable('2026-07-19T10:00:00Z'),
            'eventsFileSha256' => 'ABC',
        ], 'paper_dataset_checksum_invalid'];
        yield 'complete end timestamp' => [[
            'state' => PaperDatasetState::COMPLETE,
            'eventsFileSha256' => str_repeat('a', 64),
        ], 'paper_dataset_end_timestamp_required'];
        yield 'complete quality' => [[
            'state' => PaperDatasetState::COMPLETE,
            'endExchangeTimestamp' => new \DateTimeImmutable('2026-07-19T10:00:00Z'),
            'eventsFileSha256' => str_repeat('a', 64),
            'quality' => PaperMarketDataQuality::INCOMPLETE,
        ], 'paper_dataset_complete_quality_invalid'];
    }

    private function createCompleteDataset(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1', microseconds: 1));
        $recorder->complete();
    }

    /** @param array<string, mixed> $changes */
    private function rewriteManifest(array $changes): void
    {
        $json = file_get_contents($this->manifestPath());
        self::assertIsString($json);
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        self::assertIsArray($manifest);
        file_put_contents($this->manifestPath(), CanonicalJson::encode(array_replace($manifest, $changes)) . "\n");
    }

    /** @param list<string> $secrets */
    private function assertVerificationFailsWithoutPayload(string $error, array $secrets): void
    {
        try {
            (new PaperDatasetVerifier())->verify($this->datasetDirectory());
            self::fail('Verification must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame($error, $exception->getMessage());
            self::assertStringStartsWith('paper_dataset_', $exception->getMessage());
            $rendered = (string) $exception;
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $rendered);
            }
        }
    }

    private function manifest(): PaperDatasetManifest
    {
        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: 'dataset-okx-001',
            venue: PaperMarketDataVenue::OKX,
            network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT' => 'BTC-USDT-SWAP', 'ETHUSDT' => 'ETH-USDT-SWAP'],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
    }

    private function hyperliquidModelledBookManifest(): PaperDatasetManifest
    {
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'dataset-okx-001',
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2025-07-19T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2025-07-19T10:01:00.000000Z'),
        );

        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: $request->datasetId,
            venue: PaperMarketDataVenue::HYPERLIQUID,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT' => 'BTC'],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_MODELLED_BOOK,
            modelName: 'hl_candle_atr_top_v1',
            modelVersion: '1.0.0',
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
            historicalCoverage: $request->historicalCoverage(),
        );
    }

    private function event(string $sequence, int $microseconds): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable(sprintf('2026-07-19T10:00:00.%06dZ', $microseconds)),
            receivedTimestamp: new \DateTimeImmutable(sprintf('2026-07-19T10:00:01.%06dZ', $microseconds)),
            sequence: $sequence,
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
    }

    private function datasetRoot(): string
    {
        return $this->testRoot . '/paper-market-data';
    }

    private function datasetDirectory(): string
    {
        return $this->datasetRoot() . '/dataset-okx-001';
    }

    private function manifestPath(): string
    {
        return $this->datasetDirectory() . '/manifest.json';
    }

    private function eventsPath(): string
    {
        return $this->datasetDirectory() . '/events.ndjson';
    }

    private function copyDirectory(string $source, string $destination): void
    {
        self::assertTrue(mkdir($destination, 0700));
        $entries = scandir($source);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sourcePath = $source . '/' . $entry;
            $destinationPath = $destination . '/' . $entry;
            if (is_dir($sourcePath)) {
                $this->copyDirectory($sourcePath, $destinationPath);
            } else {
                self::assertTrue(copy($sourcePath, $destinationPath));
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

final class VerifierFaultInjectingPaperDatasetFilesystem extends PaperDatasetRecorderFilesystem
{
    private ?string $eventsMutationPath = null;
    private ?string $eventsMutationContents = null;
    private ?string $manifestMutationPath = null;
    private ?string $manifestMutationContents = null;
    private ?string $finalManifestEventsMutationPath = null;
    private ?string $finalManifestEventsMutationContents = null;
    private ?string $rootToSwapBeforeFirstPin = null;
    private ?string $replacementRootBeforeFirstPin = null;

    public function overwriteEventsBeforeVerifierRehash(string $path, string $contents): void
    {
        $this->eventsMutationPath = $path;
        $this->eventsMutationContents = $contents;
    }

    public function overwriteManifestDuringEventsRehash(string $path, string $contents): void
    {
        $this->manifestMutationPath = $path;
        $this->manifestMutationContents = $contents;
    }

    public function overwriteEventsDuringFinalManifestValidation(string $path, string $contents): void
    {
        $this->finalManifestEventsMutationPath = $path;
        $this->finalManifestEventsMutationContents = $contents;
    }

    public function swapRootBeforeVerifierFirstPin(string $root, string $replacement): void
    {
        $this->rootToSwapBeforeFirstPin = $root;
        $this->replacementRootBeforeFirstPin = $replacement;
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($this->rootToSwapBeforeFirstPin === $path
            && $this->replacementRootBeforeFirstPin !== null
        ) {
            $replacement = $this->replacementRootBeforeFirstPin;
            $this->rootToSwapBeforeFirstPin = null;
            $this->replacementRootBeforeFirstPin = null;
            if (!rename($path, $path . '.before-first-pin') || !rename($replacement, $path)) {
                throw new \RuntimeException('Unable to inject verifier root replacement.');
            }
        }

        return parent::pathStat($path, $operation);
    }

    /**
     * @param resource $handle
     *
     * @return array{checksum: string, bytes: int}
     */
    public function checksum($handle, string $operation): array
    {
        if ($operation === 'paper_dataset_verifier_manifest_rehash'
            && $this->finalManifestEventsMutationPath !== null
            && $this->finalManifestEventsMutationContents !== null
        ) {
            $path = $this->finalManifestEventsMutationPath;
            $contents = $this->finalManifestEventsMutationContents;
            $this->finalManifestEventsMutationPath = null;
            $this->finalManifestEventsMutationContents = null;
            if (file_put_contents($path, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Unable to inject final verifier events mutation.');
            }
        }
        if ($operation === 'paper_dataset_verifier_events_rehash'
            && $this->eventsMutationPath !== null
            && $this->eventsMutationContents !== null
        ) {
            $path = $this->eventsMutationPath;
            $contents = $this->eventsMutationContents;
            $this->eventsMutationPath = null;
            $this->eventsMutationContents = null;
            if (file_put_contents($path, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Unable to inject verifier snapshot mutation.');
            }
        }
        if ($operation === 'paper_dataset_verifier_events_rehash'
            && $this->manifestMutationPath !== null
            && $this->manifestMutationContents !== null
        ) {
            $path = $this->manifestMutationPath;
            $contents = $this->manifestMutationContents;
            $this->manifestMutationPath = null;
            $this->manifestMutationContents = null;
            if (file_put_contents($path, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Unable to inject manifest snapshot mutation.');
            }
        }

        return parent::checksum($handle, $operation);
    }
}

final class BoundedVerifierLineFilesystem extends PaperDatasetRecorderFilesystem
{
    /** @var list<int> */
    public array $lineReadLengths = [];

    /** @param resource $handle */
    public function readLine($handle, int $length, string $operation): string|false
    {
        $this->lineReadLengths[] = $length;

        return parent::readLine($handle, $length, $operation);
    }
}
