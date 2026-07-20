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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('paper_dataset_events_size_regressed');

        $recorder->append($this->event(sequence: '2', microseconds: 2));
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

    private function datasetRoot(): string
    {
        return $this->testRoot . '/paper-market-data';
    }

    private function datasetDirectory(): string
    {
        return $this->datasetRoot() . '/dataset-okx-001';
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

    private ?string $appendFailure = null;
    private bool $partialWriteCompleted = false;
    private int $appendStatCalls = 0;
    private ?string $rollbackFailure = null;
    private bool $failManifestDirectorySync = false;
    private bool $failEventSync = false;
    private bool $shortChecksumRead = false;
    private ?string $verifierEventsPath = null;
    private ?string $verifierEventsTarget = null;

    public function failNextAppend(string $failure): void
    {
        $this->appendFailure = $failure;
        $this->partialWriteCompleted = false;
        $this->appendStatCalls = 0;
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

    /**
     * @param resource $handle
     *
     * @return array{checksum: string, bytes: int}
     */
    public function checksum($handle, string $operation): array
    {
        if ($operation === 'paper_dataset_events_checksum_failed' && $this->shortChecksumRead) {
            $this->shortChecksumRead = false;

            return ['checksum' => hash('sha256', ''), 'bytes' => 0];
        }

        return parent::checksum($handle, $operation);
    }

    public function write($handle, string $contents, string $operation): int|false
    {
        if ($operation !== 'paper_dataset_events_write_failed') {
            return parent::write($handle, $contents, $operation);
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

        return parent::write($handle, $contents, $operation);
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

    public function stat($handle, string $operation): array|false
    {
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
        if ($operation === 'paper_dataset_events_read_failed' && $this->appendFailure === 'post_stat') {
            ++$this->appendStatCalls;
            if ($this->appendStatCalls === 2) {
                $this->appendFailure = null;

                return false;
            }
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
