<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Trading\Paper\Dataset\PaperDatasetAppendResult;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperDatasetRecorder::class)]
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

    private function manifest(): PaperDatasetManifest
    {
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
            quality: PaperMarketDataQuality::RECORDED_PUBLIC_BOOK_AND_TRADES,
            modelName: null,
            modelVersion: null,
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
