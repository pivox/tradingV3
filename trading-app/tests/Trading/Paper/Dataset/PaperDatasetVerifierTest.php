<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperDatasetVerifier::class)]
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
            'schemaVersion' => 1,
            'recorderVersion' => '1.0.0',
            'datasetId' => 'dataset-okx-001',
            'venue' => PaperMarketDataVenue::OKX,
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
            schemaVersion: 1,
            recorderVersion: '1.0.0',
            datasetId: 'dataset-okx-001',
            venue: PaperMarketDataVenue::OKX,
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

    private function event(string $sequence, int $microseconds): PaperMarketEvent
    {
        return PaperMarketEvent::create(
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
