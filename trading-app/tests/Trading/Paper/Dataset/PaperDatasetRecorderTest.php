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
            self::assertSame('paper_dataset_events_snapshot_changed', $exception->getMessage());
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

    public function testReplacedTransactionLockCannotSplitExclusionOrDowngradeACompleteManifest(): void
    {
        $manifest = $this->manifest();
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $stale = new PaperDatasetRecorder(
            $this->datasetRoot(),
            $manifest,
            filesystem: $filesystem,
        );
        $lockPath = $this->datasetDirectory() . '/.dataset.lock';
        $filesystem->replaceLockBeforeNextManifestRewrite(
            $lockPath,
            $this->datasetDirectory() . '/manifest.json',
            function () use ($manifest): void {
                $winner = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
                $winner->complete();
            },
        );

        try {
            $stale->append($this->event(sequence: '1'));
            self::fail('A recorder holding a replaced lock inode must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_file_changed', $exception->getMessage());
        }

        $storedJson = file_get_contents($this->datasetDirectory() . '/manifest.json');
        self::assertIsString($storedJson);
        $stored = (new PaperDatasetManifestCodec())->decode($storedJson);
        self::assertSame(PaperDatasetState::COMPLETE, $stored->state);
        self::assertSame(1, $stored->eventCount);
        self::assertEquals($stored, (new PaperDatasetVerifier())->verify($this->datasetDirectory()));
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

    public function testExistingRootCannotBeSwappedThroughPathBasedDirectoryChmod(): void
    {
        $root = $this->testRoot . '/existing-paper-root';
        $target = $this->testRoot . '/external-directory-target';
        self::assertTrue(mkdir($root, 0750));
        self::assertTrue(chmod($root, 0750));
        self::assertTrue(mkdir($target, 0750));
        self::assertTrue(chmod($target, 0750));
        $filesystem = new FaultInjectingPaperDatasetFilesystem();
        $filesystem->swapDirectoryDuringChangeMode($root, $target);

        try {
            new PaperDatasetRecorder($root, $this->manifest(), filesystem: $filesystem);
            self::fail('An existing directory requiring path-based chmod must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_directory_mode_failed', $exception->getMessage());
        }

        self::assertTrue(is_dir($root));
        self::assertFalse(is_link($root));
        self::assertSame(0750, fileperms($target) & 0777);
        self::assertDirectoryDoesNotExist($target . '/dataset-okx-001');
        self::assertDirectoryDoesNotExist($root . '.before-mode-swap');
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
            self::assertSame('paper_dataset_symlink_rejected', $exception->getMessage());
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
            self::assertSame('paper_dataset_events_flush_failed', $exception->getMessage());
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
        self::assertSame('paper_dataset_checksum_failed', $failure->getMessage());
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
        self::assertSame('paper_dataset_symlink_rejected', $failure->getMessage());
        self::assertSame($eventsBefore, file_get_contents($targetPath));
    }

    public function testManifestRewriteFailurePoisonsInstanceAndRestartRescansDurableAppend(): void
    {
        $manifest = $this->manifest();
        $recorder = new PaperDatasetRecorder($this->datasetRoot(), $manifest);
        $recorder->append($this->event(sequence: '1'));
        $second = $this->event(sequence: '2', microseconds: 2);
        self::assertTrue(chmod($this->datasetDirectory(), 0500));

        try {
            $recorder->append($second);
            self::fail('The manifest rewrite must fail in a read-only directory.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_manifest_write_failed', $exception->getMessage());
        } finally {
            self::assertTrue(chmod($this->datasetDirectory(), 0700));
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
            self::assertSame('paper_dataset_manifest_directory_sync_failed', $exception->getMessage());
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
                    if (!preg_match('/(?:root|path|directory|parent)/i', $parameter->getName())) {
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
    public int $eventSyncs = 0;

    /** @var list<string> */
    public array $createdDirectories = [];

    /** @var list<string> */
    public array $directoryParentSyncs = [];

    private ?string $appendFailure = null;
    private bool $partialWriteCompleted = false;
    private bool $appendWriteObserved = false;
    private ?string $rollbackFailure = null;
    private bool $failManifestDirectorySync = false;
    private bool $failEventSync = false;
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
    private ?string $lockPathToReplace = null;
    private ?string $manifestPathBeforeLockReplacement = null;
    private ?\Closure $afterLockReplacement = null;
    private bool $lockReplacementEventWriteCompleted = false;
    private ?string $directoryToSwapDuringChangeMode = null;
    private ?string $directoryChangeModeTarget = null;

    public function createDirectory(#[\SensitiveParameter] string $directory, int $permissions): bool
    {
        $this->createdDirectories[] = $directory;

        return @mkdir($directory, $permissions);
    }

    public function failDirectoryParentSyncAt(int $sync): void
    {
        $this->directoryParentSyncFailureAt = $sync;
    }

    public function replaceLockBeforeNextManifestRewrite(
        #[\SensitiveParameter] string $lockPath,
        #[\SensitiveParameter] string $manifestPath,
        callable $afterReplacement,
    ): void {
        $this->lockPathToReplace = $lockPath;
        $this->manifestPathBeforeLockReplacement = $manifestPath;
        $this->afterLockReplacement = $afterReplacement(...);
    }

    public function swapDirectoryDuringChangeMode(
        #[\SensitiveParameter] string $directory,
        #[\SensitiveParameter] string $target,
    ): void {
        $this->directoryToSwapDuringChangeMode = $directory;
        $this->directoryChangeModeTarget = $target;
    }

    public function changeMode(#[\SensitiveParameter] string $path, int $permissions): bool
    {
        if ($this->directoryToSwapDuringChangeMode === $path
            && $this->directoryChangeModeTarget !== null
        ) {
            $target = $this->directoryChangeModeTarget;
            $this->directoryToSwapDuringChangeMode = null;
            $this->directoryChangeModeTarget = null;
            if (!rename($path, $path . '.before-mode-swap') || !symlink($target, $path)) {
                throw new \RuntimeException('Unable to inject directory mode swap.');
            }
        }

        return parent::changeMode($path, $permissions);
    }

    /** @return array<string, mixed>|false */
    public function pathStat(#[\SensitiveParameter] string $path, string $operation): array|false
    {
        if ($this->lockReplacementEventWriteCompleted
            && $this->lockPathToReplace !== null
            && $this->manifestPathBeforeLockReplacement === $path
            && $this->afterLockReplacement !== null
        ) {
            $lockPath = $this->lockPathToReplace;
            $afterReplacement = $this->afterLockReplacement;
            $this->lockPathToReplace = null;
            $this->manifestPathBeforeLockReplacement = null;
            $this->afterLockReplacement = null;
            $this->lockReplacementEventWriteCompleted = false;
            if (!unlink($lockPath)) {
                throw new \RuntimeException('Unable to remove the transaction lock for replacement.');
            }
            $replacement = fopen($lockPath, 'x+b');
            if ($replacement === false) {
                throw new \RuntimeException('Unable to replace the transaction lock.');
            }
            fclose($replacement);
            if (!chmod($lockPath, 0600)) {
                throw new \RuntimeException('Unable to protect the replacement transaction lock.');
            }
            $afterReplacement();
        }

        return parent::pathStat($path, $operation);
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

    public function failNextEventSync(): void
    {
        $this->failEventSync = true;
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
        if ($operation === 'paper_dataset_events_read_failed' && $this->tailReadsBeforeFailure !== null) {
            if ($this->tailReadsBeforeFailure === 0) {
                $this->tailReadsBeforeFailure = null;

                return false;
            }
            --$this->tailReadsBeforeFailure;
        }

        return parent::readLine($handle, $operation);
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
        if ($operation !== 'paper_dataset_events_write_failed') {
            return parent::write($handle, $contents, $operation);
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

        $written = parent::write($handle, $contents, $operation);
        if ($this->lockPathToReplace !== null) {
            $this->lockReplacementEventWriteCompleted = true;
        }

        return $written;
    }

    public function flush($handle, string $operation): bool
    {
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

    public function stat($handle, string $operation): array|false
    {
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
}
