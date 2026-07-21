<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Trading\Paper\Dataset\PaperDatasetAppendResult;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
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

#[CoversClass(PaperDatasetRecorder::class)]
#[CoversClass(PaperDatasetRecorderFilesystem::class)]
#[CoversClass(PaperDatasetManifest::class)]
#[CoversClass(PaperDatasetManifestCodec::class)]
#[CoversClass(PaperDatasetVerifier::class)]
#[CoversClass(PaperMarketEvent::class)]
#[CoversClass(CanonicalJson::class)]
final class PaperDatasetRecorderTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-dataset-test-');
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

    public function testFirstAppendWritesCanonicalLineAndReplayIsIdempotent(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $event = $this->event(sequence: '1');

        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($event));

        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        self::assertSame(CanonicalJson::encode($event->toArray()) . "\n", file_get_contents($eventsPath));
        self::assertDirectoryExists($this->datasetDirectory() . '/checkpoints');
        self::assertFileExists($this->datasetDirectory() . '/manifest.json');
        self::assertSame(0600, fileperms($eventsPath) & 0777);
        self::assertSame(0600, fileperms($this->datasetDirectory() . '/manifest.json') & 0777);
        self::assertSame(0700, fileperms($this->datasetRoot()) & 0777);
        self::assertSame(0700, fileperms($this->datasetDirectory()) & 0777);
        self::assertSame(0700, fileperms($this->datasetDirectory() . '/checkpoints') & 0777);

        $size = filesize($eventsPath);
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $recorder->append($event));
        self::assertSame($size, filesize($eventsPath));
        self::assertSame(1, $recorder->manifest()->eventCount);
    }

    public function testDatasetUsesAnEmptyPrivateDurableTransactionLock(): void
    {
        new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $lockPath = $this->datasetDirectory() . '/.dataset.lock';

        self::assertFileExists($lockPath);
        self::assertSame(0600, fileperms($lockPath) & 0777);
        self::assertSame(0, filesize($lockPath));
    }

    public function testManifestCodecRoundTripsAnEmptySequenceGapMap(): void
    {
        $codec = new PaperDatasetManifestCodec();
        $manifest = $this->manifest();

        self::assertEquals($manifest, $codec->decode($codec->encode($manifest)));
    }

    public function testManifestTimestampValueIsSensitiveAndRedactedFromFullTrace(): void
    {
        $sentinel = 'PAPER_MANIFEST_TIMESTAMP_TRACE_SENTINEL_4d8c2a';
        $manifest = $this->manifest()->toArray();
        $manifest['start_exchange_timestamp'] = $sentinel;
        $json = CanonicalJson::encode($manifest) . "\n";
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);

        try {
            (new PaperDatasetManifestCodec())->decode($json);
            self::fail('An invalid manifest timestamp must fail decoding.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_timestamp_invalid', $exception->getMessage());
            $fullTrace = (string) $exception . "\n" . print_r($exception->getTrace(), true);
            self::assertStringNotContainsString($sentinel, $fullTrace);
            self::assertStringNotContainsString($json, $fullTrace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }

        $parameter = new \ReflectionParameter([PaperDatasetManifestCodec::class, 'parseTimestamp'], 'value');
        self::assertNotEmpty($parameter->getAttributes(\SensitiveParameter::class));
    }

    public function testDuplicateIdentityWithDifferentPayloadIsRejected(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $event = $this->event(sequence: '1');
        $recorder->append($event);

        $conflicting = $event->toArray();
        $conflicting['payload'] = ['ask' => '30002.0', 'bid' => '29999.0'];
        $conflicting['payload_hash'] = hash('sha256', CanonicalJson::encode($conflicting['payload']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('market_event_identity_conflict');

        $recorder->append(PaperMarketEvent::fromArray($conflicting));
    }

    public function testDuplicateIdentityWithDifferentReceiptMetadataIsNotAnExactReplay(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $event = $this->event(sequence: '1');
        $recorder->append($event);
        $conflicting = $event->toArray();
        $conflicting['received_timestamp'] = '2026-07-19T10:00:09.000000Z';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('market_event_identity_conflict');

        $recorder->append(PaperMarketEvent::fromArray($conflicting));
    }

    public function testSequenceRegressionWithinChannelIsRejected(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '2'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('market_event_out_of_order');

        $recorder->append($this->event(sequence: '1', microseconds: 2));
    }

    public function testForwardGapIsCountedAndChannelsHaveIndependentSequences(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());

        $recorder->append($this->event(sequence: '1'));
        $recorder->append($this->event(sequence: '3', microseconds: 2));
        self::assertSame(
            ['okx/BTCUSDT/top_of_book' => 1],
            $recorder->manifest()->sequenceGaps,
        );

        self::assertSame(
            PaperDatasetAppendResult::APPENDED,
            $recorder->append($this->event(
                sequence: '1',
                microseconds: 3,
                channel: PaperMarketDataChannel::PUBLIC_TRADE,
            )),
        );
        self::assertSame(['public_trade', 'top_of_book'], $recorder->manifest()->channels);
    }

    public function testOneHundredTwentyEightDigitSequencesSurviveGapComparisonRestartAndVerification(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $lower = '4' . str_repeat('9', 127);
        $first = '5' . str_repeat('0', 127);
        $gap = '5' . str_repeat('0', 126) . '2';
        $next = '5' . str_repeat('0', 126) . '3';

        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($this->event(sequence: $first)));
        try {
            $recorder->append($this->event(sequence: $lower, microseconds: 2));
            self::fail('A lower 128-digit sequence must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_out_of_order', $exception->getMessage());
        }

        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($this->event(sequence: $gap, microseconds: 3)));
        self::assertSame(['okx/BTCUSDT/top_of_book' => 1], $recorder->manifest()->sequenceGaps);

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $restarted->append($this->event(sequence: $gap, microseconds: 3)));
        self::assertSame(PaperDatasetAppendResult::APPENDED, $restarted->append($this->event(sequence: $next, microseconds: 4)));
        $completed = $restarted->complete();

        self::assertEquals($completed, (new PaperDatasetVerifier())->verify($this->datasetDirectory()));
        self::assertSame(['okx/BTCUSDT/top_of_book' => 1], $completed->sequenceGaps);
    }

    public function testRestartRebuildsIdentitySequenceAndReconcilesStaleRecordingManifest(): void
    {
        $initialManifest = $this->manifest();
        $first = new PaperDatasetRecorder($this->datasetRoot(), $initialManifest);
        $event = $this->event(sequence: '1');
        $first->append($event);

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $initialManifest);

        self::assertSame(1, $restarted->manifest()->eventCount);
        self::assertSame($event->eventId, $restarted->manifest()->lastEventId);
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $restarted->append($event));
        self::assertSame(
            PaperDatasetAppendResult::APPENDED,
            $restarted->append($this->event(sequence: '2', microseconds: 2)),
        );
    }

    public function testRecorderRetainsItsDurableByteOffsetAcrossAppends(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $offset = new \ReflectionProperty(PaperDatasetRecorder::class, 'scannedBytes');
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';

        $recorder->append($this->event(sequence: '1'));
        self::assertSame(filesize($eventsPath), $offset->getValue($recorder));

        $recorder->append($this->event(sequence: '2', microseconds: 2));
        self::assertSame(filesize($eventsPath), $offset->getValue($recorder));
    }

    public function testRecorderFailsClosedWhenTheDurableFileRegressesBehindItsOffset(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1'));
        $handle = fopen($this->datasetDirectory() . '/events.ndjson', 'r+b');
        self::assertIsResource($handle);
        self::assertTrue(ftruncate($handle, 0));
        fclose($handle);

        try {
            $recorder->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A durable size regression must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_size_regressed', $exception->getMessage());
        }

        $this->assertRecorderUnusable($recorder);
    }

    #[DataProvider('sameLengthEventsMutationProvider')]
    public function testRecorderRejectsSameLengthEventsMutationAndPoisonsItsCachedState(bool $replaceFile): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $original = file_get_contents($eventsPath);
        $replacement = CanonicalJson::encode($this->event(sequence: '9', microseconds: 9)->toArray()) . "\n";
        self::assertIsString($original);
        self::assertNotSame($original, $replacement);
        self::assertSame(strlen($original), strlen($replacement));

        if ($replaceFile) {
            $replacementPath = $this->testRoot . '/same-length-events.ndjson';
            self::assertSame(strlen($replacement), file_put_contents($replacementPath, $replacement));
            self::assertTrue(chmod($replacementPath, 0600));
            self::assertTrue(rename($eventsPath, $eventsPath . '.original'));
            self::assertTrue(rename($replacementPath, $eventsPath));
            $expectedError = 'paper_dataset_file_changed';
        } else {
            self::assertSame(strlen($replacement), file_put_contents($eventsPath, $replacement));
            $expectedError = 'paper_dataset_events_prefix_changed';
        }

        try {
            $recorder->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A same-length mutation must invalidate the durable scan cache.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedError, $exception->getMessage());
        }

        $this->assertRecorderUnusable($recorder);
    }

    /** @return iterable<string, array{bool}> */
    public static function sameLengthEventsMutationProvider(): iterable
    {
        yield 'regular-file replacement' => [true];
        yield 'same-inode overwrite' => [false];
    }

    public function testZeroTailValidatesThatTheOpenedDescriptorStillMatchesThePathBeforeReturning(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $event = $this->event(sequence: '1');
        $recorder->append($event);
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $replacementPath = $this->testRoot . '/zero-tail-events.ndjson';
        $contents = file_get_contents($eventsPath);
        self::assertIsString($contents);
        self::assertSame(strlen($contents), file_put_contents($replacementPath, $contents));
        self::assertTrue(chmod($replacementPath, 0600));
        $filesystem->swapEventsPathOnTailValidation($eventsPath, $replacementPath);

        try {
            $recorder->append($event);
            self::fail('A zero-tail scan must validate handle/path identity before returning.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_file_changed', $exception->getMessage());
        }

        $this->assertRecorderUnusable($recorder);
    }

    public function testStaleRecorderRejectsATruncatedDurableTail(): void
    {
        $manifest = $this->manifest();
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $writer = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $stale->append($this->event(sequence: '1'));
        $writer->append($this->event(sequence: '2', microseconds: 2));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $contents = file_get_contents($eventsPath);
        self::assertIsString($contents);
        self::assertSame("\n", substr($contents, -1));
        file_put_contents($eventsPath, substr($contents, 0, -1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_event_line_truncated');

        $stale->append($this->event(sequence: '3', microseconds: 3));
    }

    public function testValidThenTruncatedTailDoesNotPublishGhostStateAndCanBeRetried(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $first = $this->event(sequence: '1');
        $second = $this->event(sequence: '2', microseconds: 2);
        $third = $this->event(sequence: '3', microseconds: 3);
        $recorder->append($first);
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $durablePrefix = file_get_contents($eventsPath);
        self::assertIsString($durablePrefix);
        $secondLine = CanonicalJson::encode($second->toArray()) . "\n";
        $truncatedThird = CanonicalJson::encode($third->toArray());
        self::assertSame(
            strlen($secondLine . $truncatedThird),
            file_put_contents($eventsPath, $secondLine . $truncatedThird, FILE_APPEND),
        );
        $cachedState = $this->recorderScanState($recorder);

        try {
            $recorder->append($this->event(sequence: '4', microseconds: 4));
            self::fail('A truncated tail must fail before candidate scan state is published.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_event_line_truncated', $exception->getMessage());
        }

        self::assertEquals($cachedState, $this->recorderScanState($recorder));
        self::assertSame(
            strlen($durablePrefix . $secondLine),
            file_put_contents($eventsPath, $durablePrefix . $secondLine),
        );
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($third));
        self::assertSame(3, $recorder->manifest()->eventCount);
    }

    public function testValidThenReadFailureTailDoesNotPublishGhostStateAndCanBeRetried(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest, filesystem: $filesystem);
        $first = $this->event(sequence: '1');
        $second = $this->event(sequence: '2', microseconds: 2);
        $third = $this->event(sequence: '3', microseconds: 3);
        $fourth = $this->event(sequence: '4', microseconds: 4);
        $stale->append($first);
        $writer = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $writer->append($second);
        $writer->append($third);
        $cachedState = $this->recorderScanState($stale);
        $filesystem->failTailReadAfterLines(1);

        try {
            $stale->append($fourth);
            self::fail('A tail read failure must fail before candidate scan state is published.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_read_failed', $exception->getMessage());
        }

        self::assertEquals($cachedState, $this->recorderScanState($stale));
        self::assertSame(PaperDatasetAppendResult::APPENDED, $stale->append($fourth));
        self::assertSame(4, $stale->manifest()->eventCount);
    }

    public function testTailMutationAfterParsingBeforeFinalRehashFailsClosedAndPoisonsRecorder(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest, filesystem: $filesystem);
        $first = $this->event(sequence: '1');
        $second = $this->event(sequence: '2', microseconds: 2);
        $stale->append($first);
        $writer = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $writer->append($second);

        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $replacement = CanonicalJson::encode($this->event(sequence: '7', microseconds: 7)->toArray()) . "\n"
            . CanonicalJson::encode($this->event(sequence: '8', microseconds: 8)->toArray()) . "\n";
        self::assertSame(filesize($eventsPath), strlen($replacement));
        $cachedState = $this->recorderScanState($stale);
        $filesystem->overwriteEventsAfterTailParsing($eventsPath, $replacement);

        try {
            $stale->append($this->event(sequence: '3', microseconds: 3));
            self::fail('A same-inode same-size mutation after parsing must invalidate the scan snapshot.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_snapshot_changed', $exception->getMessage());
        }

        self::assertEquals($cachedState, $this->recorderScanState($stale));
        $this->assertRecorderUnusable($stale);
    }

    public function testAppendRejectsValidSameLengthMutationAfterReloadBeforeDurableAppend(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorded = $this->event(sequence: '1');
        $recorder->append($recorded);
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $eventsBefore = file_get_contents($eventsPath);
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($eventsBefore);
        self::assertIsString($manifestBefore);
        $replacement = $this->sameLengthValidReplacement($recorded);
        self::assertSame(strlen($eventsBefore), strlen($replacement));
        $filesystem->overwriteEventsBeforePublication($eventsPath, $replacement, 'append');

        try {
            $recorder->append($this->event(sequence: '2', microseconds: 2));
            self::fail('Append must reject a valid same-length event mutation after durable reload.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_snapshot_changed', $exception->getMessage());
        }

        self::assertSame($replacement, file_get_contents($eventsPath));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        self::assertSame(
            PaperDatasetState::RECORDING,
            (new PaperDatasetManifestCodec())->decode($manifestBefore)->state,
        );
        $this->assertRecorderUnusable($recorder);
    }

    public function testAppendRejectsValidSameLengthMutationOfOnlyNewLineBeforeDigestPublication(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $durablePrefix = file_get_contents($eventsPath);
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($durablePrefix);
        self::assertIsString($manifestBefore);

        $requested = $this->event(sequence: '2', microseconds: 2);
        $canonicalLine = CanonicalJson::encode($requested->toArray()) . "\n";
        $replacementLine = $this->sameLengthValidReplacement($requested);
        self::assertSame(strlen($canonicalLine), strlen($replacementLine));
        $replacementData = json_decode($replacementLine, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($replacementData);
        $replacement = PaperMarketEvent::fromArray($replacementData);
        self::assertSame($requested->eventId, $replacement->eventId);
        self::assertSame($requested->sequence, $replacement->sequence);
        self::assertEquals($requested->exchangeTimestamp, $replacement->exchangeTimestamp);
        self::assertEquals($requested->receivedTimestamp, $replacement->receivedTimestamp);
        self::assertNotSame($requested->payload, $replacement->payload);
        self::assertNotSame($requested->payloadHash, $replacement->payloadHash);

        $filesystem->overwriteAppendedLineBeforePublication(
            $eventsPath,
            strlen($durablePrefix),
            $replacementLine,
        );

        try {
            $recorder->append($requested);
            self::fail('Append must reject replacement of only the newly appended canonical line.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_snapshot_changed', $exception->getMessage());
        }

        self::assertSame($durablePrefix, file_get_contents($eventsPath));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        self::assertSame(PaperDatasetState::RECORDING, $recorder->manifest()->state);
        $this->assertRecorderUnusable($recorder);
    }

    public function testAppendHasNoMutablePublicationBoundaryAfterFinalRehash(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $durablePrefix = file_get_contents($eventsPath);
        self::assertIsString($durablePrefix);

        $requested = $this->event(sequence: '2', microseconds: 2);
        $canonicalLine = CanonicalJson::encode($requested->toArray()) . "\n";
        $replacementLine = $this->sameLengthValidReplacement($requested);
        self::assertSame(strlen($canonicalLine), strlen($replacementLine));
        $filesystem->overwriteAppendedLineOnSnapshotValidation(
            $eventsPath,
            strlen($durablePrefix),
            $replacementLine,
            validationSeek: 3,
        );

        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($requested));
        self::assertSame($durablePrefix . $canonicalLine, file_get_contents($eventsPath));
    }

    public function testAppendFinalRehashRejectsNewLineMutationAfterFirstFullHash(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $durablePrefix = file_get_contents($eventsPath);
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($durablePrefix);
        self::assertIsString($manifestBefore);

        $requested = $this->event(sequence: '2', microseconds: 2);
        $replacementLine = $this->sameLengthValidReplacement($requested);
        $filesystem->overwriteAppendedLineOnSnapshotValidation(
            $eventsPath,
            strlen($durablePrefix),
            $replacementLine,
            validationSeek: 2,
        );

        try {
            $recorder->append($requested);
            self::fail('The final full rehash must reject a new-line mutation after the first full hash.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_snapshot_changed', $exception->getMessage());
        }

        self::assertSame($durablePrefix, file_get_contents($eventsPath));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        $this->assertRecorderUnusable($recorder);
    }

    #[DataProvider('finalizationMethodProvider')]
    public function testFinalizationRejectsValidSameLengthMutationAfterReloadBeforeChecksum(
        string $finalizationMethod,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorded = $this->event(sequence: '1');
        $recorder->append($recorded);
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $eventsBefore = file_get_contents($eventsPath);
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($eventsBefore);
        self::assertIsString($manifestBefore);
        $replacement = $this->sameLengthValidReplacement($recorded);
        self::assertSame(strlen($eventsBefore), strlen($replacement));
        $filesystem->overwriteEventsBeforePublication($eventsPath, $replacement, 'finalize');

        try {
            $recorder->{$finalizationMethod}();
            self::fail('Finalization must reject a valid same-length event mutation before checksum.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                $finalizationMethod === 'complete'
                    ? 'paper_dataset_complete_failed'
                    : 'paper_dataset_mark_incomplete_failed',
                $exception->getMessage(),
            );
            self::assertSame('paper_dataset_events_snapshot_changed', $exception->getPrevious()?->getMessage());
        }

        self::assertSame($replacement, file_get_contents($eventsPath));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        self::assertSame(PaperDatasetState::RECORDING, $recorder->manifest()->state);
        self::assertSame(
            PaperDatasetState::RECORDING,
            (new PaperDatasetManifestCodec())->decode($manifestBefore)->state,
        );
        $this->assertRecorderUnusable($recorder);
    }

    public function testRestartRejectsAValidLastEventLineWithoutItsDurableNewline(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $contents = file_get_contents($this->datasetDirectory() . '/events.ndjson');
        self::assertIsString($contents);
        file_put_contents($this->datasetDirectory() . '/events.ndjson', rtrim($contents, "\n"));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_event_line_truncated');

        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
    }

    public function testRestartRecoversOnlyAnExactStagedAppendPrefix(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $committed = file_get_contents($eventsPath);
        self::assertIsString($committed);
        $second = $this->event(sequence: '2', microseconds: 2);
        $line = CanonicalJson::encode($second->toArray()) . "\n";
        $partial = substr($line, 0, -17);
        self::assertNotSame('', $partial);
        $this->stageAppendIntent($manifest, $second, $committed, $line);
        self::assertSame(strlen($partial), file_put_contents($eventsPath, $partial, FILE_APPEND));

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);

        self::assertSame($committed, file_get_contents($eventsPath));
        self::assertFileDoesNotExist($this->appendIntentPath());
        self::assertSame(1, $restarted->manifest()->eventCount);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $restarted->append($second));
    }

    public function testAppendFsyncsAPrivateFixedIntentBeforeWritingEventsAndRemovesItDurably(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $event = $this->event(sequence: '1');
        $line = CanonicalJson::encode($event->toArray()) . "\n";

        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($event));

        self::assertSame(1, $filesystem->appendIntentSyncs);
        self::assertSame(2, $filesystem->appendIntentDirectorySyncs);
        self::assertSame(0600, $filesystem->observedAppendIntentMode);
        self::assertSame([
            'version' => 1,
            'dataset_id' => $manifest->datasetId,
            'event_id' => $event->eventId,
            'original_events_bytes' => 0,
            'original_events_sha256' => hash('sha256', ''),
            'canonical_line_base64' => base64_encode($line),
            'canonical_line_sha256' => hash('sha256', $line),
        ], $filesystem->observedAppendIntent);
        self::assertFileDoesNotExist($this->appendIntentPath());
    }

    public function testRestartPreservesAnExactCompleteStagedAppend(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $committed = file_get_contents($eventsPath);
        self::assertIsString($committed);
        $second = $this->event(sequence: '2', microseconds: 2);
        $line = CanonicalJson::encode($second->toArray()) . "\n";
        $this->stageAppendIntent($manifest, $second, $committed, $line);
        self::assertSame(strlen($line), file_put_contents($eventsPath, $line, FILE_APPEND));

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);

        self::assertSame($committed . $line, file_get_contents($eventsPath));
        self::assertFileDoesNotExist($this->appendIntentPath());
        self::assertSame(2, $restarted->manifest()->eventCount);
        self::assertSame($second->eventId, $restarted->manifest()->lastEventId);
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $restarted->append($second));
    }

    public function testRestartFsyncsACompleteStagedAppendBeforeRemovingItsIntent(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $committed = file_get_contents($eventsPath);
        self::assertIsString($committed);
        $second = $this->event(sequence: '2', microseconds: 2);
        $line = CanonicalJson::encode($second->toArray()) . "\n";
        $this->stageAppendIntent($manifest, $second, $committed, $line);
        self::assertSame(strlen($line), file_put_contents($eventsPath, $line, FILE_APPEND));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->failNextAppendRecoverySync();

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest, filesystem: $filesystem);
            self::fail('A complete recovered line must be fsynced before its intent is removed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_append_intent_invalid', $exception->getMessage());
            self::assertSame('paper_dataset_append_recovery_failed', $exception->getPrevious()?->getMessage());
        }

        self::assertSame(1, $filesystem->appendRecoverySyncs);
        self::assertFileExists($this->appendIntentPath());
        self::assertSame($committed . $line, file_get_contents($eventsPath));
    }

    public function testRestartRevalidatesACompleteStagedAppendAfterItsFsync(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $committed = file_get_contents($eventsPath);
        self::assertIsString($committed);
        $second = $this->event(sequence: '2', microseconds: 2);
        $line = CanonicalJson::encode($second->toArray()) . "\n";
        $mutatedLine = str_replace('"sequence":"2"', '"sequence":"3"', $line);
        self::assertSame(strlen($line), strlen($mutatedLine));
        self::assertNotSame($line, $mutatedLine);
        $this->stageAppendIntent($manifest, $second, $committed, $line);
        self::assertSame(strlen($line), file_put_contents($eventsPath, $line, FILE_APPEND));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->overwriteEventsAfterAppendRecoverySync($eventsPath, $committed . $mutatedLine);

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest, filesystem: $filesystem);
            self::fail('A recovered full line changed after fsync must retain its durable intent.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_append_intent_invalid', $exception->getMessage());
        }

        self::assertSame(1, $filesystem->appendRecoverySyncs);
        self::assertFileExists($this->appendIntentPath());
        self::assertSame($committed . $mutatedLine, file_get_contents($eventsPath));
    }

    public function testRestartRejectsANonMatchingStagedAppendSuffixWithoutMutation(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $committed = file_get_contents($eventsPath);
        self::assertIsString($committed);
        $second = $this->event(sequence: '2', microseconds: 2);
        $line = CanonicalJson::encode($second->toArray()) . "\n";
        $this->stageAppendIntent($manifest, $second, $committed, $line);
        self::assertSame(8, file_put_contents($eventsPath, '{"wrong"', FILE_APPEND));
        $eventsBefore = file_get_contents($eventsPath);
        $markerBefore = file_get_contents($this->appendIntentPath());

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('Only an exact prefix of the marker-bound canonical line may be recovered.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_append_intent_invalid', $exception->getMessage());
        }

        self::assertSame($eventsBefore, file_get_contents($eventsPath));
        self::assertSame($markerBefore, file_get_contents($this->appendIntentPath()));
    }

    #[DataProvider('fixedArtifactWriteFailureProvider')]
    public function testFixedArtifactsNeverExposePartialAuthoritativeContents(
        string $artifact,
        string $failure,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $filesystem->failNextFixedArtifactPublication($artifact, $failure);

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail(sprintf('The %s %s failure must interrupt publication.', $artifact, $failure));
        } catch (\RuntimeException) {
        }

        $authoritative = $this->fixedArtifactPath($artifact);
        $staging = $authoritative . '.staging';
        self::assertFileDoesNotExist($authoritative);
        self::assertFileExists($staging);
        $mode = fileperms($staging);
        self::assertIsInt($mode);
        self::assertSame(0600, $mode & 0777);

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);

        self::assertFileDoesNotExist($staging);
        self::assertSame(
            $artifact === 'append_intent' ? 0 : 1,
            $restarted->manifest()->eventCount,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function fixedArtifactWriteFailureProvider(): iterable
    {
        foreach (['append_intent', 'manifest_transition', 'manifest_backup', 'manifest_candidate'] as $artifact) {
            foreach (['short_write', 'flush', 'sync'] as $failure) {
                yield $artifact . ' ' . $failure => [$artifact, $failure];
            }
        }
    }

    #[DataProvider('fixedArtifactCrashBoundaryProvider')]
    public function testFixedArtifactCrashBoundariesRecoverWithoutPartialAuthority(
        string $artifact,
        string $boundary,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $publicationsBefore = $filesystem->fixedArtifactPublications[$artifact] ?? 0;
        $filesystem->failNextFixedArtifactPublication($artifact, $boundary);

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail(sprintf('The %s %s crash boundary must interrupt publication.', $artifact, $boundary));
        } catch (\RuntimeException $exception) {
            self::assertSame(
                $artifact === 'append_intent'
                    ? ($boundary === 'after_directory_sync'
                        ? 'paper_dataset_append_intent_directory_sync_failed'
                        : 'paper_dataset_append_intent_flush_failed')
                    : 'paper_dataset_manifest_write_failed',
                $exception->getMessage(),
            );
        }

        $authoritative = $this->fixedArtifactPath($artifact);
        $staging = $authoritative . '.staging';
        self::assertSame(
            $publicationsBefore + 1,
            $filesystem->fixedArtifactPublications[$artifact] ?? 0,
        );
        if ($boundary === 'before_rename') {
            self::assertFileDoesNotExist($authoritative);
            self::assertFileExists($staging);
        } else {
            self::assertFileExists($authoritative);
            self::assertFileDoesNotExist($staging);
        }

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);

        self::assertFileDoesNotExist($staging);
        self::assertSame(
            $artifact === 'append_intent' ? 0 : 1,
            $restarted->manifest()->eventCount,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function fixedArtifactCrashBoundaryProvider(): iterable
    {
        foreach (['append_intent', 'manifest_transition', 'manifest_backup', 'manifest_candidate'] as $artifact) {
            foreach (['before_rename', 'after_rename', 'after_directory_sync'] as $boundary) {
                yield $artifact . ' ' . $boundary => [$artifact, $boundary];
            }
        }
    }

    #[DataProvider('fixedArtifactProvider')]
    public function testLoneFixedArtifactStagingIsDiscardedWithoutParsing(string $artifact): void
    {
        $manifest = $this->manifest();
        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $staging = $this->fixedArtifactPath($artifact) . '.staging';
        self::assertSame(8, file_put_contents($staging, 'not-json'));
        self::assertTrue(chmod($staging, 0600));

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);

        self::assertSame(PaperDatasetState::RECORDING, $restarted->manifest()->state);
        self::assertFileDoesNotExist($staging);
    }

    #[DataProvider('fixedArtifactProvider')]
    public function testFixedArtifactStagingAndAuthorityCollisionFailsClosed(string $artifact): void
    {
        $manifest = $this->manifest();
        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $authoritative = $this->fixedArtifactPath($artifact);
        $staging = $authoritative . '.staging';
        foreach ([$authoritative, $staging] as $path) {
            self::assertSame(8, file_put_contents($path, 'not-json'));
            self::assertTrue(chmod($path, 0600));
        }

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('A fixed staging/authority collision must fail before parsing either artifact.');
        } catch (\RuntimeException $exception) {
            self::assertContains($exception->getMessage(), [
                'paper_dataset_append_intent_invalid',
                'paper_dataset_manifest_transition_invalid',
            ]);
        }

        self::assertSame('not-json', file_get_contents($authoritative));
        self::assertSame('not-json', file_get_contents($staging));
    }

    /** @return iterable<string, array{string}> */
    public static function fixedArtifactProvider(): iterable
    {
        yield 'append intent' => ['append_intent'];
        yield 'manifest transition' => ['manifest_transition'];
        yield 'manifest backup' => ['manifest_backup'];
        yield 'manifest candidate' => ['manifest_candidate'];
    }

    public function testRestartRejectsAnOversizedAppendIntentBeforeReadingItsContents(): void
    {
        $manifest = $this->manifest();
        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $handle = fopen($this->appendIntentPath(), 'x+b');
        self::assertIsResource($handle);
        try {
            self::assertTrue(ftruncate($handle, 9_000_001));
            self::assertTrue(fflush($handle));
        } finally {
            fclose($handle);
        }
        self::assertTrue(chmod($this->appendIntentPath(), 0600));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest, filesystem: $filesystem);
            self::fail('The append intent bound must be checked before allocating its contents.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_append_intent_invalid', $exception->getMessage());
        }

        self::assertSame(0, $filesystem->appendIntentReads);
        self::assertSame(9_000_001, filesize($this->appendIntentPath()));
    }

    public function testFailedAppendRollbackRetainsIntentForExactRestartRecovery(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $event = $this->event(sequence: '1');
        $filesystem->failNextAppend('partial_write');
        $filesystem->failRollback('truncate');

        try {
            $recorder->append($event);
            self::fail('The injected append rollback failure must leave durable recovery intent.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_rollback_failed', $exception->getMessage());
        }

        self::assertFileExists($this->appendIntentPath());
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $partial = file_get_contents($eventsPath);
        self::assertIsString($partial);
        self::assertNotSame('', $partial);
        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame('', file_get_contents($eventsPath));
        self::assertFileDoesNotExist($this->appendIntentPath());
        self::assertSame(PaperDatasetAppendResult::APPENDED, $restarted->append($event));
    }

    #[DataProvider('mutationMethodProvider')]
    public function testEveryStaleRecorderMutationRecoversTheFixedAppendIntent(string $method): void
    {
        $manifest = $this->manifest();
        $writer = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $writer->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $committed = file_get_contents($eventsPath);
        self::assertIsString($committed);
        $second = $this->event(sequence: '2', microseconds: 2);
        $line = CanonicalJson::encode($second->toArray()) . "\n";
        $partial = substr($line, 0, -17);
        $this->stageAppendIntent($manifest, $second, $committed, $line);
        self::assertSame(strlen($partial), file_put_contents($eventsPath, $partial, FILE_APPEND));

        if ($method === 'append') {
            self::assertSame(PaperDatasetAppendResult::APPENDED, $stale->append($second));
            self::assertSame($committed . $line, file_get_contents($eventsPath));
        } else {
            $finalized = $stale->{$method}();
            self::assertNotSame(PaperDatasetState::RECORDING, $finalized->state);
            self::assertSame($committed, file_get_contents($eventsPath));
        }
        self::assertFileDoesNotExist($this->appendIntentPath());
    }

    /** @return iterable<string, array{string}> */
    public static function mutationMethodProvider(): iterable
    {
        yield 'append' => ['append'];
        yield 'complete' => ['complete'];
        yield 'mark incomplete' => ['markIncomplete'];
    }

    public function testExistingDatasetRejectsMissingEventsFileWithoutRecreatingIt(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        self::assertTrue(unlink($eventsPath));

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('An existing dataset must not recreate a missing events file.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_missing', $exception->getMessage());
        }

        self::assertFileDoesNotExist($eventsPath);
    }

    public function testCompleteStoresChecksumFreezesDatasetAndVerifiesIt(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $event = $this->event(sequence: '1');
        $recorder->append($event);

        $completed = $recorder->complete();

        self::assertSame(PaperDatasetState::COMPLETE, $completed->state);
        self::assertSame(hash_file('sha256', $this->datasetDirectory() . '/events.ndjson'), $completed->eventsFileSha256);
        self::assertEquals($event->exchangeTimestamp, $completed->endExchangeTimestamp);
        self::assertEquals($completed, (new PaperDatasetVerifier())->verify($this->datasetDirectory()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_not_recording');
        $recorder->append($this->event(sequence: '2', microseconds: 2));
    }

    public function testStaleRecorderCannotAppendAfterAnotherInstanceCompletes(): void
    {
        $manifest = $this->manifest();
        $first = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $first->append($this->event(sequence: '1'));
        $first->complete();
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $eventsBefore = file_get_contents($eventsPath);
        $manifestBefore = file_get_contents($manifestPath);

        try {
            $stale->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A stale recorder must observe the complete durable state.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }

        self::assertSame($eventsBefore, file_get_contents($eventsPath));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        $stored = (new PaperDatasetManifestCodec())->decode((string) $manifestBefore);
        self::assertSame(PaperDatasetState::COMPLETE, $stored->state);
        self::assertSame(1, $stored->eventCount);
    }

    public function testStaleRecorderCannotAppendAfterAnotherInstanceMarksIncomplete(): void
    {
        $manifest = $this->manifest();
        $first = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $first->append($this->event(sequence: '1'));
        $first->markIncomplete();
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $eventsBefore = file_get_contents($eventsPath);
        $manifestBefore = file_get_contents($manifestPath);

        try {
            $stale->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A stale recorder must observe the incomplete durable state.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }

        self::assertSame($eventsBefore, file_get_contents($eventsPath));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        $stored = (new PaperDatasetManifestCodec())->decode((string) $manifestBefore);
        self::assertSame(PaperDatasetState::INCOMPLETE, $stored->state);
        self::assertSame(1, $stored->eventCount);
    }

    public function testExistingDatasetDoesNotRecreateAMissingTransactionLock(): void
    {
        $manifest = $this->manifest();
        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $lockPath = $this->datasetDirectory() . '/.dataset.lock';
        self::assertTrue(unlink($lockPath));

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('An existing dataset with no durable lock must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_lock_invalid', $exception->getMessage());
        }

        self::assertFileDoesNotExist($lockPath);
    }

    #[DataProvider('finalizationMethodProvider')]
    public function testTransactionLockReplacementExactlyAtManifestPublicationCannotDowngradeFinalState(
        string $finalizationMethod,
    ): void {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        $manifest = $this->manifest();
        $expectedState = $finalizationMethod === 'complete'
            ? PaperDatasetState::COMPLETE
            : PaperDatasetState::INCOMPLETE;
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $stale = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($sockets[0]);
            $signal = fread($sockets[1], 1);
            if ($signal !== 'G') {
                exit(2);
            }
            fwrite($sockets[1], "A\n");
            fflush($sockets[1]);
            try {
                $winner = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
                $finalized = $finalizationMethod === 'complete'
                    ? $winner->complete()
                    : $winner->markIncomplete();
                fwrite($sockets[1], 'C:' . $finalized->state->value . "\n");
                fflush($sockets[1]);
                exit(0);
            } catch (\Throwable $failure) {
                fwrite($sockets[1], 'E:' . $failure->getMessage() . "\n");
                fflush($sockets[1]);
                exit(3);
            }
        }

        fclose($sockets[1]);
        $publicationTriggered = false;
        $winnerResult = null;
        $childReaped = false;
        $filesystem->replaceLockAtNextManifestPublication(
            $this->datasetDirectory() . '/.dataset.lock',
            function () use ($sockets, &$publicationTriggered, &$winnerResult): void {
                $publicationTriggered = true;
                self::assertSame(1, fwrite($sockets[0], 'G'));
                fflush($sockets[0]);
                self::assertSame("A\n", fgets($sockets[0]));

                $directoryHandle = fopen($this->datasetDirectory(), 'rb');
                self::assertIsResource($directoryHandle);
                $staleHoldsDirectoryLock = !flock($directoryHandle, LOCK_EX | LOCK_NB);
                if (!$staleHoldsDirectoryLock) {
                    self::assertTrue(flock($directoryHandle, LOCK_UN));
                }
                fclose($directoryHandle);

                if (!$staleHoldsDirectoryLock) {
                    $winnerResult = fgets($sockets[0]);
                }
            },
        );

        try {
            try {
                $stale->append($this->event(sequence: '1'));
                self::fail('A recorder holding a replaced lock inode must fail closed at publication.');
            } catch (\RuntimeException $exception) {
                self::assertSame('paper_dataset_file_changed', $exception->getMessage());
            }

            self::assertTrue($publicationTriggered, 'The interleaving must run at manifest publication.');
            $winnerResult ??= fgets($sockets[0]);
            self::assertSame('C:' . $expectedState->value . "\n", $winnerResult);
            self::assertSame($pid, pcntl_waitpid($pid, $childStatus));
            $childReaped = true;
            self::assertTrue(pcntl_wifexited($childStatus));
            self::assertSame(0, pcntl_wexitstatus($childStatus));

            $storedJson = file_get_contents($this->datasetDirectory() . '/manifest.json');
            self::assertIsString($storedJson);
            $stored = (new PaperDatasetManifestCodec())->decode($storedJson);
            self::assertSame($expectedState, $stored->state);
            self::assertSame(1, $stored->eventCount);
            if ($expectedState === PaperDatasetState::COMPLETE) {
                self::assertEquals($stored, (new PaperDatasetVerifier())->verify($this->datasetDirectory()));
            } else {
                $reopened = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
                self::assertSame(PaperDatasetState::INCOMPLETE, $reopened->manifest()->state);
            }
        } finally {
            if (!$publicationTriggered) {
                fwrite($sockets[0], 'G');
                fflush($sockets[0]);
            }
            fclose($sockets[0]);
            if (!$childReaped) {
                pcntl_waitpid($pid, $childStatus);
            }
        }
    }

    #[DataProvider('manifestTemporaryMutationProvider')]
    public function testManifestTemporaryMutationAtPublicationRetainsPublishedAndBackupEvidence(
        bool $substituteInode,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        $filesystem->mutateManifestTemporaryAfterValidation(
            $manifestPath,
            '"recorder_version":"1.0.0"',
            '"recorder_version":"9.9.9"',
            $substituteInode,
        );

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('A manifest temporary changed at publication must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        $published = file_get_contents($manifestPath);
        self::assertIsString($published);
        self::assertStringContainsString('"recorder_version":"9.9.9"', $published);
        self::assertSame(
            PaperDatasetState::RECORDING,
            (new PaperDatasetManifestCodec())->decode($published)->state,
        );
        $recoveries = glob($this->datasetDirectory() . '/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertCount(1, $recoveries);
        self::assertSame($manifestBefore, file_get_contents($recoveries[0]));
        self::assertSame(0600, fileperms($recoveries[0]) & 0777);
        $this->assertRecorderUnusable($recorder);
    }

    /** @return iterable<string, array{bool}> */
    public static function manifestTemporaryMutationProvider(): iterable
    {
        yield 'same inode and size' => [false];
        yield 'substituted inode with same size' => [true];
    }

    public function testManifestBackupDirectorySyncFailureAbortsBeforePublication(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        $publicationsBefore = $filesystem->manifestPublications;
        $filesystem->failNextManifestBackupDirectorySync();

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('A backup directory sync failure must abort before manifest publication.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        self::assertSame($publicationsBefore, $filesystem->manifestPublications);
        self::assertSame([
            $this->manifestBackupPath(),
            $this->manifestTransitionPath(),
        ], glob($this->datasetDirectory() . '/.manifest-*'));
        $this->assertRecorderUnusable($recorder);
    }

    #[DataProvider('ambiguousManifestPublicationFailureProvider')]
    public function testManifestPublicationFailureAfterRenameRetainsRecoveryEvidence(string $failure): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        $filesystem->failNextManifestPublicationAfterRename($failure);

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('An ambiguous manifest publication must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        $published = file_get_contents($manifestPath);
        self::assertIsString($published);
        self::assertSame(1, (new PaperDatasetManifestCodec())->decode($published)->eventCount);
        $recoveries = glob($this->datasetDirectory() . '/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertCount(1, $recoveries);
        self::assertSame($manifestBefore, file_get_contents($recoveries[0]));
        self::assertSame(0600, fileperms($recoveries[0]) & 0777);
        $this->assertRecorderUnusable($recorder);
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousManifestPublicationFailureProvider(): iterable
    {
        yield 'move throws after rename' => ['throw'];
        yield 'move returns false after rename' => ['false'];
    }

    #[DataProvider('ambiguousManifestPublicationFailureProvider')]
    public function testReconciliationPublicationAmbiguityPoisonsAppendAndPreservesItsErrorContract(
        string $failure,
    ): void {
        $manifest = $this->manifest();
        $reconciliationFilesystem = new FaultInjectingPaperDatasetFilesystem();
        $stale = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $reconciliationFilesystem,
        );
        $writerFilesystem = new FaultInjectingPaperDatasetFilesystem();
        $writer = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $writerFilesystem,
        );
        $durableEvent = $this->event(sequence: '1');
        $writerFilesystem->failNextManifestBackupDirectorySync();

        try {
            $writer->append($durableEvent);
            self::fail('The setup append must leave an unreconciled durable event.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        self::assertSame(0, (new PaperDatasetManifestCodec())->decode($manifestBefore)->eventCount);
        $reconciliationFilesystem->failNextManifestPublicationAfterRename($failure);

        try {
            $stale->append($this->event(sequence: '2', microseconds: 2));
            self::fail('Ambiguous reconciliation publication must fail the append closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        self::assertSame(0, $stale->manifest()->eventCount);
        $this->assertRecorderUnusable($stale);
        $published = file_get_contents($manifestPath);
        self::assertIsString($published);
        self::assertSame(1, (new PaperDatasetManifestCodec())->decode($published)->eventCount);
        $recoveries = glob($this->datasetDirectory() . '/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertCount(1, $recoveries);
        self::assertSame($manifestBefore, file_get_contents($recoveries[0]));
        self::assertSame(0600, fileperms($recoveries[0]) & 0777);

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame(1, $restarted->manifest()->eventCount);
        self::assertSame(
            PaperDatasetAppendResult::APPENDED,
            $restarted->append($this->event(sequence: '2', microseconds: 2)),
        );
    }

    #[DataProvider('ambiguousMutatedBackupProvider')]
    public function testManifestBackupMutationAfterPublicationAmbiguityIsSurfacedAsUntrustedEvidence(
        bool $substituteInode,
        string $publicationFailure,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        $filesystem->mutateManifestBackupAtPublication(
            '"recorder_version":"1.0.0"',
            '"recorder_version":"8.8.8"',
            $substituteInode,
        );
        $filesystem->failNextManifestPublicationAfterRename($publicationFailure);

        $failure = null;
        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('A changed recovery artifact must fail closed without pathname recovery.');
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('paper_dataset_manifest_write_failed', $failure->getMessage());
        $backupFailure = $failure->getPrevious();
        self::assertInstanceOf(\RuntimeException::class, $backupFailure);
        self::assertSame('paper_dataset_manifest_backup_changed', $backupFailure->getMessage());
        self::assertSame('paper_dataset_file_changed', $backupFailure->getPrevious()?->getMessage());

        $published = file_get_contents($manifestPath);
        self::assertIsString($published);
        self::assertSame(1, (new PaperDatasetManifestCodec())->decode($published)->eventCount);
        $recoveries = glob($this->datasetDirectory() . '/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertNotEmpty($recoveries);
        $mutatedBackup = str_replace(
            '"recorder_version":"1.0.0"',
            '"recorder_version":"8.8.8"',
            $manifestBefore,
        );
        $recoveryContents = [];
        foreach ($recoveries as $recovery) {
            $contents = file_get_contents($recovery);
            self::assertIsString($contents);
            $recoveryContents[] = $contents;
            self::assertSame(0600, fileperms($recovery) & 0777);
        }
        self::assertContains($mutatedBackup, $recoveryContents);
        if ($substituteInode) {
            self::assertContains($manifestBefore, $recoveryContents);
        }
        $this->assertRecorderUnusable($recorder);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function ambiguousMutatedBackupProvider(): iterable
    {
        foreach (self::manifestTemporaryMutationProvider() as $identity => [$substituteInode]) {
            foreach (self::ambiguousManifestPublicationFailureProvider() as $ambiguity => [$failure]) {
                yield $identity . ', ' . $ambiguity => [$substituteInode, $failure];
            }
        }
    }

    public function testStaleRecordingAppendersPreserveEveryEventAndManifestFact(): void
    {
        $manifest = $this->manifest();
        $first = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $second = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $firstEvent = $this->event(sequence: '1');
        $secondEvent = $this->event(sequence: '2', microseconds: 2);

        self::assertSame(PaperDatasetAppendResult::APPENDED, $first->append($firstEvent));
        self::assertSame(PaperDatasetAppendResult::APPENDED, $second->append($secondEvent));

        $manifestJson = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($manifestJson);
        $stored = (new PaperDatasetManifestCodec())->decode($manifestJson);
        self::assertSame(2, $stored->eventCount);
        self::assertSame($secondEvent->eventId, $stored->lastEventId);
        $lines = file($this->datasetDirectory() . '/events.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);

        self::assertSame(PaperDatasetAppendResult::REPLAYED, $first->append($secondEvent));
        self::assertCount(
            2,
            file($this->datasetDirectory() . '/events.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    #[DataProvider('immutableProvenanceProvider')]
    public function testRecordingAndCompleteDatasetsRejectDifferentRequestedProvenance(
        bool $complete,
        PaperMarketDataQuality $storedQuality,
        ?string $storedModelName,
        ?string $storedModelVersion,
        PaperMarketDataQuality $requestedQuality,
        ?string $requestedModelName,
        ?string $requestedModelVersion,
    ): void {
        $storedManifest = $this->manifest($storedQuality, $storedModelName, $storedModelVersion);
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $storedManifest);
        if ($complete) {
            $recorder->append($this->event(sequence: '1'));
            $recorder->complete();
        }
        $eventsBefore = file_get_contents($this->datasetDirectory() . '/events.ndjson');
        $manifestBefore = file_get_contents($this->datasetDirectory() . '/manifest.json');

        try {
            new PaperDatasetRecorder(
                $this->datasetRoot(),
                $this->manifest($requestedQuality, $requestedModelName, $requestedModelVersion),
            );
            self::fail('Dataset provenance must be immutable after creation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_identity_mismatch', $exception->getMessage());
        }

        self::assertSame($eventsBefore, file_get_contents($this->datasetDirectory() . '/events.ndjson'));
        self::assertSame($manifestBefore, file_get_contents($this->datasetDirectory() . '/manifest.json'));
    }

    /**
     * @return iterable<string, array{
     *   bool,
     *   PaperMarketDataQuality,
     *   string|null,
     *   string|null,
     *   PaperMarketDataQuality,
     *   string|null,
     *   string|null
     * }>
     */
    public static function immutableProvenanceProvider(): iterable
    {
        foreach ([false, true] as $complete) {
            $state = $complete ? 'complete' : 'recording';
            yield $state . ' quality' => [
                $complete,
                PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
                null,
                null,
                PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
                'public-model',
                '1.0.0',
            ];
            yield $state . ' model name' => [
                $complete,
                PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
                'public-model',
                '1.0.0',
                PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
                'other-model',
                '1.0.0',
            ];
            yield $state . ' model version' => [
                $complete,
                PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
                'public-model',
                '1.0.0',
                PaperMarketDataQuality::PUBLIC_HISTORICAL_CANDLES_AND_TRADES,
                'public-model',
                '2.0.0',
            ];
        }
    }

    public function testMarkIncompleteDurablyFreezesDatasetAndPreventsReplayVerification(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1'));

        $incomplete = $recorder->markIncomplete();

        self::assertSame(PaperDatasetState::INCOMPLETE, $incomplete->state);
        self::assertSame(PaperMarketDataQuality::INCOMPLETE, $incomplete->quality);
        self::assertSame(hash_file('sha256', $this->datasetDirectory() . '/events.ndjson'), $incomplete->eventsFileSha256);

        try {
            (new PaperDatasetRecorder($this->datasetRoot(), $this->manifest()))->append($this->event(sequence: '1'));
            self::fail('An incomplete dataset must remain frozen after restart.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_not_complete');
        (new PaperDatasetVerifier())->verify($this->datasetDirectory());
    }

    public function testManifestNeverContainsPayloadOrSensitiveFields(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1'));
        $recorder->complete();

        $json = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($json);
        self::assertStringNotContainsString('payload', $json);
        self::assertStringNotContainsString('29999.0', $json);
        self::assertStringNotContainsString('authorization', strtolower($json));

        $decoded = (new PaperDatasetManifestCodec())->decode($json);
        self::assertSame('dataset-okx-001', $decoded->datasetId);
    }

    public function testEmptySymbolsAreRejectedBeforeRecorderArtifactsAreCreated(): void
    {
        try {
            new PaperDatasetRecorder(
                $this->datasetRoot(),
                new PaperDatasetManifest(
                    schemaVersion: 1,
                    recorderVersion: '1.0.0',
                    datasetId: 'dataset-okx-001',
                    venue: PaperMarketDataVenue::OKX,
                    symbols: [],
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
                ),
            );
            self::fail('An empty symbol map must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('paper_dataset_symbols_invalid', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->datasetRoot());
    }

    #[DataProvider('initialFinalStateProvider')]
    public function testFinalStateCannotCreateDatasetArtifacts(PaperDatasetState $state): void
    {
        $manifest = $this->manifest()->finalized(
            state: $state,
            endExchangeTimestamp: $state === PaperDatasetState::COMPLETE
                ? new \DateTimeImmutable('2026-07-19T10:00:00.000001Z')
                : null,
            quality: $state === PaperDatasetState::COMPLETE
                ? PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES
                : PaperMarketDataQuality::INCOMPLETE,
            eventsFileSha256: hash('sha256', ''),
        );

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('A finalized manifest must not create a dataset.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_initial_state_invalid', $exception->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->datasetRoot());
    }

    /** @return iterable<string, array{PaperDatasetState}> */
    public static function initialFinalStateProvider(): iterable
    {
        yield 'complete' => [PaperDatasetState::COMPLETE];
        yield 'incomplete' => [PaperDatasetState::INCOMPLETE];
    }

    #[DataProvider('initialFinalStateProvider')]
    public function testExistingFinalizedDatasetCanBeReopenedButRemainsFrozen(PaperDatasetState $state): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $event = $this->event(sequence: '1');
        $recorder->append($event);
        $finalized = $state === PaperDatasetState::COMPLETE
            ? $recorder->complete()
            : $recorder->markIncomplete();

        $reopened = new PaperDatasetRecorder($this->datasetRoot(), $finalized);
        self::assertSame($state, $reopened->manifest()->state);

        try {
            $reopened->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A reopened finalized dataset must remain frozen.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }
    }

    public function testRecorderRejectsAnIntermediateSymlinkBeforeCreatingRoot(): void
    {
        $real = $this->testRoot . '/real';
        self::assertTrue(mkdir($real, 0700));
        $link = $this->testRoot . '/linked';
        self::assertTrue(symlink($real, $link));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_symlink_rejected');

        new PaperDatasetRecorder($link . '/paper-market-data', $this->manifest());
    }

    public function testRecorderRejectsAnIntermediateSymlinkWhenFinalDirectoryAlreadyExists(): void
    {
        $safe = $this->testRoot . '/safe';
        $outside = $this->testRoot . '/outside';
        self::assertTrue(mkdir($safe, 0700));
        self::assertTrue(mkdir($outside . '/existing', 0700, true));
        self::assertTrue(symlink($outside, $safe . '/link'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_symlink_rejected');

        new PaperDatasetRecorder($safe . '/link/existing', $this->manifest());
    }

    public function testExistingRootWithWrongModeFailsClosedWithoutPathBasedRepair(): void
    {
        $root = $this->testRoot . '/existing-paper-root';
        self::assertTrue(mkdir($root, 0750));
        self::assertTrue(chmod($root, 0750));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();

        try {
            new PaperDatasetRecorder($root, $this->manifest(), filesystem: $filesystem);
            self::fail('An existing directory requiring path-based chmod must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_mode_failed', $exception->getMessage());
        }

        self::assertTrue(is_dir($root));
        self::assertFalse(is_link($root));
        self::assertSame(0750, fileperms($root) & 0777);
        self::assertDirectoryDoesNotExist($root . '/dataset-okx-001');
    }

    #[DataProvider('wrongModeTerminalFileProvider')]
    public function testPreexistingWrongModeTerminalFileFailsClosedWithoutModeRepair(string $terminalFile): void
    {
        self::assertTrue(mkdir($this->datasetDirectory(), 0700, true));
        foreach (['.dataset.lock', 'events.ndjson'] as $path) {
            $fullPath = $this->datasetDirectory() . '/' . $path;
            self::assertSame(0, file_put_contents($fullPath, ''));
            self::assertTrue(chmod($fullPath, $path === $terminalFile ? 0640 : 0600));
        }

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
            self::fail('A preexisting terminal file with a non-private mode must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_file_validation_failed', $exception->getMessage());
        }

        self::assertSame(0640, fileperms($this->datasetDirectory() . '/' . $terminalFile) & 0777);
        self::assertFileDoesNotExist($this->datasetDirectory() . '/manifest.json');
    }

    /** @return iterable<string, array{string}> */
    public static function wrongModeTerminalFileProvider(): iterable
    {
        yield 'events' => ['events.ndjson'];
        yield 'transaction lock' => ['.dataset.lock'];
    }

    #[DataProvider('newTerminalFileSubstitutionProvider')]
    public function testNewTerminalFileSubstitutionBeforeLegacyChmodDoesNotTouchExternalFile(
        string $terminalFile,
    ): void {
        $target = $this->testRoot . '/external-' . ltrim($terminalFile, '.') . '-target';
        self::assertSame(17, file_put_contents($target, 'external-sentinel'));
        self::assertTrue(chmod($target, 0640));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->swapFileAfterValidationBeforeLegacyModeChange(
            $this->datasetDirectory() . '/' . $terminalFile,
            $target,
        );

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $this->manifest(), filesystem: $filesystem);
            self::fail('A substituted new terminal file must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_symlink_rejected', $exception->getMessage());
        }

        self::assertSame('external-sentinel', file_get_contents($target));
        self::assertSame(0640, fileperms($target) & 0777);
    }

    /** @return iterable<string, array{string}> */
    public static function newTerminalFileSubstitutionProvider(): iterable
    {
        yield 'events' => ['events.ndjson'];
        yield 'transaction lock' => ['.dataset.lock'];
    }

    public function testManifestTemporarySubstitutionBeforeLegacyChmodDoesNotTouchExternalFile(): void
    {
        $target = $this->testRoot . '/external-manifest-target.json';
        self::assertSame(17, file_put_contents($target, 'external-sentinel'));
        self::assertTrue(chmod($target, 0640));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->swapManifestTemporaryBeforeLegacyModeChange($this->datasetDirectory(), $target);

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $this->manifest(), filesystem: $filesystem);
            self::fail('A substituted manifest temporary must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_symlink_rejected', $exception->getMessage());
        }

        self::assertSame('external-sentinel', file_get_contents($target));
        self::assertSame(0640, fileperms($target) & 0777);
        self::assertFileDoesNotExist($this->datasetDirectory() . '/manifest.json');
    }

    public function testCreationRejectsPreexistingHardlinkedEventsFileWithoutTouchingExternalInode(): void
    {
        self::assertTrue(mkdir($this->datasetDirectory(), 0700, true));
        $victimPath = $this->testRoot . '/external-events-victim.ndjson';
        self::assertSame(0, file_put_contents($victimPath, ''));
        self::assertTrue(chmod($victimPath, 0640));
        self::assertTrue(link($victimPath, $this->datasetDirectory() . '/events.ndjson'));
        $lockPath = $this->datasetDirectory() . '/.dataset.lock';
        self::assertSame(0, file_put_contents($lockPath, ''));
        self::assertTrue(chmod($lockPath, 0600));

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
            self::fail('A preexisting multi-link events inode must be rejected before chmod or append.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_file_validation_failed', $exception->getMessage());
        }

        self::assertSame('', file_get_contents($victimPath));
        self::assertSame(0640, fileperms($victimPath) & 0777);
        self::assertFileDoesNotExist($this->datasetDirectory() . '/manifest.json');
    }

    public function testAppendRejectsEventsSymlinkSwappedAfterConstructionWithoutTouchingTarget(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $originalEventsPath = $this->datasetDirectory() . '/events.original.ndjson';
        $targetPath = $this->testRoot . '/events-target.ndjson';
        self::assertSame(0, file_put_contents($targetPath, ''));
        self::assertTrue(rename($eventsPath, $originalEventsPath));
        self::assertTrue(symlink($targetPath, $eventsPath));

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('An events file swapped for a symlink must be rejected before append.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_symlink_rejected', $exception->getMessage());
        }

        self::assertSame('', file_get_contents($targetPath));
        self::assertSame('', file_get_contents($originalEventsPath));
    }

    public function testAppendRejectsManifestSymlinkSwappedAfterConstructionWithoutReadingOrReplacingIt(): void
    {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $originalManifestPath = $this->datasetDirectory() . '/manifest.original.json';
        $targetPath = $this->testRoot . '/manifest-target.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        self::assertTrue(copy($manifestPath, $targetPath));
        self::assertTrue(rename($manifestPath, $originalManifestPath));
        self::assertTrue(symlink($targetPath, $manifestPath));

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('A manifest swapped for a symlink must be rejected before it is read or replaced.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_symlink_rejected', $exception->getMessage());
        }

        self::assertTrue(is_link($manifestPath));
        self::assertSame($manifestBefore, file_get_contents($targetPath));
        self::assertSame($manifestBefore, file_get_contents($originalManifestPath));
    }

    public function testAppendRejectsDatasetDirectorySwapDuringTransaction(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $datasetDirectory = $this->datasetDirectory();
        $replacementDirectory = $this->testRoot . '/dataset-replacement';
        self::assertTrue(mkdir($replacementDirectory, 0700));
        self::assertTrue(mkdir($replacementDirectory . '/checkpoints', 0700));
        foreach (['.dataset.lock', 'events.ndjson', 'manifest.json'] as $terminalFile) {
            self::assertTrue(copy(
                $datasetDirectory . '/' . $terminalFile,
                $replacementDirectory . '/' . $terminalFile,
            ));
            self::assertTrue(chmod($replacementDirectory . '/' . $terminalFile, 0600));
        }
        $filesystem->swapDatasetDirectoryDuringEventsScan($datasetDirectory, $replacementDirectory);

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('A dataset directory replacement must fail during the transaction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }

        self::assertSame('', file_get_contents($datasetDirectory . '.directory-original/events.ndjson'));
        $this->assertRecorderUnusable($recorder);
    }

    public function testManifestRecoveryDoesNotMutateSubstitutedDirectoryAndRetainsBackupEvidence(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $datasetDirectory = $this->datasetDirectory();
        $manifestBefore = file_get_contents($datasetDirectory . '/manifest.json');
        self::assertIsString($manifestBefore);
        $replacementDirectory = $this->testRoot . '/dataset-publication-replacement';
        self::assertTrue(mkdir($replacementDirectory, 0700));
        self::assertTrue(mkdir($replacementDirectory . '/checkpoints', 0700));
        $replacementFiles = [
            '.dataset.lock' => 'replacement-lock-sentinel',
            'events.ndjson' => 'replacement-events-sentinel',
            'manifest.json' => 'replacement-manifest-sentinel',
        ];
        foreach ($replacementFiles as $name => $contents) {
            self::assertSame(strlen($contents), file_put_contents($replacementDirectory . '/' . $name, $contents));
            self::assertTrue(chmod($replacementDirectory . '/' . $name, 0600));
        }
        $filesystem->swapDatasetDirectoryAfterManifestPublication($datasetDirectory, $replacementDirectory);

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('A dataset directory replacement after publication must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }

        foreach ($replacementFiles as $name => $contents) {
            self::assertSame($contents, file_get_contents($datasetDirectory . '/' . $name));
        }
        self::assertSame(
            ['.', '..', '.dataset.lock', 'checkpoints', 'events.ndjson', 'manifest.json'],
            scandir($datasetDirectory),
        );
        $recoveries = glob($datasetDirectory . '.directory-original/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertCount(1, $recoveries);
        self::assertSame($manifestBefore, file_get_contents($recoveries[0]));
        self::assertSame(0600, fileperms($recoveries[0]) & 0777);
        $this->assertRecorderUnusable($recorder);
    }

    public function testInitialManifestFailureDoesNotMutateDirectorySwappedAfterRecoveryRevalidation(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $datasetDirectory = $this->datasetDirectory();
        $replacementDirectory = $this->testRoot . '/initial-publication-replacement';
        self::assertTrue(mkdir($replacementDirectory, 0700));
        self::assertTrue(mkdir($replacementDirectory . '/checkpoints', 0700));
        $replacementFiles = [
            '.dataset.lock' => 'replacement-lock-sentinel',
            'events.ndjson' => 'replacement-events-sentinel',
            'manifest.json' => 'replacement-manifest-sentinel',
        ];
        foreach ($replacementFiles as $name => $contents) {
            self::assertSame(strlen($contents), file_put_contents($replacementDirectory . '/' . $name, $contents));
            self::assertTrue(chmod($replacementDirectory . '/' . $name, 0600));
        }
        $filesystem->mutateManifestTemporaryAfterValidation(
            $datasetDirectory . '/manifest.json',
            '"recorder_version":"1.0.0"',
            '"recorder_version":"9.9.9"',
            false,
        );
        $filesystem->swapDatasetDirectoryAfterRecoveryRevalidation(
            $datasetDirectory,
            $replacementDirectory,
        );

        try {
            new PaperDatasetRecorder(
                $this->datasetRoot(),
                $manifest,
                filesystem: $filesystem,
            );
            self::fail('An initial publication directory replacement must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_candidate_changed', $exception->getMessage());
            self::assertSame('paper_dataset_file_changed', $exception->getPrevious()?->getMessage());
        }

        foreach ($replacementFiles as $name => $contents) {
            self::assertSame($contents, file_get_contents($datasetDirectory . '/' . $name));
        }
        self::assertSame(
            ['.', '..', '.dataset.lock', 'checkpoints', 'events.ndjson', 'manifest.json'],
            scandir($datasetDirectory),
        );
        self::assertSame(0700, fileperms($datasetDirectory) & 0777);
        $originalDirectory = $datasetDirectory . '.directory-original';
        self::assertSame(0700, fileperms($originalDirectory) & 0777);
        $published = file_get_contents($originalDirectory . '/manifest.json');
        self::assertIsString($published);
        self::assertStringContainsString('"recorder_version":"9.9.9"', $published);
        self::assertSame(0600, fileperms($originalDirectory . '/manifest.json') & 0777);
    }

    public function testBackupBackedManifestFailureDoesNotMutateDirectorySwappedAfterRecoveryRevalidation(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $datasetDirectory = $this->datasetDirectory();
        $manifestBefore = file_get_contents($datasetDirectory . '/manifest.json');
        self::assertIsString($manifestBefore);
        $replacementDirectory = $this->testRoot . '/existing-publication-replacement';
        self::assertTrue(mkdir($replacementDirectory, 0700));
        self::assertTrue(mkdir($replacementDirectory . '/checkpoints', 0700));
        $replacementFiles = [
            '.dataset.lock' => 'replacement-lock-sentinel',
            'events.ndjson' => 'replacement-events-sentinel',
            'manifest.json' => 'replacement-manifest-sentinel',
        ];
        foreach ($replacementFiles as $name => $contents) {
            self::assertSame(strlen($contents), file_put_contents($replacementDirectory . '/' . $name, $contents));
            self::assertTrue(chmod($replacementDirectory . '/' . $name, 0600));
        }
        $filesystem->mutateManifestTemporaryAfterValidation(
            $datasetDirectory . '/manifest.json',
            '"recorder_version":"1.0.0"',
            '"recorder_version":"9.9.9"',
            false,
        );
        $filesystem->swapDatasetDirectoryAfterRecoveryRevalidation(
            $datasetDirectory,
            $replacementDirectory,
        );

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('An existing manifest publication directory replacement must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        foreach ($replacementFiles as $name => $contents) {
            self::assertSame($contents, file_get_contents($datasetDirectory . '/' . $name));
        }
        self::assertSame(
            ['.', '..', '.dataset.lock', 'checkpoints', 'events.ndjson', 'manifest.json'],
            scandir($datasetDirectory),
        );
        self::assertSame(0700, fileperms($datasetDirectory) & 0777);
        $originalDirectory = $datasetDirectory . '.directory-original';
        self::assertSame(0700, fileperms($originalDirectory) & 0777);
        $published = file_get_contents($originalDirectory . '/manifest.json');
        self::assertIsString($published);
        self::assertStringContainsString('"recorder_version":"9.9.9"', $published);
        self::assertSame(0600, fileperms($originalDirectory . '/manifest.json') & 0777);
        $recoveries = glob($originalDirectory . '/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertCount(1, $recoveries);
        self::assertSame($manifestBefore, file_get_contents($recoveries[0]));
        self::assertSame(0600, fileperms($recoveries[0]) & 0777);
        $this->assertRecorderUnusable($recorder);
    }

    public function testAppendRejectsDatasetDirectoryPermissionDriftDuringLockAcquisition(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $datasetDirectory = $this->datasetDirectory();
        $manifestBefore = file_get_contents($datasetDirectory . '/manifest.json');
        self::assertIsString($manifestBefore);
        $filesystem->changeDirectoryModeOnLockOpen($datasetDirectory, 0750);

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('Directory permission drift during lock acquisition must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_changed', $exception->getMessage());
        }

        self::assertSame('', file_get_contents($datasetDirectory . '/events.ndjson'));
        self::assertSame($manifestBefore, file_get_contents($datasetDirectory . '/manifest.json'));
        $this->assertRecorderUnusable($recorder);
    }

    #[DataProvider('finalizationMethodProvider')]
    public function testFinalizationRejectsEventsSymlinkSwappedAfterConstructionBeforeFinalizingManifest(
        string $finalizationMethod,
    ): void {
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $this->manifest());
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $originalEventsPath = $this->datasetDirectory() . '/events.original.ndjson';
        $targetPath = $this->testRoot . '/events-target.ndjson';
        $eventsBefore = file_get_contents($eventsPath);
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($eventsBefore);
        self::assertIsString($manifestBefore);
        self::assertSame(strlen($eventsBefore), file_put_contents($targetPath, $eventsBefore));
        self::assertTrue(rename($eventsPath, $originalEventsPath));
        self::assertTrue(symlink($targetPath, $eventsPath));

        try {
            if ($finalizationMethod === 'complete') {
                $recorder->complete();
            } else {
                $recorder->markIncomplete();
            }
            self::fail('Finalization must reject an events file swapped for a symlink.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                $finalizationMethod === 'complete'
                    ? 'paper_dataset_complete_failed'
                    : 'paper_dataset_mark_incomplete_failed',
                $exception->getMessage(),
            );
            self::assertSame('paper_dataset_symlink_rejected', $exception->getPrevious()?->getMessage());
        }

        self::assertSame($eventsBefore, file_get_contents($targetPath));
        self::assertSame($eventsBefore, file_get_contents($originalEventsPath));
        self::assertSame($manifestBefore, file_get_contents($manifestPath));
        self::assertSame(PaperDatasetState::RECORDING, $recorder->manifest()->state);
    }

    /** @return iterable<string, array{string}> */
    public static function finalizationMethodProvider(): iterable
    {
        yield 'complete' => ['complete'];
        yield 'mark incomplete' => ['markIncomplete'];
    }

    public function testCompleteFsyncsEventsAndLeavesRecordingStateWhenThatSyncFails(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $syncsBeforeCompletion = $filesystem->eventSyncs;
        $filesystem->failNextEventSync();

        try {
            $recorder->complete();
            self::fail('The injected completion event-file fsync failure must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_complete_failed', $exception->getMessage());
            self::assertSame('paper_dataset_events_flush_failed', $exception->getPrevious()?->getMessage());
        }

        self::assertSame($syncsBeforeCompletion + 1, $filesystem->eventSyncs);
        $storedJson = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($storedJson);
        self::assertSame(PaperDatasetState::RECORDING, (new PaperDatasetManifestCodec())->decode($storedJson)->state);

        self::assertSame(PaperDatasetState::COMPLETE, $recorder->complete()->state);
        self::assertSame($syncsBeforeCompletion + 2, $filesystem->eventSyncs);
    }

    public function testCompleteRejectsShortEventChecksumReadBeforeFinalizingManifest(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $filesystem->shortReadNextChecksum();

        $failure = null;
        try {
            $recorder->complete();
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('paper_dataset_complete_failed', $failure->getMessage());
        self::assertSame('paper_dataset_checksum_failed', $failure->getPrevious()?->getMessage());
        self::assertSame(PaperDatasetState::RECORDING, $recorder->manifest()->state);
    }

    public function testCompleteFinalVerificationRejectsEventsPathSwappedAfterVerifierOpen(): void
    {
        $verifierFilesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            verifier: new PaperDatasetVerifier(filesystem: $verifierFilesystem),
        );
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $targetPath = $this->testRoot . '/verifier-events-target.ndjson';
        $eventsBefore = file_get_contents($eventsPath);
        self::assertIsString($eventsBefore);
        self::assertSame(strlen($eventsBefore), file_put_contents($targetPath, $eventsBefore));
        $verifierFilesystem->swapPathOnVerifierEventsValidation($eventsPath, $targetPath);

        $failure = null;
        try {
            $recorder->complete();
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('paper_dataset_complete_failed', $failure->getMessage());
        self::assertSame('paper_dataset_symlink_rejected', $failure->getPrevious()?->getMessage());
        self::assertSame($eventsBefore, file_get_contents($targetPath));
    }

    public function testManifestRewriteFailurePoisonsInstanceAndRestartRescansDurableAppend(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $second = $this->event(sequence: '2', microseconds: 2);
        $filesystem->failNextManifestBackupDirectorySync();

        try {
            $recorder->append($second);
            self::fail('The manifest rewrite must fail when its recovery evidence cannot be synced.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        try {
            $recorder->append($second);
            self::fail('A recorder with uncertain durable state must be unusable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_recorder_unusable', $exception->getMessage());
        }

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame(2, $restarted->manifest()->eventCount);
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $restarted->append($second));
    }

    public function testEveryManifestRenameSynchronizesTheDatasetDirectory(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        self::assertSame(1, $filesystem->manifestDirectorySyncs);

        $recorder->append($this->event(sequence: '1'));

        self::assertSame(2, $filesystem->manifestDirectorySyncs);
    }

    public function testManifestRewriteFsyncsAFixedTransitionBindingExactOldAndNewManifests(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $old = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($old);
        $filesystem->resetManifestTransitionObservation();

        $recorder->append($this->event(sequence: '1'));

        $new = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($new);
        self::assertSame(1, $filesystem->manifestTransitionSyncs);
        self::assertSame(2, $filesystem->manifestTransitionDirectorySyncs);
        self::assertSame(0600, $filesystem->observedManifestTransitionMode);
        self::assertSame([
            'version' => 1,
            'dataset_id' => $manifest->datasetId,
            'old_manifest_base64' => base64_encode($old),
            'old_manifest_sha256' => hash('sha256', $old),
            'new_manifest_base64' => base64_encode($new),
            'new_manifest_sha256' => hash('sha256', $new),
        ], $filesystem->observedManifestTransition);
        self::assertSame($new, $filesystem->observedManifestCandidate);
        self::assertSame($old, $filesystem->observedManifestBackup);
        self::assertSame(0600, $filesystem->observedManifestCandidateMode);
        self::assertSame(0600, $filesystem->observedManifestBackupMode);
        self::assertFileDoesNotExist($this->manifestTransitionPath());
        self::assertFileDoesNotExist($this->manifestCandidatePath());
        self::assertFileDoesNotExist($this->manifestBackupPath());
    }

    public function testEveryCreatedDirectoryEntrySynchronizesItsParentWithoutChangingExistingAncestorMode(): void
    {
        $existingAncestor = $this->testRoot . '/existing-ancestor';
        self::assertTrue(mkdir($existingAncestor, 0750));
        self::assertTrue(chmod($existingAncestor, 0750));
        $rootParent = $existingAncestor . '/root-parent';
        $rootContainer = $rootParent . '/root-container';
        $root = $rootContainer . '/paper-market-data';
        $datasetDirectory = $root . '/dataset-okx-001';
        $filesystem = new FaultInjectingPaperDatasetFilesystem();

        new PaperDatasetRecorder($root, $this->manifest(), filesystem: $filesystem);

        self::assertSame([
            $existingAncestor,
            $rootParent,
            $rootContainer,
            $root,
            $datasetDirectory,
        ], $filesystem->directoryParentSyncs);
        self::assertSame([
            $rootParent,
            $rootContainer,
            $root,
            $datasetDirectory,
            $datasetDirectory . '/checkpoints',
        ], $filesystem->createdDirectories);
        self::assertSame(0750, fileperms($existingAncestor) & 0777);
        foreach ($filesystem->createdDirectories as $createdDirectory) {
            self::assertSame(0700, fileperms($createdDirectory) & 0777);
        }
    }

    public function testDirectoryParentSyncFailureStopsConstructionWithStableError(): void
    {
        $root = $this->testRoot . '/sync-failure-root';
        $datasetDirectory = $root . '/dataset-okx-001';
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->failDirectoryParentSyncAt(2);
        $recorder = null;

        try {
            $recorder = new PaperDatasetRecorder($root, $this->manifest(), filesystem: $filesystem);
            self::fail('A directory parent sync failure must stop recorder construction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_parent_sync_failed', $exception->getMessage());
            self::assertStringNotContainsString($root, (string) $exception);
        }

        self::assertNull($recorder);
        self::assertSame([$this->testRoot, $root], $filesystem->directoryParentSyncs);
        self::assertDirectoryExists($datasetDirectory);
        self::assertDirectoryDoesNotExist($datasetDirectory . '/checkpoints');
        self::assertFileDoesNotExist($datasetDirectory . '/manifest.json');
    }

    public function testRetryResynchronizesExistingManagedDirectoryAfterParentSyncFailure(): void
    {
        $root = $this->testRoot . '/sync-retry-root';
        $datasetDirectory = $root . '/dataset-okx-001';
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->failDirectoryParentSyncAt(2);

        try {
            new PaperDatasetRecorder($root, $this->manifest(), filesystem: $filesystem);
            self::fail('The injected parent sync failure must interrupt the first construction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_parent_sync_failed', $exception->getMessage());
        }

        self::assertDirectoryExists($datasetDirectory);

        new PaperDatasetRecorder($root, $this->manifest(), filesystem: $filesystem);

        self::assertSame([
            $this->testRoot,
            $root,
            $this->testRoot,
            $root,
            $datasetDirectory,
        ], $filesystem->directoryParentSyncs);
    }

    public function testManifestDirectorySyncFailureIsNotReportedAsAppendSuccess(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        $event = $this->event(sequence: '1');
        $filesystem->failNextManifestDirectorySync();

        try {
            $recorder->append($event);
            self::fail('A manifest directory sync failure must not report append success.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        }

        try {
            $recorder->append($event);
            self::fail('A recorder with uncertain manifest durability must be unusable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_recorder_unusable', $exception->getMessage());
        }

        $recoveries = glob($this->datasetDirectory() . '/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertCount(1, $recoveries);
        self::assertSame($manifestBefore, file_get_contents($recoveries[0]));
        self::assertSame(0600, fileperms($recoveries[0]) & 0777);

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $restarted->append($event));
    }

    #[DataProvider('postRenameFinalizationFailureProvider')]
    public function testPostRenameDirectorySyncFailurePoisonsFinalizerAndRestartObservesFrozenState(
        string $method,
        PaperDatasetState $expectedState,
    ): void {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $filesystem->failNextManifestDirectorySync();

        try {
            $recorder->{$method}();
            self::fail('A post-rename directory fsync failure must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                $method === 'complete'
                    ? 'paper_dataset_complete_failed'
                    : 'paper_dataset_mark_incomplete_failed',
                $exception->getMessage(),
            );
            self::assertSame(
                'paper_dataset_manifest_directory_sync_failed',
                $exception->getPrevious()?->getMessage(),
            );
        }

        self::assertSame($expectedState, $recorder->manifest()->state);
        $this->assertRecorderUnusable($recorder);

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame($expectedState, $restarted->manifest()->state);
        try {
            $restarted->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A restarted finalized dataset must remain frozen.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, PaperDatasetState}> */
    public static function postRenameFinalizationFailureProvider(): iterable
    {
        yield 'complete' => ['complete', PaperDatasetState::COMPLETE];
        yield 'mark incomplete' => ['markIncomplete', PaperDatasetState::INCOMPLETE];
    }

    #[DataProvider('postRenameFinalizationFailureProvider')]
    public function testAmbiguousFinalizerLeavesOnlyTheFixedTransitionForExactRestartRecovery(
        string $method,
        PaperDatasetState $expectedState,
    ): void {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $filesystem->failNextManifestPublicationAfterRename('throw');

        try {
            $recorder->{$method}();
            self::fail('An ambiguous finalizer must leave one fixed authenticated transition.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                $method === 'complete'
                    ? 'paper_dataset_complete_failed'
                    : 'paper_dataset_mark_incomplete_failed',
                $exception->getMessage(),
            );
            self::assertSame(
                'Injected manifest publication failure after rename.',
                $exception->getPrevious()?->getMessage(),
            );
        }

        self::assertFileExists($this->manifestTransitionPath());
        self::assertFileExists($this->manifestBackupPath());
        self::assertFileDoesNotExist($this->manifestCandidatePath());
        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame($expectedState, $restarted->manifest()->state);
        self::assertFileDoesNotExist($this->manifestTransitionPath());
        self::assertFileDoesNotExist($this->manifestBackupPath());
        self::assertFileDoesNotExist($this->manifestCandidatePath());
    }

    public function testRestartRejectsAMutatedExactTransitionBackupWithoutPromotionOrCleanup(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $filesystem->failNextManifestPublicationAfterRename('throw');
        try {
            $recorder->complete();
            self::fail('The setup must retain an ambiguous terminal transition.');
        } catch (\RuntimeException) {
        }
        $backup = file_get_contents($this->manifestBackupPath());
        self::assertIsString($backup);
        $mutated = str_replace('"recorder_version":"1.0.0"', '"recorder_version":"9.9.9"', $backup);
        self::assertSame(strlen($backup), strlen($mutated));
        self::assertSame(strlen($mutated), file_put_contents($this->manifestBackupPath(), $mutated));
        $manifestBefore = file_get_contents($this->datasetDirectory() . '/manifest.json');
        $markerBefore = file_get_contents($this->manifestTransitionPath());

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('A changed exact backup must fail closed without artifact promotion.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_transition_invalid', $exception->getMessage());
        }

        self::assertSame($manifestBefore, file_get_contents($this->datasetDirectory() . '/manifest.json'));
        self::assertSame($markerBefore, file_get_contents($this->manifestTransitionPath()));
        self::assertSame($mutated, file_get_contents($this->manifestBackupPath()));
    }

    public function testManifestTransitionSurvivesLstatFailureOnPresentBackupDuringCleanup(): void
    {
        $manifest = $this->manifest();
        $publishingFilesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $publishingFilesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $publishingFilesystem->failNextManifestPublicationAfterRename('throw');
        try {
            $recorder->complete();
            self::fail('The setup must retain a terminal transition and backup.');
        } catch (\RuntimeException) {
        }
        self::assertFileExists($this->manifestTransitionPath());
        self::assertFileExists($this->manifestBackupPath());
        $transitionBefore = file_get_contents($this->manifestTransitionPath());
        $backupBefore = file_get_contents($this->manifestBackupPath());
        $recoveringFilesystem = new FaultInjectingPaperDatasetFilesystem();
        $recoveringFilesystem->failPathStatAt($this->manifestBackupPath(), 6);

        try {
            new PaperDatasetRecorder(
                $this->datasetRoot(),
                $manifest,
                filesystem: $recoveringFilesystem,
            );
            self::fail('A failed lstat on a present cleanup artifact must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_transition_invalid', $exception->getMessage());
        }

        self::assertSame($transitionBefore, file_get_contents($this->manifestTransitionPath()));
        self::assertSame($backupBefore, file_get_contents($this->manifestBackupPath()));
    }

    public function testActuallyAbsentProtocolArtifactsRemainPositiveAbsence(): void
    {
        $manifest = $this->manifest();
        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertFileDoesNotExist($this->appendIntentPath());
        self::assertFileDoesNotExist($this->manifestTransitionPath());
        self::assertFileDoesNotExist($this->manifestCandidatePath());
        self::assertFileDoesNotExist($this->manifestBackupPath());

        $restarted = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: new FaultInjectingPaperDatasetFilesystem(),
        );

        self::assertSame(PaperDatasetState::RECORDING, $restarted->manifest()->state);
    }

    public function testStaleRecorderChecksAndResolvesTheFixedManifestTransitionBeforeMutation(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $writer = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $writer->append($this->event(sequence: '1'));
        $filesystem->failNextManifestPublicationAfterRename('throw');
        try {
            $writer->complete();
            self::fail('The setup must retain an ambiguous terminal transition.');
        } catch (\RuntimeException) {
        }

        try {
            $stale->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A stale recorder must resolve terminal transition intent before appending.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->manifestTransitionPath());
        self::assertFileDoesNotExist($this->manifestBackupPath());
    }

    public function testStaleRecorderRollsForwardExactTerminalIntentPersistedBeforeRename(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $writer = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $writer->append($this->event(sequence: '1'));
        $stale = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $filesystem->failNextManifestCandidateDirectorySync();

        try {
            $writer->complete();
            self::fail('The setup must interrupt terminal publication before rename.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_complete_failed', $exception->getMessage());
        }
        $storedBefore = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($storedBefore);
        self::assertSame(
            PaperDatasetState::RECORDING,
            (new PaperDatasetManifestCodec())->decode($storedBefore)->state,
        );
        self::assertFileExists($this->manifestTransitionPath());
        self::assertFileExists($this->manifestCandidatePath());
        self::assertFileExists($this->manifestBackupPath());

        try {
            $stale->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A stale recorder must not discard a durable terminal transition intent.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }

        $storedAfter = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($storedAfter);
        self::assertSame(
            PaperDatasetState::COMPLETE,
            (new PaperDatasetManifestCodec())->decode($storedAfter)->state,
        );
        self::assertFileDoesNotExist($this->manifestTransitionPath());
        self::assertFileDoesNotExist($this->manifestCandidatePath());
        self::assertFileDoesNotExist($this->manifestBackupPath());
    }

    #[DataProvider('stableFinalizerErrorProvider')]
    public function testFinalizerExposesOnlyItsStablePublicErrorAndChainsTheCause(
        string $method,
        string $expectedError,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $filesystem->failNextEventSync();

        try {
            $recorder->{$method}();
            self::fail('A finalizer failure must expose only the method public contract.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedError, $exception->getMessage());
            self::assertSame('paper_dataset_events_flush_failed', $exception->getPrevious()?->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function stableFinalizerErrorProvider(): iterable
    {
        yield 'complete' => ['complete', 'paper_dataset_complete_failed'];
        yield 'mark incomplete' => ['markIncomplete', 'paper_dataset_mark_incomplete_failed'];
    }

    #[DataProvider('postRenameFinalizationFailureProvider')]
    public function testRestartAuthenticatesTerminalManifestAgainstReadOnlyEventScan(
        string $method,
        PaperDatasetState $expectedState,
    ): void {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        self::assertSame($expectedState, $recorder->{$method}()->state);
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $extra = CanonicalJson::encode($this->event(sequence: '2', microseconds: 2)->toArray()) . "\n";
        self::assertSame(strlen($extra), file_put_contents($eventsPath, $extra, FILE_APPEND));

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('A terminal manifest must authenticate against all durable event facts.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_terminal_manifest_invalid', $exception->getMessage());
        }
    }

    #[DataProvider('terminalPublicationMutationProvider')]
    public function testFinalizerAuthenticatesEventsThroughTerminalPublication(
        string $method,
        string $boundary,
        string $publicError,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        $original = file_get_contents($eventsPath);
        self::assertIsString($original);
        $replacement = CanonicalJson::encode(
            $this->event(sequence: '1', microseconds: 2)->toArray(),
        ) . "\n";
        self::assertSame(strlen($original), strlen($replacement));
        self::assertNotSame($original, $replacement);
        $filesystem->overwriteEventsAtTerminalPublication($eventsPath, $replacement, $boundary);

        try {
            $recorder->{$method}();
            self::fail('A divergent event snapshot must never produce finalization success.');
        } catch (\RuntimeException $exception) {
            self::assertSame($publicError, $exception->getMessage());
        }

        self::assertSame($replacement, file_get_contents($eventsPath));
        self::assertFileExists($this->manifestTransitionPath());
        self::assertFileExists($this->manifestBackupPath());
        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('Restart must reject the divergent pending terminal publication.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_terminal_manifest_invalid', $exception->getMessage());
        }
        self::assertFileExists($this->manifestTransitionPath());
        self::assertFileExists($this->manifestBackupPath());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function terminalPublicationMutationProvider(): iterable
    {
        foreach (['before_rename', 'after_rename', 'after_directory_sync'] as $boundary) {
            yield 'complete ' . $boundary => [
                'complete',
                $boundary,
                'paper_dataset_complete_failed',
            ];
            yield 'mark incomplete ' . $boundary => [
                'markIncomplete',
                $boundary,
                'paper_dataset_mark_incomplete_failed',
            ];
        }
    }

    #[DataProvider('terminalRetryRecoveryProvider')]
    public function testMatchingTerminalRetryReturnsExactAuthenticatedManifestAfterRecovery(
        string $method,
        string $oppositeMethod,
        PaperDatasetState $expectedState,
        string $boundary,
        string $oppositePublicError,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        match ($boundary) {
            'before_rename' => $filesystem->failNextManifestCandidateDirectorySync(),
            'after_rename' => $filesystem->failNextManifestPublicationAfterRename('throw'),
            'after_directory_sync' => $filesystem->failNextManifestDirectorySync(),
            'during_cleanup' => $filesystem->failNextTerminalCleanupSync('backup'),
            default => throw new \InvalidArgumentException('Unknown terminal retry boundary.'),
        };

        try {
            $recorder->{$method}();
            self::fail('The injected terminal boundary must interrupt the first finalization.');
        } catch (\RuntimeException) {
        }

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $authenticatedContents = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($authenticatedContents);
        $authenticated = (new PaperDatasetManifestCodec())->decode($authenticatedContents);
        self::assertSame($expectedState, $authenticated->state);

        $retried = $restarted->{$method}();

        self::assertSame($expectedState, $retried->state);
        self::assertSame($authenticatedContents, (new PaperDatasetManifestCodec())->encode($retried));
        self::assertSame($authenticatedContents, file_get_contents($this->datasetDirectory() . '/manifest.json'));
        try {
            $restarted->{$oppositeMethod}();
            self::fail('An opposite terminal intent must remain rejected after matching retry.');
        } catch (\RuntimeException $exception) {
            self::assertSame($oppositePublicError, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string, PaperDatasetState, string, string}> */
    public static function terminalRetryRecoveryProvider(): iterable
    {
        foreach (['before_rename', 'after_rename', 'after_directory_sync', 'during_cleanup'] as $boundary) {
            yield 'complete ' . $boundary => [
                'complete',
                'markIncomplete',
                PaperDatasetState::COMPLETE,
                $boundary,
                'paper_dataset_mark_incomplete_failed',
            ];
            yield 'mark incomplete ' . $boundary => [
                'markIncomplete',
                'complete',
                PaperDatasetState::INCOMPLETE,
                $boundary,
                'paper_dataset_complete_failed',
            ];
        }
    }

    public function testUnrelatedManifestArtifactIsNeverEnumeratedOrPromoted(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $manifestBefore = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($manifestBefore);
        $artifact = $this->datasetDirectory() . '/.manifest-backup-attacker-controlled';
        self::assertSame(strlen($manifestBefore), file_put_contents($artifact, $manifestBefore));
        self::assertTrue(chmod($artifact, 0600));

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);

        self::assertSame(PaperDatasetState::RECORDING, $restarted->manifest()->state);
        self::assertSame($manifestBefore, file_get_contents($this->datasetDirectory() . '/manifest.json'));
        self::assertSame($manifestBefore, file_get_contents($artifact));
    }

    public function testRestartRejectsOversizedManifestTransitionBeforeReadingItsContents(): void
    {
        $manifest = $this->manifest();
        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $handle = fopen($this->manifestTransitionPath(), 'x+b');
        self::assertIsResource($handle);
        try {
            self::assertTrue(ftruncate($handle, 200_001));
            self::assertTrue(fflush($handle));
        } finally {
            fclose($handle);
        }
        self::assertTrue(chmod($this->manifestTransitionPath(), 0600));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest, filesystem: $filesystem);
            self::fail('The transition marker bound must be checked before allocating its contents.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_transition_invalid', $exception->getMessage());
        }

        self::assertSame(0, $filesystem->manifestTransitionReads);
        self::assertSame(200_001, filesize($this->manifestTransitionPath()));
    }

    public function testOrphanedFixedManifestStageFailsClosedWithoutCleanup(): void
    {
        $manifest = $this->manifest();
        new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $stored = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($stored);
        self::assertSame(strlen($stored), file_put_contents($this->manifestCandidatePath(), $stored));
        self::assertTrue(chmod($this->manifestCandidatePath(), 0600));

        try {
            new PaperDatasetRecorder($this->datasetRoot(), $manifest);
            self::fail('A fixed stage without its exact transition marker must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_transition_invalid', $exception->getMessage());
        }

        self::assertSame($stored, file_get_contents($this->manifestCandidatePath()));
        self::assertSame($stored, file_get_contents($this->datasetDirectory() . '/manifest.json'));
    }

    public function testFinalizedRestartScansEventsReadOnlyWhenNoRecoveryMarkerExists(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $recorder->complete();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();

        $restarted = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );

        self::assertSame(PaperDatasetState::COMPLETE, $restarted->manifest()->state);
        self::assertNotSame([], $filesystem->eventScanModes);
        self::assertSame(['rb'], array_values(array_unique($filesystem->eventScanModes)));
    }

    #[DataProvider('ambiguousFinalizationPublicationFailureProvider')]
    public function testPostRenamePublicationAmbiguityPoisonsFinalizerAndRestartObservesFrozenState(
        string $method,
        PaperDatasetState $expectedState,
        string $failure,
        string $expectedError,
    ): void {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $recorder->append($this->event(sequence: '1'));
        $manifestPath = $this->datasetDirectory() . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        $filesystem->failNextManifestPublicationAfterRename($failure);

        try {
            $recorder->{$method}();
            self::fail('A post-rename finalization ambiguity must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                $method === 'complete'
                    ? 'paper_dataset_complete_failed'
                    : 'paper_dataset_mark_incomplete_failed',
                $exception->getMessage(),
            );
            self::assertSame($expectedError, $exception->getPrevious()?->getMessage());
        }

        self::assertSame($expectedState, $recorder->manifest()->state);
        $this->assertRecorderUnusable($recorder);
        $recoveries = glob($this->datasetDirectory() . '/.manifest-backup-*');
        self::assertIsArray($recoveries);
        self::assertCount(1, $recoveries);
        self::assertSame($manifestBefore, file_get_contents($recoveries[0]));
        self::assertSame(0600, fileperms($recoveries[0]) & 0777);

        $restarted = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        self::assertSame($expectedState, $restarted->manifest()->state);
        try {
            $restarted->append($this->event(sequence: '2', microseconds: 2));
            self::fail('A restarted finalized dataset must remain frozen.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_not_recording', $exception->getMessage());
        }

        if ($expectedState === PaperDatasetState::COMPLETE) {
            self::assertEquals($restarted->manifest(), (new PaperDatasetVerifier())->verify($this->datasetDirectory()));
        }
    }

    /** @return iterable<string, array{string, PaperDatasetState, string, string}> */
    public static function ambiguousFinalizationPublicationFailureProvider(): iterable
    {
        foreach (self::postRenameFinalizationFailureProvider() as $finalizer => [$method, $state]) {
            yield $finalizer . ', move throws after rename' => [
                $method,
                $state,
                'throw',
                'Injected manifest publication failure after rename.',
            ];
            yield $finalizer . ', move returns false after rename' => [
                $method,
                $state,
                'false',
                'paper_dataset_manifest_rename_failed',
            ];
        }
    }

    public function testPayloadBearingRecorderParametersAreSensitiveAndFullTraceIsRedacted(): void
    {
        foreach ([
            [PaperDatasetRecorder::class, 'append', 'event'],
            [PaperDatasetRecorder::class, 'appendUnderLock', 'event'],
            [PaperDatasetRecorder::class, 'assertEventMatchesManifest', 'event'],
            [PaperDatasetRecorder::class, 'appendDurably', 'line'],
            [PaperDatasetRecorder::class, 'writeAll', 'contents'],
            [PaperDatasetRecorder::class, 'withDatasetLock', 'operation'],
            [PaperDatasetRecorderFilesystem::class, 'write', 'contents'],
        ] as [$class, $method, $parameter]) {
            $reflection = new \ReflectionParameter([$class, $method], $parameter);
            self::assertNotEmpty(
                $reflection->getAttributes(\SensitiveParameter::class),
                sprintf('%s::%s($%s) must be sensitive.', $class, $method, $parameter),
            );
        }

        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $sentinel = 'PAPER_EVENT_TRACE_SENTINEL_7f5937f5';
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $event = PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: PaperMarketDataChannel::TOP_OF_BOOK,
            exchangeTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.000001Z'),
            receivedTimestamp: new \DateTimeImmutable('2026-07-19T10:00:01.000001Z'),
            sequence: '1',
            payload: ['ask' => '30001.0', 'bid' => '29999.0', 'note' => $sentinel],
        );
        $filesystem->failNextAppend('full_write');

        try {
            $recorder->append($event);
            self::fail('The injected write failure must be reported.');
        } catch (\RuntimeException $exception) {
            $fullTrace = (string) $exception . "\n" . print_r($exception->getTrace(), true);
            self::assertStringNotContainsString($sentinel, $fullTrace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function testRootIsRedactedFromFullRecorderTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $sentinel = 'PAPER_DATASET_ROOT_TRACE_SENTINEL_6b8fa2d1';
        $root = $this->testRoot . DIRECTORY_SEPARATOR . $sentinel;
        self::assertTrue(symlink($this->testRoot, $root));

        try {
            new PaperDatasetRecorder($root, $this->manifest());
            self::fail('A symlinked dataset root must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_symlink_rejected', $exception->getMessage());
            $fullTrace = (string) $exception . "\n" . print_r($exception->getTrace(), true);
            self::assertStringNotContainsString($sentinel, $fullTrace);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public function testEveryPathBearingRecorderParameterIsSensitive(): void
    {
        foreach ([PaperDatasetRecorder::class, PaperDatasetRecorderFilesystem::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    if (!preg_match(
                        '/(?:root|path|directory|parent|source|destination|backup)/i',
                        $parameter->getName(),
                    )) {
                        continue;
                    }

                    self::assertNotEmpty(
                        $parameter->getAttributes(\SensitiveParameter::class),
                        sprintf(
                            '%s::%s($%s) must be sensitive.',
                            $class,
                            $method->getName(),
                            $parameter->getName(),
                        ),
                    );
                }
            }
        }
    }

    public function testInitialManifestDirectorySyncFailureHasStableError(): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->failNextManifestDirectorySync();

        try {
            new PaperDatasetRecorder(
                $this->datasetRoot(),
                $this->manifest(),
                filesystem: $filesystem,
            );
            self::fail('An initial manifest directory sync failure must fail construction.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_directory_sync_failed', $exception->getMessage());
        }

        self::assertFileExists($this->datasetDirectory() . '/manifest.json');
    }

    #[DataProvider('recoverableAppendFailureProvider')]
    public function testAppendFailureRollsBackToOriginalLengthAndRecorderRemainsUsable(
        string $failure,
        string $expectedError,
    ): void {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $eventsPath = $this->datasetDirectory() . '/events.ndjson';
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($this->event(sequence: '1')));
        $before = file_get_contents($eventsPath);
        self::assertIsString($before);
        self::assertNotSame('', $before);
        $filesystem->failNextAppend($failure);

        try {
            $recorder->append($this->event(sequence: '2', microseconds: 2));
            self::fail('The injected append failure must be reported.');
        } catch (\RuntimeException $exception) {
            self::assertSame($expectedError, $exception->getMessage());
        }

        self::assertSame(1, $filesystem->rollbackTruncations);
        self::assertSame($before, file_get_contents($eventsPath));
        self::assertSame(
            PaperDatasetAppendResult::APPENDED,
            $recorder->append($this->event(sequence: '2', microseconds: 2)),
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function recoverableAppendFailureProvider(): iterable
    {
        yield 'full write failure' => ['full_write', 'paper_dataset_events_write_failed'];
        yield 'partial write failure' => ['partial_write', 'paper_dataset_events_write_failed'];
        yield 'fflush failure' => ['flush', 'paper_dataset_events_flush_failed'];
        yield 'fsync failure' => ['sync', 'paper_dataset_events_flush_failed'];
        yield 'post-append fstat failure' => ['post_stat', 'paper_dataset_events_read_failed'];
    }

    #[DataProvider('rollbackFailureProvider')]
    public function testFailedAppendPoisonsRecorderWhenRollbackCannotBeMadeDurable(string $failure): void
    {
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $recorder = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $this->manifest(),
            filesystem: $filesystem,
        );
        $filesystem->failNextAppend('partial_write');
        $filesystem->failRollback($failure);

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('An append with an uncertain rollback must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_events_rollback_failed', $exception->getMessage());
        }

        try {
            $recorder->append($this->event(sequence: '1'));
            self::fail('A recorder with uncertain event bytes must be unusable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_recorder_unusable', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function rollbackFailureProvider(): iterable
    {
        yield 'truncate failure' => ['truncate'];
        yield 'rollback fflush failure' => ['flush'];
        yield 'rollback fsync failure' => ['sync'];
    }

    private function manifest(
        PaperMarketDataQuality $quality = PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
        ?string $modelName = null,
        ?string $modelVersion = null,
    ): PaperDatasetManifest {
        return new PaperDatasetManifest(
            schemaVersion: 1,
            recorderVersion: '1.0.0',
            datasetId: 'dataset-okx-001',
            venue: PaperMarketDataVenue::OKX,
            symbols: [
                'BTCUSDT' => 'BTC-USDT-SWAP',
                'ETHUSDT' => 'ETH-USDT-SWAP',
            ],
            startExchangeTimestamp: null,
            endExchangeTimestamp: null,
            channels: [],
            eventCount: 0,
            sequenceGaps: [],
            quality: $quality,
            modelName: $modelName,
            modelVersion: $modelVersion,
            eventsFileSha256: null,
            state: PaperDatasetState::RECORDING,
            lastEventId: null,
        );
    }

    private function event(
        string $sequence,
        int $microseconds = 1,
        PaperMarketDataChannel $channel = PaperMarketDataChannel::TOP_OF_BOOK,
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: $channel,
            exchangeTimestamp: new \DateTimeImmutable(sprintf('2026-07-19T10:00:00.%06dZ', $microseconds)),
            receivedTimestamp: new \DateTimeImmutable(sprintf('2026-07-19T10:00:01.%06dZ', $microseconds)),
            sequence: $sequence,
            payload: ['ask' => '30001.0', 'bid' => '29999.0'],
        );
    }

    private function sameLengthValidReplacement(PaperMarketEvent $recorded): string
    {
        $replacement = $recorded->toArray();
        $replacement['payload'] = ['ask' => '30001.0', 'bid' => '28888.0'];
        $replacement['payload_hash'] = hash('sha256', CanonicalJson::encode($replacement['payload']));
        $replacementEvent = PaperMarketEvent::fromArray($replacement);
        self::assertNotSame($recorded->payloadHash, $replacementEvent->payloadHash);

        return CanonicalJson::encode($replacementEvent->toArray()) . "\n";
    }

    private function datasetRoot(): string
    {
        return $this->testRoot . '/paper-market-data';
    }

    private function datasetDirectory(): string
    {
        return $this->datasetRoot() . '/dataset-okx-001';
    }

    private function appendIntentPath(): string
    {
        return $this->datasetDirectory() . '/.append-intent.json';
    }

    private function manifestTransitionPath(): string
    {
        return $this->datasetDirectory() . '/.manifest-transition.json';
    }

    private function manifestCandidatePath(): string
    {
        return $this->datasetDirectory() . '/.manifest-candidate-fixed';
    }

    private function manifestBackupPath(): string
    {
        return $this->datasetDirectory() . '/.manifest-backup-fixed';
    }

    private function fixedArtifactPath(string $artifact): string
    {
        return match ($artifact) {
            'append_intent' => $this->appendIntentPath(),
            'manifest_transition' => $this->manifestTransitionPath(),
            'manifest_backup' => $this->manifestBackupPath(),
            'manifest_candidate' => $this->manifestCandidatePath(),
            default => throw new \InvalidArgumentException('Unknown fixed artifact.'),
        };
    }

    private function stageAppendIntent(
        PaperDatasetManifest $manifest,
        PaperMarketEvent $event,
        #[\SensitiveParameter] string $committed,
        #[\SensitiveParameter] string $canonicalLine,
    ): void {
        $marker = json_encode([
            'version' => 1,
            'dataset_id' => $manifest->datasetId,
            'event_id' => $event->eventId,
            'original_events_bytes' => strlen($committed),
            'original_events_sha256' => hash('sha256', $committed),
            'canonical_line_base64' => base64_encode($canonicalLine),
            'canonical_line_sha256' => hash('sha256', $canonicalLine),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        self::assertSame(strlen($marker), file_put_contents($this->appendIntentPath(), $marker));
        self::assertTrue(chmod($this->appendIntentPath(), 0600));
    }

    /** @return array<string, mixed> */
    private function recorderScanState(PaperDatasetRecorder $recorder): array
    {
        $state = [];
        foreach ([
            'identities',
            'lastSequences',
            'sequenceGaps',
            'channels',
            'eventCount',
            'scannedBytes',
            'scannedPrefixSha256',
            'scannedFileIdentity',
            'lastEventId',
            'startExchangeTimestamp',
            'latestExchangeTimestamp',
        ] as $property) {
            $reflection = new \ReflectionProperty(PaperDatasetRecorder::class, $property);
            $state[$property] = $reflection->getValue($recorder);
        }

        return $state;
    }

    private function assertRecorderUnusable(PaperDatasetRecorder $recorder): void
    {
        try {
            $recorder->append($this->event(sequence: '8', microseconds: 8));
            self::fail('A recorder with divergent durable state must be unusable.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_recorder_unusable', $exception->getMessage());
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

final class FaultInjectingPaperDatasetFilesystem extends PaperDatasetRecorderFilesystem
{
    public int $rollbackTruncations = 0;
    public int $manifestDirectorySyncs = 0;
    public int $manifestPublications = 0;
    public int $eventSyncs = 0;
    public int $appendIntentSyncs = 0;
    public int $appendIntentDirectorySyncs = 0;
    public int $appendIntentReads = 0;
    public int $appendRecoverySyncs = 0;
    public int $manifestTransitionSyncs = 0;
    public int $manifestTransitionDirectorySyncs = 0;
    public int $manifestTransitionReads = 0;
    public ?int $observedAppendIntentMode = null;

    public ?int $observedManifestTransitionMode = null;
    public ?int $observedManifestCandidateMode = null;
    public ?int $observedManifestBackupMode = null;

    /** @var array<string, mixed>|null */
    public ?array $observedManifestTransition = null;
    public ?string $observedManifestCandidate = null;
    public ?string $observedManifestBackup = null;

    /** @var list<string> */
    public array $eventScanModes = [];

    /** @var array<string, int> */
    public array $fixedArtifactPublications = [];

    /** @var array<string, mixed>|null */
    public ?array $observedAppendIntent = null;

    /** @var list<string> */
    public array $createdDirectories = [];

    /** @var list<string> */
    public array $directoryParentSyncs = [];

    private ?string $appendFailure = null;
    private bool $partialWriteCompleted = false;
    private bool $appendWriteObserved = false;
    private ?string $rollbackFailure = null;
    private bool $failManifestDirectorySync = false;
    private bool $failManifestBackupDirectorySync = false;
    private bool $failManifestCandidateDirectorySync = false;
    private ?string $manifestPublicationFailureAfterRename = null;
    private bool $failEventSync = false;
    private bool $failAppendRecoverySync = false;
    private ?string $appendRecoveryMutationPath = null;
    private ?string $appendRecoveryMutationContents = null;
    private bool $shortChecksumRead = false;
    private ?string $verifierEventsPath = null;
    private ?string $verifierEventsTarget = null;
    private ?string $zeroTailEventsPath = null;
    private ?string $zeroTailEventsTarget = null;
    private ?int $tailReadsBeforeFailure = null;
    private ?string $tailMutationPath = null;
    private ?string $tailMutationContents = null;
    private int $tailMutationSeeks = 0;
    private ?string $datasetDirectoryToSwap = null;
    private ?string $datasetDirectoryReplacement = null;
    private ?string $publicationMutationPath = null;
    private ?string $publicationMutationContents = null;
    private ?string $publicationMutationTrigger = null;
    private int $publicationMutationOffset = 0;
    private bool $publicationAppendWriteObserved = false;
    private int $publicationSnapshotValidationSeeks = 0;
    private ?int $publicationSnapshotValidationTarget = null;
    private ?int $directoryParentSyncFailureAt = null;
    private ?string $lockPathToReplaceAtPublication = null;
    private ?\Closure $afterPublicationLockReplacement = null;
    private ?string $manifestPathForTemporaryMutation = null;
    private ?string $manifestTemporaryMutationSearch = null;
    private ?string $manifestTemporaryMutationReplacement = null;
    private bool $substituteManifestTemporaryInode = false;
    private ?string $manifestBackupMutationSearch = null;
    private ?string $manifestBackupMutationReplacement = null;
    private bool $substituteManifestBackupInode = false;
    private ?string $fileToSwapBeforeLegacyModeChange = null;
    private ?string $fileModeChangeTarget = null;
    private ?string $manifestTemporaryDirectoryToSwap = null;
    private ?string $manifestTemporaryModeChangeTarget = null;
    private ?string $datasetDirectoryToSwapAfterManifestPublication = null;
    private ?string $datasetDirectoryReplacementAfterManifestPublication = null;
    private ?string $datasetDirectoryToSwapDuringManifestSnapshotValidation = null;
    private ?string $datasetDirectoryReplacementDuringManifestSnapshotValidation = null;
    private bool $manifestSnapshotDirectorySwapArmed = false;
    private ?string $datasetDirectoryToSwapAfterRecoveryRevalidation = null;
    private ?string $datasetDirectoryReplacementAfterRecoveryRevalidation = null;
    private int $recoveryRevalidationManifestPublication = 0;
    private int $datasetDirectoryValidationsAfterManifestPublication = 0;
    private ?string $directoryToChangeModeOnLockOpen = null;
    private ?int $directoryModeOnLockOpen = null;
    private ?string $fixedArtifactFailureArtifact = null;
    private ?string $fixedArtifactFailure = null;
    private bool $fixedArtifactShortWriteCompleted = false;

    /** @var array<string, int> */
    private array $pathStatFailureAt = [];

    /** @var array<string, int> */
    private array $pathStatCalls = [];
    private ?string $terminalMutationPath = null;
    private ?string $terminalMutationContents = null;
    private ?string $terminalMutationBoundary = null;
    private ?string $terminalCleanupFailure = null;
    private bool $terminalCleanupFailureArmed = false;

    public function createDirectory(#[\SensitiveParameter] string $directory, int $permissions): bool
    {
        $this->createdDirectories[] = $directory;

        return @mkdir($directory, $permissions);
    }

    public function failDirectoryParentSyncAt(int $sync): void
    {
        $this->directoryParentSyncFailureAt = $sync;
    }

    public function failPathStatAt(#[\SensitiveParameter] string $path, int $call): void
    {
        if ($call < 1) {
            throw new \InvalidArgumentException('Path stat failure call must be positive.');
        }
        $this->pathStatFailureAt[$path] = $call;
        $this->pathStatCalls[$path] = 0;
    }

    public function replaceLockAtNextManifestPublication(
        #[\SensitiveParameter] string $lockPath,
        callable $afterReplacement,
    ): void {
        $this->lockPathToReplaceAtPublication = $lockPath;
        $this->afterPublicationLockReplacement = $afterReplacement(...);
    }

    public function mutateManifestTemporaryAfterValidation(
        #[\SensitiveParameter] string $manifestPath,
        #[\SensitiveParameter] string $search,
        #[\SensitiveParameter] string $replacement,
        bool $substituteInode,
    ): void {
        if (strlen($search) !== strlen($replacement)) {
            throw new \InvalidArgumentException('Manifest mutations must preserve size.');
        }
        $this->manifestPathForTemporaryMutation = $manifestPath;
        $this->manifestTemporaryMutationSearch = $search;
        $this->manifestTemporaryMutationReplacement = $replacement;
        $this->substituteManifestTemporaryInode = $substituteInode;
    }

    public function mutateManifestBackupAtPublication(
        #[\SensitiveParameter] string $search,
        #[\SensitiveParameter] string $replacement,
        bool $substituteInode,
    ): void {
        if (strlen($search) !== strlen($replacement)) {
            throw new \InvalidArgumentException('Manifest backup mutations must preserve size.');
        }
        $this->manifestBackupMutationSearch = $search;
        $this->manifestBackupMutationReplacement = $replacement;
        $this->substituteManifestBackupInode = $substituteInode;
    }

    public function swapFileAfterValidationBeforeLegacyModeChange(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $target,
    ): void {
        $this->fileToSwapBeforeLegacyModeChange = $path;
        $this->fileModeChangeTarget = $target;
    }

    public function swapManifestTemporaryBeforeLegacyModeChange(
        #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $target,
    ): void {
        $this->manifestTemporaryDirectoryToSwap = $directory;
        $this->manifestTemporaryModeChangeTarget = $target;
    }

    public function swapDatasetDirectoryAfterManifestPublication(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $replacement,
    ): void {
        $this->datasetDirectoryToSwapAfterManifestPublication = $path;
        $this->datasetDirectoryReplacementAfterManifestPublication = $replacement;
    }

    public function swapDatasetDirectoryDuringPublishedManifestSnapshotValidation(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $replacement,
    ): void {
        $this->datasetDirectoryToSwapDuringManifestSnapshotValidation = $path;
        $this->datasetDirectoryReplacementDuringManifestSnapshotValidation = $replacement;
    }

    public function swapDatasetDirectoryAfterRecoveryRevalidation(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $replacement,
    ): void {
        $this->datasetDirectoryToSwapAfterRecoveryRevalidation = $path;
        $this->datasetDirectoryReplacementAfterRecoveryRevalidation = $replacement;
        $this->recoveryRevalidationManifestPublication = $this->manifestPublications + 1;
        $this->datasetDirectoryValidationsAfterManifestPublication = 0;
    }

    public function changeDirectoryModeOnLockOpen(
        #[\SensitiveParameter] string $directory,
        int $mode,
    ): void {
        $this->directoryToChangeModeOnLockOpen = $directory;
        $this->directoryModeOnLockOpen = $mode;
    }

    public function openDirectory(#[\SensitiveParameter] string $directory, string $operation)
    {
        $handle = parent::openDirectory($directory, $operation);
        if ($operation === 'paper_dataset_lock_open_failed'
            && $directory === $this->directoryToChangeModeOnLockOpen
            && $this->directoryModeOnLockOpen !== null
        ) {
            $mode = $this->directoryModeOnLockOpen;
            $this->directoryToChangeModeOnLockOpen = null;
            $this->directoryModeOnLockOpen = null;
            if (!chmod($directory, $mode)) {
                throw new \RuntimeException('Unable to inject dataset directory permission drift.');
            }
        }

        return $handle;
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        $statistics = parent::pathStat($path, $operation);
        if (isset($this->pathStatFailureAt[$path])) {
            $this->pathStatCalls[$path] = ($this->pathStatCalls[$path] ?? 0) + 1;
            if ($this->pathStatCalls[$path] === $this->pathStatFailureAt[$path]) {
                unset($this->pathStatFailureAt[$path]);

                return false;
            }
        }
        if ($operation === 'paper_dataset_directory_validation'
            && $path === $this->datasetDirectoryToSwapAfterRecoveryRevalidation
            && $this->datasetDirectoryReplacementAfterRecoveryRevalidation !== null
            && $this->manifestPublications >= $this->recoveryRevalidationManifestPublication
        ) {
            ++$this->datasetDirectoryValidationsAfterManifestPublication;
            if ($this->datasetDirectoryValidationsAfterManifestPublication === 2) {
                $replacement = $this->datasetDirectoryReplacementAfterRecoveryRevalidation;
                $this->datasetDirectoryToSwapAfterRecoveryRevalidation = null;
                $this->datasetDirectoryReplacementAfterRecoveryRevalidation = null;
                if (!rename($path, $path . '.directory-original') || !rename($replacement, $path)) {
                    throw new \RuntimeException('Unable to inject dataset substitution after recovery revalidation.');
                }
            }
        }
        if ($this->fileToSwapBeforeLegacyModeChange === $path
            && $this->fileModeChangeTarget !== null
        ) {
            $target = $this->fileModeChangeTarget;
            $this->fileToSwapBeforeLegacyModeChange = null;
            $this->fileModeChangeTarget = null;
            if (!rename($path, $path . '.before-mode-swap') || !symlink($target, $path)) {
                throw new \RuntimeException('Unable to inject terminal file mode substitution.');
            }
        }
        if ($this->manifestTemporaryDirectoryToSwap === $path
            && $this->manifestTemporaryModeChangeTarget !== null
        ) {
            $temporary = $path . '/.manifest-candidate-fixed';
            if (is_file($temporary)) {
                $target = $this->manifestTemporaryModeChangeTarget;
                $this->manifestTemporaryDirectoryToSwap = null;
                $this->manifestTemporaryModeChangeTarget = null;
                if (!rename($temporary, $temporary . '.before-mode-swap')
                    || !symlink($target, $temporary)
                ) {
                    throw new \RuntimeException('Unable to inject manifest temporary mode substitution.');
                }
            }
        }
        return $statistics;
    }

    public function move(
        #[\SensitiveParameter] string $source,
        #[\SensitiveParameter] string $destination,
        string $operation,
    ): bool {
        $fixedArtifact = $this->fixedArtifactForPublishOperation($operation);
        if ($fixedArtifact !== null) {
            $this->fixedArtifactPublications[$fixedArtifact] = (
                $this->fixedArtifactPublications[$fixedArtifact] ?? 0
            ) + 1;
            if ($fixedArtifact === $this->fixedArtifactFailureArtifact
                && $this->fixedArtifactFailure === 'before_rename'
            ) {
                $this->clearFixedArtifactFailure();

                return false;
            }
            $moved = parent::move($source, $destination, $operation);
            if ($moved
                && $fixedArtifact === $this->fixedArtifactFailureArtifact
                && $this->fixedArtifactFailure === 'after_rename'
            ) {
                $this->clearFixedArtifactFailure();

                throw new \RuntimeException('Injected crash after fixed artifact rename.');
            }

            return $moved;
        }
        if ($operation === 'paper_dataset_manifest_publish') {
            ++$this->manifestPublications;
            $directory = dirname($destination);
            $markerPath = $directory . '/.manifest-transition.json';
            $candidatePath = $directory . '/.manifest-candidate-fixed';
            $backupPath = $directory . '/.manifest-backup-fixed';
            $marker = is_file($markerPath) ? file_get_contents($markerPath) : false;
            $candidate = is_file($candidatePath) ? file_get_contents($candidatePath) : false;
            $backup = is_file($backupPath) ? file_get_contents($backupPath) : false;
            $markerMode = is_file($markerPath) ? fileperms($markerPath) : false;
            $candidateMode = is_file($candidatePath) ? fileperms($candidatePath) : false;
            $backupMode = is_file($backupPath) ? fileperms($backupPath) : false;
            if ($marker !== false && $markerMode !== false) {
                $decoded = json_decode($marker, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                if (\is_array($decoded) && !array_is_list($decoded)) {
                    $this->observedManifestTransition = $decoded;
                    $this->observedManifestTransitionMode = $markerMode & 0777;
                }
            }
            if ($candidate !== false && $candidateMode !== false) {
                $this->observedManifestCandidate = $candidate;
                $this->observedManifestCandidateMode = $candidateMode & 0777;
            }
            if ($backup !== false && $backupMode !== false) {
                $this->observedManifestBackup = $backup;
                $this->observedManifestBackupMode = $backupMode & 0777;
            }
        }
        if ($operation === 'paper_dataset_manifest_publish'
            && $this->lockPathToReplaceAtPublication !== null
            && $this->afterPublicationLockReplacement !== null
        ) {
            $lockPath = $this->lockPathToReplaceAtPublication;
            $afterReplacement = $this->afterPublicationLockReplacement;
            $this->lockPathToReplaceAtPublication = null;
            $this->afterPublicationLockReplacement = null;
            if (!unlink($lockPath)) {
                throw new \RuntimeException('Unable to remove the transaction lock at publication.');
            }
            $previousUmask = umask(0077);
            try {
                $replacement = fopen($lockPath, 'x+b');
            } finally {
                umask($previousUmask);
            }
            if ($replacement === false) {
                throw new \RuntimeException('Unable to replace the transaction lock at publication.');
            }
            fclose($replacement);
            $afterReplacement();
        }
        if ($operation === 'paper_dataset_manifest_publish'
            && $this->terminalMutationBoundary === 'before_rename'
        ) {
            $this->injectTerminalPublicationMutation();
        }
        if ($operation === 'paper_dataset_manifest_publish'
            && $this->manifestPathForTemporaryMutation === $destination
            && $this->manifestTemporaryMutationSearch !== null
            && $this->manifestTemporaryMutationReplacement !== null
        ) {
            $contents = file_get_contents($source);
            if ($contents === false
                || substr_count($contents, $this->manifestTemporaryMutationSearch) !== 1
            ) {
                throw new \RuntimeException('Unable to locate manifest temporary mutation target.');
            }
            $mutated = str_replace(
                $this->manifestTemporaryMutationSearch,
                $this->manifestTemporaryMutationReplacement,
                $contents,
            );
            $substituteInode = $this->substituteManifestTemporaryInode;
            $this->manifestPathForTemporaryMutation = null;
            $this->manifestTemporaryMutationSearch = null;
            $this->manifestTemporaryMutationReplacement = null;
            $this->substituteManifestTemporaryInode = false;

            if ($substituteInode) {
                if (!rename($source, $source . '.validated')) {
                    throw new \RuntimeException('Unable to preserve validated manifest temporary.');
                }
                $previousUmask = umask(0077);
                try {
                    $replacementHandle = fopen($source, 'x+b');
                } finally {
                    umask($previousUmask);
                }
                if ($replacementHandle === false) {
                    throw new \RuntimeException('Unable to substitute manifest temporary inode.');
                }
                try {
                    if (fwrite($replacementHandle, $mutated) !== strlen($mutated)
                        || !fflush($replacementHandle)
                    ) {
                        throw new \RuntimeException('Unable to write substituted manifest temporary.');
                    }
                } finally {
                    fclose($replacementHandle);
                }
            } elseif (file_put_contents($source, $mutated) !== strlen($mutated)) {
                throw new \RuntimeException('Unable to mutate manifest temporary in place.');
            }
        }
        if ($operation === 'paper_dataset_manifest_publish'
            && $this->manifestBackupMutationSearch !== null
            && $this->manifestBackupMutationReplacement !== null
        ) {
            $backup = dirname($destination) . '/.manifest-backup-fixed';
            $contents = file_get_contents($backup);
            if ($contents === false
                || substr_count($contents, $this->manifestBackupMutationSearch) !== 1
            ) {
                throw new \RuntimeException('Unable to locate manifest backup mutation contents.');
            }
            $mutated = str_replace(
                $this->manifestBackupMutationSearch,
                $this->manifestBackupMutationReplacement,
                $contents,
            );
            $substituteInode = $this->substituteManifestBackupInode;
            $this->manifestBackupMutationSearch = null;
            $this->manifestBackupMutationReplacement = null;
            $this->substituteManifestBackupInode = false;
            if ($substituteInode) {
                if (!rename($backup, $backup . '.before-inode-swap')) {
                    throw new \RuntimeException('Unable to retain original manifest backup inode.');
                }
                $previousUmask = umask(0077);
                try {
                    $replacementHandle = fopen($backup, 'x+b');
                } finally {
                    umask($previousUmask);
                }
                if ($replacementHandle === false) {
                    throw new \RuntimeException('Unable to substitute manifest backup inode.');
                }
                try {
                    if (fwrite($replacementHandle, $mutated) !== strlen($mutated)
                        || !fflush($replacementHandle)
                    ) {
                        throw new \RuntimeException('Unable to write substituted manifest backup.');
                    }
                } finally {
                    fclose($replacementHandle);
                }
            } elseif (file_put_contents($backup, $mutated) !== strlen($mutated)) {
                throw new \RuntimeException('Unable to mutate manifest backup in place.');
            }
        }

        $moved = parent::move($source, $destination, $operation);
        if ($moved
            && $operation === 'paper_dataset_manifest_publish'
            && $this->terminalCleanupFailure !== null
        ) {
            $this->terminalCleanupFailureArmed = true;
        }
        if ($moved
            && $operation === 'paper_dataset_manifest_publish'
            && $this->terminalMutationBoundary === 'after_rename'
        ) {
            $this->injectTerminalPublicationMutation();
        }
        if ($moved
            && $operation === 'paper_dataset_manifest_publish'
            && $this->manifestPublicationFailureAfterRename !== null
        ) {
            $failure = $this->manifestPublicationFailureAfterRename;
            $this->manifestPublicationFailureAfterRename = null;
            if ($failure === 'throw') {
                throw new \RuntimeException('Injected manifest publication failure after rename.');
            }

            return false;
        }
        if ($moved
            && $operation === 'paper_dataset_manifest_publish'
            && $this->datasetDirectoryToSwapAfterManifestPublication !== null
            && $this->datasetDirectoryReplacementAfterManifestPublication !== null
        ) {
            $path = $this->datasetDirectoryToSwapAfterManifestPublication;
            $replacement = $this->datasetDirectoryReplacementAfterManifestPublication;
            $this->datasetDirectoryToSwapAfterManifestPublication = null;
            $this->datasetDirectoryReplacementAfterManifestPublication = null;
            if (!rename($path, $path . '.directory-original') || !rename($replacement, $path)) {
                throw new \RuntimeException('Unable to inject dataset directory substitution after publication.');
            }
        }
        if ($moved
            && $operation === 'paper_dataset_manifest_publish'
            && $this->datasetDirectoryToSwapDuringManifestSnapshotValidation !== null
            && $this->datasetDirectoryReplacementDuringManifestSnapshotValidation !== null
        ) {
            $this->manifestSnapshotDirectorySwapArmed = true;
        }

        return $moved;
    }

    public function failNextAppend(string $failure): void
    {
        $this->appendFailure = $failure;
        $this->partialWriteCompleted = false;
        $this->appendWriteObserved = false;
    }

    public function failRollback(string $failure): void
    {
        $this->rollbackFailure = $failure;
    }

    public function failNextManifestDirectorySync(): void
    {
        $this->failManifestDirectorySync = true;
    }

    public function failNextManifestBackupDirectorySync(): void
    {
        $this->failManifestBackupDirectorySync = true;
    }

    public function failNextManifestCandidateDirectorySync(): void
    {
        $this->failManifestCandidateDirectorySync = true;
    }

    public function failNextManifestPublicationAfterRename(string $failure): void
    {
        if (!\in_array($failure, ['throw', 'false'], true)) {
            throw new \InvalidArgumentException('Unsupported post-rename publication failure.');
        }
        $this->manifestPublicationFailureAfterRename = $failure;
    }

    public function failNextTerminalCleanupSync(string $artifact): void
    {
        if (!\in_array($artifact, ['backup', 'transition'], true)) {
            throw new \InvalidArgumentException('Unsupported terminal cleanup artifact.');
        }
        $this->terminalCleanupFailure = $artifact;
        $this->terminalCleanupFailureArmed = false;
    }

    public function resetManifestTransitionObservation(): void
    {
        $this->manifestTransitionSyncs = 0;
        $this->manifestTransitionDirectorySyncs = 0;
        $this->observedManifestTransitionMode = null;
        $this->observedManifestCandidateMode = null;
        $this->observedManifestBackupMode = null;
        $this->observedManifestTransition = null;
        $this->observedManifestCandidate = null;
        $this->observedManifestBackup = null;
    }

    public function failNextEventSync(): void
    {
        $this->failEventSync = true;
    }

    public function failNextAppendRecoverySync(): void
    {
        $this->failAppendRecoverySync = true;
    }

    public function failNextFixedArtifactPublication(string $artifact, string $failure): void
    {
        if (!\in_array($artifact, [
            'append_intent',
            'manifest_transition',
            'manifest_backup',
            'manifest_candidate',
        ], true) || !\in_array($failure, [
            'short_write',
            'flush',
            'sync',
            'before_rename',
            'after_rename',
            'after_directory_sync',
        ], true)) {
            throw new \InvalidArgumentException('Unsupported fixed artifact failure.');
        }
        $this->fixedArtifactFailureArtifact = $artifact;
        $this->fixedArtifactFailure = $failure;
        $this->fixedArtifactShortWriteCompleted = false;
    }

    public function overwriteEventsAfterAppendRecoverySync(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $contents,
    ): void {
        $this->appendRecoveryMutationPath = $path;
        $this->appendRecoveryMutationContents = $contents;
    }

    public function overwriteEventsAtTerminalPublication(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] string $contents,
        string $boundary,
    ): void {
        if (!\in_array($boundary, ['before_rename', 'after_rename', 'after_directory_sync'], true)) {
            throw new \InvalidArgumentException('Unsupported terminal mutation boundary.');
        }
        $this->terminalMutationPath = $path;
        $this->terminalMutationContents = $contents;
        $this->terminalMutationBoundary = $boundary;
    }

    public function shortReadNextChecksum(): void
    {
        $this->shortChecksumRead = true;
    }

    public function swapPathOnVerifierEventsValidation(string $path, string $target): void
    {
        $this->verifierEventsPath = $path;
        $this->verifierEventsTarget = $target;
    }

    public function swapEventsPathOnTailValidation(string $path, string $target): void
    {
        $this->zeroTailEventsPath = $path;
        $this->zeroTailEventsTarget = $target;
    }

    public function failTailReadAfterLines(int $lines): void
    {
        $this->tailReadsBeforeFailure = $lines;
    }

    public function overwriteEventsAfterTailParsing(string $path, string $contents): void
    {
        $this->tailMutationPath = $path;
        $this->tailMutationContents = $contents;
        $this->tailMutationSeeks = 0;
    }

    public function swapDatasetDirectoryDuringEventsScan(string $path, string $replacement): void
    {
        $this->datasetDirectoryToSwap = $path;
        $this->datasetDirectoryReplacement = $replacement;
    }

    public function overwriteEventsBeforePublication(string $path, string $contents, string $trigger): void
    {
        $this->publicationMutationPath = $path;
        $this->publicationMutationContents = $contents;
        $this->publicationMutationTrigger = $trigger;
        $this->publicationMutationOffset = 0;
    }

    public function overwriteAppendedLineBeforePublication(string $path, int $offset, string $contents): void
    {
        $this->publicationMutationPath = $path;
        $this->publicationMutationContents = $contents;
        $this->publicationMutationTrigger = 'append';
        $this->publicationMutationOffset = $offset;
    }

    public function overwriteAppendedLineOnSnapshotValidation(
        string $path,
        int $offset,
        string $contents,
        int $validationSeek,
    ): void {
        $this->publicationMutationPath = $path;
        $this->publicationMutationContents = $contents;
        $this->publicationMutationTrigger = 'append_snapshot_validation';
        $this->publicationMutationOffset = $offset;
        $this->publicationSnapshotValidationSeeks = 0;
        $this->publicationSnapshotValidationTarget = $validationSeek;
    }

    /** @param resource $handle */
    public function seek($handle, int $offset, int $whence, string $operation): bool
    {
        if ($this->publicationMutationTrigger === 'append'
            && $this->publicationAppendWriteObserved
            && $operation === 'paper_dataset_events_read_failed'
        ) {
            $this->injectPublicationMutation();
        }
        if ($this->publicationMutationTrigger === 'append_snapshot_validation'
            && $this->publicationAppendWriteObserved
            && $operation === 'paper_dataset_events_snapshot_validation'
        ) {
            ++$this->publicationSnapshotValidationSeeks;
            if ($this->publicationSnapshotValidationSeeks === $this->publicationSnapshotValidationTarget) {
                $this->injectPublicationMutation();
            }
        }
        if ($this->tailMutationPath !== null
            && $this->tailMutationContents !== null
            && $offset === 0
            && $whence === SEEK_SET
            && ($operation === 'paper_dataset_events_read_failed'
                || $operation === 'paper_dataset_events_tail_rehash')
        ) {
            ++$this->tailMutationSeeks;
            if ($operation === 'paper_dataset_events_tail_rehash' || $this->tailMutationSeeks === 2) {
                $path = $this->tailMutationPath;
                $contents = $this->tailMutationContents;
                $this->tailMutationPath = null;
                $this->tailMutationContents = null;
                if (file_put_contents($path, $contents) !== strlen($contents)) {
                    throw new \RuntimeException('Unable to inject tail snapshot mutation.');
                }
            }
        }

        return parent::seek($handle, $offset, $whence, $operation);
    }

    /** @param resource $handle */
    public function readLine($handle, string $operation): string|false
    {
        if ($operation === 'paper_dataset_events_read_failed') {
            $metadata = stream_get_meta_data($handle);
            $this->eventScanModes[] = $metadata['mode'];
        }
        if ($operation === 'paper_dataset_events_read_failed' && $this->tailReadsBeforeFailure !== null) {
            if ($this->tailReadsBeforeFailure === 0) {
                $this->tailReadsBeforeFailure = null;

                return false;
            }
            --$this->tailReadsBeforeFailure;
        }

        return parent::readLine($handle, $operation);
    }

    public function read($handle, int $length, string $operation): string|false
    {
        if ($operation === 'paper_dataset_append_intent_read_failed') {
            ++$this->appendIntentReads;
        }
        if ($operation === 'paper_dataset_manifest_transition_read_failed') {
            ++$this->manifestTransitionReads;
        }

        return parent::read($handle, $length, $operation);
    }

    /**
     * @param resource $handle
     *
     * @return array{checksum: string, bytes: int}
     */
    public function checksum($handle, string $operation): array
    {
        if ($operation === 'paper_dataset_events_checksum_failed'
            && $this->publicationMutationTrigger === 'finalize'
        ) {
            $this->injectPublicationMutation();
        }
        if ($operation === 'paper_dataset_events_checksum_failed' && $this->shortChecksumRead) {
            $this->shortChecksumRead = false;

            return ['checksum' => hash('sha256', ''), 'bytes' => 0];
        }

        return parent::checksum($handle, $operation);
    }

    public function write($handle, #[\SensitiveParameter] string $contents, string $operation): int|false
    {
        $fixedArtifact = $this->fixedArtifactForContentOperation($operation);
        if ($fixedArtifact === $this->fixedArtifactFailureArtifact
            && $this->fixedArtifactFailure === 'short_write'
        ) {
            if (!$this->fixedArtifactShortWriteCompleted) {
                $this->fixedArtifactShortWriteCompleted = true;

                return parent::write(
                    $handle,
                    substr($contents, 0, max(1, intdiv(strlen($contents), 2))),
                    $operation,
                );
            }
            $this->clearFixedArtifactFailure();

            return false;
        }
        if ($operation !== 'paper_dataset_events_write_failed') {
            return parent::write($handle, $contents, $operation);
        }
        $metadata = stream_get_meta_data($handle);
        $eventsPath = $metadata['uri'];
        if (\is_string($eventsPath)) {
            $intentPath = dirname($eventsPath) . '/.append-intent.json';
            $intent = is_file($intentPath) ? file_get_contents($intentPath) : false;
            $mode = is_file($intentPath) ? fileperms($intentPath) : false;
            if ($intent !== false && $mode !== false) {
                $decoded = json_decode($intent, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                if (\is_array($decoded) && !array_is_list($decoded)) {
                    $this->observedAppendIntent = $decoded;
                    $this->observedAppendIntentMode = $mode & 0777;
                }
            }
        }
        if (\in_array($this->publicationMutationTrigger, ['append', 'append_snapshot_validation'], true)) {
            $this->publicationAppendWriteObserved = true;
        }
        if ($this->appendFailure === 'full_write') {
            $this->appendFailure = null;

            return false;
        }
        if ($this->appendFailure === 'partial_write') {
            if (!$this->partialWriteCompleted) {
                $this->partialWriteCompleted = true;

                return parent::write($handle, substr($contents, 0, max(1, intdiv(strlen($contents), 2))), $operation);
            }
            $this->appendFailure = null;

            return false;
        }

        if ($this->appendFailure === 'post_stat') {
            $this->appendWriteObserved = true;
        }

        return parent::write($handle, $contents, $operation);
    }

    public function flush($handle, string $operation): bool
    {
        if ($this->fixedArtifactForContentOperation($operation) === $this->fixedArtifactFailureArtifact
            && $this->fixedArtifactFailure === 'flush'
        ) {
            $this->clearFixedArtifactFailure();

            return false;
        }
        if ($operation === 'paper_dataset_events_flush_failed' && $this->appendFailure === 'flush') {
            $this->appendFailure = null;

            return false;
        }
        if ($operation === 'paper_dataset_events_rollback_failed' && $this->rollbackFailure === 'flush') {
            $this->rollbackFailure = null;

            return false;
        }

        return parent::flush($handle, $operation);
    }

    public function sync($handle, string $operation): bool
    {
        $terminalCleanupOperation = match ($this->terminalCleanupFailure) {
            'backup' => 'paper_dataset_manifest_backup_cleanup_directory_sync',
            'transition' => 'paper_dataset_manifest_transition_directory_sync_failed',
            default => null,
        };
        if ($this->terminalCleanupFailureArmed && $operation === $terminalCleanupOperation) {
            $this->terminalCleanupFailure = null;
            $this->terminalCleanupFailureArmed = false;

            return false;
        }
        if ($this->fixedArtifactForContentOperation($operation) === $this->fixedArtifactFailureArtifact
            && $this->fixedArtifactFailure === 'sync'
        ) {
            $this->clearFixedArtifactFailure();

            return false;
        }
        if ($this->fixedArtifactForDirectorySyncOperation($operation) === $this->fixedArtifactFailureArtifact
            && $this->fixedArtifactFailure === 'after_directory_sync'
        ) {
            $synced = parent::sync($handle, $operation);
            $this->clearFixedArtifactFailure();
            if (!$synced) {
                return false;
            }

            throw new \RuntimeException('Injected crash after fixed artifact directory sync.');
        }
        if ($operation === 'paper_dataset_append_recovery_failed') {
            ++$this->appendRecoverySyncs;
            if ($this->failAppendRecoverySync) {
                $this->failAppendRecoverySync = false;

                return false;
            }
            $synced = parent::sync($handle, $operation);
            if (!$synced) {
                return false;
            }
            if ($this->appendRecoveryMutationPath !== null
                && $this->appendRecoveryMutationContents !== null
            ) {
                $path = $this->appendRecoveryMutationPath;
                $contents = $this->appendRecoveryMutationContents;
                $this->appendRecoveryMutationPath = null;
                $this->appendRecoveryMutationContents = null;
                if (file_put_contents($path, $contents) !== strlen($contents)) {
                    throw new \RuntimeException('Unable to inject recovered append mutation.');
                }
            }

            return true;
        }
        if ($operation === 'paper_dataset_append_intent_flush_failed') {
            ++$this->appendIntentSyncs;
        }
        if ($operation === 'paper_dataset_append_intent_directory_sync_failed') {
            ++$this->appendIntentDirectorySyncs;
        }
        if ($operation === 'paper_dataset_manifest_transition_flush_failed') {
            ++$this->manifestTransitionSyncs;
        }
        if ($operation === 'paper_dataset_manifest_transition_directory_sync_failed') {
            ++$this->manifestTransitionDirectorySyncs;
        }
        if ($operation === 'paper_dataset_manifest_backup_directory_sync') {
            if ($this->failManifestBackupDirectorySync) {
                $this->failManifestBackupDirectorySync = false;

                return false;
            }
        }
        if ($operation === 'paper_dataset_manifest_candidate_directory_sync'
            && $this->failManifestCandidateDirectorySync
        ) {
            $this->failManifestCandidateDirectorySync = false;

            return false;
        }
        if ($operation === 'paper_dataset_directory_parent_sync_failed') {
            $metadata = stream_get_meta_data($handle);
            $path = $metadata['uri'];
            $resolved = realpath($path);
            if ($resolved === false) {
                throw new \RuntimeException('Unable to resolve directory parent sync.');
            }
            $this->directoryParentSyncs[] = $resolved;
            if (\count($this->directoryParentSyncs) === $this->directoryParentSyncFailureAt) {
                $this->directoryParentSyncFailureAt = null;

                return false;
            }
        }
        if ($operation === 'paper_dataset_manifest_directory_sync_failed') {
            ++$this->manifestDirectorySyncs;
            if ($this->failManifestDirectorySync) {
                $this->failManifestDirectorySync = false;

                return false;
            }
            if ($this->terminalMutationBoundary === 'after_directory_sync') {
                $synced = parent::sync($handle, $operation);
                if (!$synced) {
                    return false;
                }
                $this->injectTerminalPublicationMutation();

                return true;
            }
        }
        if ($operation === 'paper_dataset_events_flush_failed') {
            ++$this->eventSyncs;
            if ($this->failEventSync) {
                $this->failEventSync = false;

                return false;
            }
        }
        if ($operation === 'paper_dataset_events_flush_failed' && $this->appendFailure === 'sync') {
            $this->appendFailure = null;

            return false;
        }
        if ($operation === 'paper_dataset_events_rollback_failed' && $this->rollbackFailure === 'sync') {
            $this->rollbackFailure = null;

            return false;
        }

        return parent::sync($handle, $operation);
    }

    private function injectPublicationMutation(): void
    {
        $path = $this->publicationMutationPath;
        $contents = $this->publicationMutationContents;
        $offset = $this->publicationMutationOffset;
        $this->publicationMutationPath = null;
        $this->publicationMutationContents = null;
        $this->publicationMutationTrigger = null;
        $this->publicationMutationOffset = 0;
        $this->publicationAppendWriteObserved = false;
        $this->publicationSnapshotValidationSeeks = 0;
        $this->publicationSnapshotValidationTarget = null;
        if ($path === null || $contents === null) {
            throw new \RuntimeException('Unable to inject publication snapshot mutation.');
        }
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to inject publication snapshot mutation.');
        }
        try {
            if (fseek($handle, $offset, SEEK_SET) !== 0) {
                throw new \RuntimeException('Unable to inject publication snapshot mutation.');
            }
            $written = fwrite($handle, $contents);
            if ($written !== strlen($contents) || !fflush($handle)) {
                throw new \RuntimeException('Unable to inject publication snapshot mutation.');
            }
        } finally {
            fclose($handle);
        }
        if (filesize($path) < $offset + strlen($contents)) {
            throw new \RuntimeException('Unable to inject publication snapshot mutation.');
        }
    }

    private function injectTerminalPublicationMutation(): void
    {
        $path = $this->terminalMutationPath;
        $contents = $this->terminalMutationContents;
        $this->terminalMutationPath = null;
        $this->terminalMutationContents = null;
        $this->terminalMutationBoundary = null;
        if ($path === null || $contents === null
            || file_put_contents($path, $contents) !== strlen($contents)
        ) {
            throw new \RuntimeException('Unable to inject terminal publication mutation.');
        }
    }

    public function stat($handle, string $operation): array|false
    {
        if ($operation === 'paper_dataset_manifest_snapshot_validation'
            && $this->manifestSnapshotDirectorySwapArmed
            && $this->datasetDirectoryToSwapDuringManifestSnapshotValidation !== null
            && $this->datasetDirectoryReplacementDuringManifestSnapshotValidation !== null
        ) {
            $statistics = parent::stat($handle, $operation);
            $path = $this->datasetDirectoryToSwapDuringManifestSnapshotValidation;
            $replacement = $this->datasetDirectoryReplacementDuringManifestSnapshotValidation;
            $this->datasetDirectoryToSwapDuringManifestSnapshotValidation = null;
            $this->datasetDirectoryReplacementDuringManifestSnapshotValidation = null;
            $this->manifestSnapshotDirectorySwapArmed = false;
            if (!rename($path, $path . '.directory-original') || !rename($replacement, $path)) {
                throw new \RuntimeException('Unable to inject dataset directory substitution during manifest validation.');
            }

            return $statistics;
        }
        if ($operation === 'paper_dataset_events_read_failed'
            && $this->datasetDirectoryToSwap !== null
            && $this->datasetDirectoryReplacement !== null
        ) {
            $statistics = parent::stat($handle, $operation);
            $path = $this->datasetDirectoryToSwap;
            $replacement = $this->datasetDirectoryReplacement;
            $this->datasetDirectoryToSwap = null;
            $this->datasetDirectoryReplacement = null;
            if (!rename($path, $path . '.directory-original') || !rename($replacement, $path)) {
                throw new \RuntimeException('Unable to inject dataset directory substitution.');
            }

            return $statistics;
        }
        if ($operation === 'paper_dataset_events_tail_validation'
            && $this->zeroTailEventsPath !== null
            && $this->zeroTailEventsTarget !== null
        ) {
            $statistics = parent::stat($handle, $operation);
            $path = $this->zeroTailEventsPath;
            $target = $this->zeroTailEventsTarget;
            $this->zeroTailEventsPath = null;
            $this->zeroTailEventsTarget = null;
            if (!rename($path, $path . '.zero-tail-original') || !rename($target, $path)) {
                throw new \RuntimeException('Unable to inject zero-tail path substitution.');
            }

            return $statistics;
        }
        if ($operation === 'paper_dataset_verifier_events_validation'
            && $this->verifierEventsPath !== null
            && $this->verifierEventsTarget !== null
        ) {
            $statistics = parent::stat($handle, $operation);
            $path = $this->verifierEventsPath;
            $target = $this->verifierEventsTarget;
            $this->verifierEventsPath = null;
            $this->verifierEventsTarget = null;
            if (!rename($path, $path . '.verification-original') || !symlink($target, $path)) {
                throw new \RuntimeException('Unable to inject verifier path substitution.');
            }

            return $statistics;
        }
        if ($operation === 'paper_dataset_events_read_failed'
            && $this->appendFailure === 'post_stat'
            && $this->appendWriteObserved
        ) {
            $this->appendFailure = null;
            $this->appendWriteObserved = false;

            return false;
        }

        return parent::stat($handle, $operation);
    }

    public function truncate($handle, int $size, string $operation): bool
    {
        if ($operation === 'paper_dataset_events_rollback_failed') {
            ++$this->rollbackTruncations;
            if ($this->rollbackFailure === 'truncate') {
                $this->rollbackFailure = null;

                return false;
            }
        }

        return parent::truncate($handle, $size, $operation);
    }

    private function fixedArtifactForContentOperation(string $operation): ?string
    {
        return match ($operation) {
            'paper_dataset_append_intent_flush_failed' => 'append_intent',
            'paper_dataset_manifest_transition_flush_failed' => 'manifest_transition',
            'paper_dataset_manifest_backup_failed' => 'manifest_backup',
            'paper_dataset_manifest_flush_failed' => 'manifest_candidate',
            default => null,
        };
    }

    private function fixedArtifactForPublishOperation(string $operation): ?string
    {
        return match ($operation) {
            'paper_dataset_append_intent_publish' => 'append_intent',
            'paper_dataset_manifest_transition_publish' => 'manifest_transition',
            'paper_dataset_manifest_backup_publish' => 'manifest_backup',
            'paper_dataset_manifest_candidate_publish' => 'manifest_candidate',
            default => null,
        };
    }

    private function fixedArtifactForDirectorySyncOperation(string $operation): ?string
    {
        return match ($operation) {
            'paper_dataset_append_intent_directory_sync_failed' => 'append_intent',
            'paper_dataset_manifest_transition_directory_sync_failed' => 'manifest_transition',
            'paper_dataset_manifest_backup_directory_sync' => 'manifest_backup',
            'paper_dataset_manifest_candidate_directory_sync' => 'manifest_candidate',
            default => null,
        };
    }

    private function clearFixedArtifactFailure(): void
    {
        $this->fixedArtifactFailureArtifact = null;
        $this->fixedArtifactFailure = null;
        $this->fixedArtifactShortWriteCompleted = false;
    }
}
