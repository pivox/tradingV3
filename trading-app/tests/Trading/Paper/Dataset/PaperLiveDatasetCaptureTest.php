<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Dataset;

use App\Trading\Paper\Dataset\PaperDatasetAppendResult;
use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\Dataset\PaperLiveDatasetCapture;
use App\Trading\Paper\Dataset\PaperLiveEventConsumerInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataQuality;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

if (($argv[1] ?? null) === '--paper-live-crash-harness') {
    require_once \dirname(__DIR__, 4) . '/vendor/autoload.php';
}

#[CoversClass(PaperLiveDatasetCapture::class)]
final class PaperLiveDatasetCaptureTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'paper-live-capture-test-');
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

    public function testAppendedEventIsDurableBeforeConsumerAndAcknowledgedAfterward(): void
    {
        $datasetId = 'dataset-live-appended';
        $recorder = $this->recorder($datasetId);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        $order = new CaptureOrderLog();
        $sourceState = new CaptureSourceState([$event], order: $order);
        $source = captureTestSource($sourceState);
        $consumerState = new CaptureConsumerState($recorder->datasetDirectory(), order: $order);
        $consumer = captureTestConsumer($consumerState);
        $source->requestHealthyOperatorStop();

        $manifest = (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(1, $manifest->eventCount);
        self::assertSame(1, $consumerState->consumeCount);
        self::assertSame(1, $consumerState->effectCount);
        self::assertSame(1, $sourceState->acknowledgementCount);
        self::assertSame(
            ['consume:public_trade', 'acknowledge:public_trade'],
            $order->entries,
        );
    }

    public function testConsumerRejectsManifestWhoseLastEventDoesNotMatchCurrentEvent(): void
    {
        $datasetId = 'dataset-live-stale-manifest';
        $recorder = $this->recorder($datasetId);
        $current = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        $later = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '2', microseconds: 2);
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($current));
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($later));
        $consumerState = new CaptureConsumerState($recorder->datasetDirectory());

        try {
            captureTestConsumer($consumerState)->consume($datasetId, $current);
            self::fail('A manifest ending at another event must not prove current-event durability.');
        } catch (\RuntimeException $exception) {
            self::assertSame('capture_test_event_not_durable', $exception->getMessage());
        }

        self::assertSame(0, $consumerState->effectCount);
    }

    public function testConsumerRejectsManifestCountThatIncludesInvalidNdjsonLine(): void
    {
        $datasetId = 'dataset-live-invalid-ndjson-count';
        $recorder = $this->recorder($datasetId);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($event));
        $eventsPath = $recorder->datasetDirectory() . '/events.ndjson';
        self::assertNotFalse(file_put_contents($eventsPath, "not-json\n", FILE_APPEND));
        $manifestPath = $recorder->datasetDirectory() . '/manifest.json';
        $manifest = $this->decodeJsonFile($manifestPath);
        $manifest['event_count'] = 2;
        self::assertNotFalse(file_put_contents(
            $manifestPath,
            CanonicalJson::encode($manifest) . "\n",
        ));
        $consumerState = new CaptureConsumerState($recorder->datasetDirectory());

        try {
            captureTestConsumer($consumerState)->consume($datasetId, $event);
            self::fail('Invalid NDJSON lines must not satisfy the durable manifest event count.');
        } catch (\RuntimeException $exception) {
            self::assertSame('capture_test_event_not_durable', $exception->getMessage());
        }

        self::assertSame(0, $consumerState->effectCount);
    }

    public function testReplayedEventStillCrossesIdempotentConsumerBeforeAcknowledgement(): void
    {
        $datasetId = 'dataset-live-replayed';
        $filesystem = new CaptureFaultInjectingFilesystem();
        $recorder = $this->recorder($datasetId, $filesystem);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($event));
        $filesystem->armReplayAppendObservation();
        $eventsPath = $recorder->datasetDirectory() . '/events.ndjson';
        $eventsBefore = file_get_contents($eventsPath);
        self::assertIsString($eventsBefore);
        $checksumBefore = hash('sha256', $eventsBefore);
        $order = new CaptureOrderLog();
        $sourceState = new CaptureSourceState([$event], order: $order);
        $source = captureTestSource($sourceState);
        $consumerState = new CaptureConsumerState(
            $recorder->datasetDirectory(),
            checkpoints: [$datasetId . '/' . $event->eventId => $event->payloadHash],
            effectCount: 1,
            order: $order,
            afterDurability: static function () use ($filesystem): void {
                $filesystem->assertReplayAppendObserved();
            },
        );
        $consumer = captureTestConsumer($consumerState);
        $source->requestHealthyOperatorStop();

        $manifest = (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(1, $manifest->eventCount);
        self::assertSame($checksumBefore, $manifest->eventsFileSha256);
        self::assertSame($eventsBefore, file_get_contents($eventsPath));
        self::assertSame(1, $consumerState->consumeCount);
        self::assertSame(1, $consumerState->effectCount);
        self::assertSame(1, $sourceState->acknowledgementCount);
        self::assertSame(
            ['consume:public_trade', 'acknowledge:public_trade'],
            $order->entries,
        );
    }

    public function testExactExchangeRetryIsConsumedIdempotentlyAndAcknowledgedTwice(): void
    {
        $recorder = $this->recorder('dataset-live-exact-retry');
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        $eventsPath = $recorder->datasetDirectory() . '/events.ndjson';
        /** @var list<string> $recorderBytes */
        $recorderBytes = [];
        /** @var list<string> $recorderChecksums */
        $recorderChecksums = [];
        $sourceState = new CaptureSourceState([$event, $event]);
        $source = captureTestSource($sourceState);
        $consumerState = new CaptureConsumerState(
            $recorder->datasetDirectory(),
            afterDurability: static function () use (
                $eventsPath,
                &$recorderBytes,
                &$recorderChecksums,
            ): void {
                $bytes = file_get_contents($eventsPath);
                self::assertIsString($bytes);
                $recorderBytes[] = $bytes;
                $recorderChecksums[] = hash('sha256', $bytes);
            },
        );
        $source->requestHealthyOperatorStop();

        $manifest = (new PaperLiveDatasetCapture())->run(
            $recorder,
            $source,
            captureTestConsumer($consumerState),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame(1, $manifest->eventCount);
        self::assertSame(2, $consumerState->consumeCount);
        self::assertSame(1, $consumerState->effectCount);
        self::assertSame(2, $sourceState->acknowledgementCount);
        self::assertCount(1, $consumerState->checkpoints);
        self::assertCount(2, $recorderBytes);
        self::assertCount(1, array_unique($recorderBytes));
        self::assertIsString($manifest->eventsFileSha256);
        self::assertSame(
            [$manifest->eventsFileSha256, $manifest->eventsFileSha256],
            $recorderChecksums,
        );
    }

    public function testRecorderConflictNeverInvokesConsumerAndLeavesDatasetIncomplete(): void
    {
        $recorder = $this->recorder('dataset-live-recorder-conflict');
        $recorded = self::event(PaperMarketDataChannel::TOP_OF_BOOK, '1');
        self::assertSame(PaperDatasetAppendResult::APPENDED, $recorder->append($recorded));
        $conflicting = self::event(
            PaperMarketDataChannel::TOP_OF_BOOK,
            '1',
            ['ask' => '31001.0', 'bid' => '30999.0'],
        );
        self::assertSame($recorded->eventId, $conflicting->eventId);
        self::assertNotSame($recorded->payloadHash, $conflicting->payloadHash);
        $sourceState = new CaptureSourceState([$conflicting]);
        $consumerState = new CaptureConsumerState($recorder->datasetDirectory());

        try {
            (new PaperLiveDatasetCapture())->run(
                $recorder,
                captureTestSource($sourceState),
                captureTestConsumer($consumerState),
            );
            self::fail('A conflicting recorder identity must fail capture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame(0, $consumerState->consumeCount);
        self::assertSame(0, $sourceState->acknowledgementCount);
        self::assertSame(1, $sourceState->stopCount);
        $this->assertIncomplete($recorder);
    }

    public function testConsumerConflictIsRethrownAndLeavesDatasetIncomplete(): void
    {
        $datasetId = 'dataset-live-consumer-conflict';
        $recorder = $this->recorder($datasetId);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        $sourceState = new CaptureSourceState([$event]);
        $consumerState = new CaptureConsumerState(
            $recorder->datasetDirectory(),
            checkpoints: [$datasetId . '/' . $event->eventId => str_repeat('a', 64)],
            effectCount: 1,
        );

        try {
            (new PaperLiveDatasetCapture())->run(
                $recorder,
                captureTestSource($sourceState),
                captureTestConsumer($consumerState),
            );
            self::fail('A conflicting consumer identity must fail capture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('market_event_identity_conflict', $exception->getMessage());
        }

        self::assertSame(1, $consumerState->consumeCount);
        self::assertSame(1, $consumerState->effectCount);
        self::assertSame(0, $sourceState->acknowledgementCount);
        self::assertSame(1, $sourceState->stopCount);
        $this->assertIncomplete($recorder);
    }

    public function testEveryEventKindIsAppendedBeforeConsumptionAndAcknowledgement(): void
    {
        $recorder = $this->recorder('dataset-live-event-kinds');
        $events = [
            self::event(
                PaperMarketDataChannel::CONNECTION_STATE,
                '1',
                ['native_symbol' => 'BTC-USDT-SWAP', 'state' => 'connected', 'connection_epoch' => 1],
            ),
            self::event(
                PaperMarketDataChannel::SNAPSHOT_BOUNDARY,
                '1',
                [
                    'native_symbol' => 'BTC-USDT-SWAP',
                    'reason' => 'initial',
                    'source_epoch' => 1,
                    'source_seq_id' => '1',
                ],
                microseconds: 2,
            ),
            self::event(
                PaperMarketDataChannel::TOP_OF_BOOK,
                '1',
                ['ask' => '30001.0', 'bid' => '29999.0'],
                microseconds: 3,
            ),
        ];
        $order = new CaptureOrderLog();
        $sourceState = new CaptureSourceState($events, order: $order);
        $source = captureTestSource($sourceState);
        $consumerState = new CaptureConsumerState($recorder->datasetDirectory(), order: $order);
        $source->requestHealthyOperatorStop();

        $manifest = (new PaperLiveDatasetCapture())->run(
            $recorder,
            $source,
            captureTestConsumer($consumerState),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $manifest->state);
        self::assertSame([
            'consume:connection_state',
            'acknowledge:connection_state',
            'consume:snapshot_boundary',
            'acknowledge:snapshot_boundary',
            'consume:top_of_book',
            'acknowledge:top_of_book',
        ], $order->entries);
    }

    public function testCrashAfterAppendBeforeConsumerEffectRecoversThroughReplay(): void
    {
        $this->assertCrashWindowRecovery(
            mode: 'before_effect',
            datasetId: 'dataset-live-crash-before-effect',
            expectedExitCode: 71,
            effectCommittedBeforeRestart: false,
        );
    }

    public function testCrashAfterConsumerEffectBeforeAcknowledgementDoesNotRepeatEffect(): void
    {
        $this->assertCrashWindowRecovery(
            mode: 'after_effect',
            datasetId: 'dataset-live-crash-after-effect',
            expectedExitCode: 72,
            effectCommittedBeforeRestart: true,
        );
    }

    public function testSourceConsumerAndAcknowledgementFailuresStopAndMarkIncomplete(): void
    {
        foreach (['source', 'consumer', 'acknowledgement'] as $stage) {
            $datasetId = 'dataset-live-' . $stage . '-failure';
            $recorder = $this->recorder($datasetId);
            $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
            $failure = new \RuntimeException('original_' . $stage . '_failure');
            $sourceState = new CaptureSourceState(
                [$event],
                eventsFailure: $stage === 'source' ? $failure : null,
                acknowledgementFailure: $stage === 'acknowledgement' ? $failure : null,
            );
            $consumerState = new CaptureConsumerState(
                $recorder->datasetDirectory(),
                failure: $stage === 'consumer' ? $failure : null,
            );

            try {
                (new PaperLiveDatasetCapture())->run(
                    $recorder,
                    captureTestSource($sourceState),
                    captureTestConsumer($consumerState),
                );
                self::fail(sprintf('The %s failure must escape capture.', $stage));
            } catch (\Throwable $caught) {
                self::assertSame($failure, $caught);
            }

            self::assertSame(1, $sourceState->stopCount);
            $this->assertIncomplete($recorder);
        }
    }

    public function testAppendFailureStopsAndMarksIncomplete(): void
    {
        $filesystem = new CaptureFaultInjectingFilesystem();
        $recorder = $this->recorder('dataset-live-append-failure', $filesystem);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        $failure = new \RuntimeException('original_append_failure');
        $filesystem->failNextAppend($failure);
        $sourceState = new CaptureSourceState([$event]);

        try {
            (new PaperLiveDatasetCapture())->run(
                $recorder,
                captureTestSource($sourceState),
                captureTestConsumer(new CaptureConsumerState($recorder->datasetDirectory())),
            );
            self::fail('The append failure must escape capture.');
        } catch (\Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $sourceState->stopCount);
        $this->assertIncomplete($recorder);
    }

    public function testAmbiguousAppendFailurePreservesOriginalAndRecoveryEvidenceWhenRecorderIsUnusable(): void
    {
        $filesystem = new CaptureFaultInjectingFilesystem();
        $recorder = $this->recorder('dataset-live-ambiguous-append-failure', $filesystem);
        $datasetDirectory = $recorder->datasetDirectory();
        $manifestPath = $datasetDirectory . '/manifest.json';
        $manifestBefore = file_get_contents($manifestPath);
        self::assertIsString($manifestBefore);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        $publicationFailure = new \RuntimeException('append_manifest_publication_ambiguous');
        $filesystem->failNextRecordingManifestPublicationAfterRename($publicationFailure);
        $sourceState = new CaptureSourceState([$event]);
        $consumerState = new CaptureConsumerState($datasetDirectory);
        $capture = new PaperLiveDatasetCapture();

        try {
            $capture->run(
                $recorder,
                captureTestSource($sourceState),
                captureTestConsumer($consumerState),
            );
            self::fail('An ambiguous append failure must fail capture.');
        } catch (\RuntimeException $caught) {
            self::assertSame('paper_live_capture_incomplete_persist_failed', $caught->getMessage());
            $appendFailure = $caught->getPrevious();
            self::assertInstanceOf(\RuntimeException::class, $appendFailure);
            self::assertSame('paper_dataset_manifest_write_failed', $appendFailure->getMessage());
            self::assertSame($publicationFailure, self::rootPrevious($appendFailure));
        }

        self::assertSame(1, $sourceState->stopCount);
        self::assertSame(0, $consumerState->consumeCount);
        self::assertSame(0, $sourceState->acknowledgementCount);
        $incompleteFailure = $this->privateFailure($capture, 'lastIncompletePersistenceFailure');
        self::assertInstanceOf(\RuntimeException::class, $incompleteFailure);
        self::assertSame('paper_dataset_mark_incomplete_failed', $incompleteFailure->getMessage());
        self::assertSame('paper_dataset_recorder_unusable', $incompleteFailure->getPrevious()?->getMessage());

        $manifestAfter = file_get_contents($manifestPath);
        self::assertIsString($manifestAfter);
        $decodedManifest = json_decode($manifestAfter, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decodedManifest);
        self::assertSame(PaperDatasetState::RECORDING->value, $decodedManifest['state'] ?? null);
        self::assertSame(1, $decodedManifest['event_count'] ?? null);
        self::assertFileExists($datasetDirectory . '/.manifest-transition.json');
        self::assertFileExists($datasetDirectory . '/.manifest-backup-fixed');
        self::assertSame($manifestBefore, file_get_contents($datasetDirectory . '/.manifest-backup-fixed'));
    }

    public function testAbnormalGeneratorEndStopsAndReturnsIncompleteManifest(): void
    {
        $recorder = $this->recorder('dataset-live-abnormal-end');
        $sourceState = new CaptureSourceState([
            self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1'),
        ]);

        $manifest = (new PaperLiveDatasetCapture())->run(
            $recorder,
            captureTestSource($sourceState),
            captureTestConsumer(new CaptureConsumerState($recorder->datasetDirectory())),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(PaperMarketDataQuality::INCOMPLETE, $manifest->quality);
        self::assertSame(1, $sourceState->stopCount);
        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        try {
            $recorder->complete();
            self::fail('An incomplete capture must never become complete afterward.');
        } catch (\RuntimeException $exception) {
            self::assertSame('paper_dataset_complete_failed', $exception->getMessage());
        }
    }

    public function testOnlyExplicitHealthyStopCompletesCapture(): void
    {
        $healthyRecorder = $this->recorder('dataset-live-healthy-stop');
        $healthyState = new CaptureSourceState([
            self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1'),
        ]);
        $healthySource = captureTestSource($healthyState);
        $healthySource->requestHealthyOperatorStop();

        $healthy = (new PaperLiveDatasetCapture())->run(
            $healthyRecorder,
            $healthySource,
            captureTestConsumer(new CaptureConsumerState($healthyRecorder->datasetDirectory())),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $healthy->state);
        self::assertSame(0, $healthyState->stopCount);

        $failedRecorder = $this->recorder('dataset-live-failed-source');
        $failedState = new CaptureSourceState(
            [],
            eventsFailure: new \RuntimeException('source_failed'),
            failureReason: 'source_failed',
        );
        try {
            (new PaperLiveDatasetCapture())->run(
                $failedRecorder,
                captureTestSource($failedState),
                captureTestConsumer(new CaptureConsumerState($failedRecorder->datasetDirectory())),
            );
            self::fail('A failed source must not complete capture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('source_failed', $exception->getMessage());
        }
        $this->assertIncomplete($failedRecorder);
    }

    public function testAmbiguousCompleteFailureNeverAttemptsIncompleteTerminalIntent(): void
    {
        $filesystem = new CaptureFaultInjectingFilesystem();
        $recorder = $this->recorder('dataset-live-ambiguous-complete-failure', $filesystem);
        $terminalFailure = new \RuntimeException('complete_manifest_publication_ambiguous');
        $filesystem->failNextTerminalPublicationAfterRename($terminalFailure);
        $sourceState = new CaptureSourceState([
            self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1'),
        ]);
        $source = captureTestSource($sourceState);
        $source->requestHealthyOperatorStop();
        $capture = new PaperLiveDatasetCapture();

        try {
            $capture->run(
                $recorder,
                $source,
                captureTestConsumer(new CaptureConsumerState($recorder->datasetDirectory())),
            );
            self::fail('An ambiguous complete failure must escape capture.');
        } catch (\RuntimeException $caught) {
            self::assertSame('paper_dataset_complete_failed', $caught->getMessage());
            self::assertSame($terminalFailure, self::rootPrevious($caught));
        }

        self::assertSame(0, $sourceState->stopCount);
        self::assertSame(0, $filesystem->successfulIncompletePublicationCount);
        $manifestContents = file_get_contents($recorder->datasetDirectory() . '/manifest.json');
        self::assertIsString($manifestContents);
        $manifest = json_decode($manifestContents, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(PaperDatasetState::COMPLETE->value, $manifest['state'] ?? null);
        self::assertFileExists($recorder->datasetDirectory() . '/.manifest-transition.json');
        self::assertFileExists($recorder->datasetDirectory() . '/.manifest-backup-fixed');
    }

    public function testThrowingEmergencyStopIsRetainedWhileIncompleteManifestIsReturned(): void
    {
        $recorder = $this->recorder('dataset-live-stop-failure');
        $stopFailure = new \RuntimeException('emergency_stop_failed');
        $sourceState = new CaptureSourceState([], stopFailure: $stopFailure);
        $capture = new PaperLiveDatasetCapture();

        $manifest = $capture->run(
            $recorder,
            captureTestSource($sourceState),
            captureTestConsumer(new CaptureConsumerState($recorder->datasetDirectory())),
        );

        self::assertSame(PaperDatasetState::INCOMPLETE, $manifest->state);
        self::assertSame(1, $sourceState->stopCount);
        self::assertSame($stopFailure, $this->privateFailure($capture, 'lastStopFailure'));
        $this->assertIncomplete($recorder);
    }

    public function testStopFailureBecomesPreviousOnlyWhenIncompletePersistenceAlsoFails(): void
    {
        $filesystem = new CaptureFaultInjectingFilesystem();
        $recorder = $this->recorder('dataset-live-stop-and-incomplete-failure', $filesystem);
        $stopFailure = new \RuntimeException('emergency_stop_failed');
        $publicationFailure = new \RuntimeException('incomplete_publication_failed');
        $filesystem->failNextTerminalPublication($publicationFailure);
        $sourceState = new CaptureSourceState([], stopFailure: $stopFailure);
        $capture = new PaperLiveDatasetCapture();

        try {
            $capture->run(
                $recorder,
                captureTestSource($sourceState),
                captureTestConsumer(new CaptureConsumerState($recorder->datasetDirectory())),
            );
            self::fail('Both cleanup failures must produce the stable persistence error.');
        } catch (\RuntimeException $caught) {
            self::assertSame('paper_live_capture_incomplete_persist_failed', $caught->getMessage());
            self::assertSame($stopFailure, $caught->getPrevious());
            self::assertStringNotContainsString($stopFailure->getMessage(), $caught->getMessage());
            self::assertStringNotContainsString($publicationFailure->getMessage(), $caught->getMessage());
        }

        self::assertSame(1, $sourceState->stopCount);
        self::assertSame(1, $filesystem->terminalPublicationAttempts);
        self::assertSame($stopFailure, $this->privateFailure($capture, 'lastStopFailure'));
        self::assertInstanceOf(
            \RuntimeException::class,
            $this->privateFailure($capture, 'lastIncompletePersistenceFailure'),
        );
    }

    public function testOriginalFailuresOutrankThrowingStopWhenIncompletePersistenceSucceeds(): void
    {
        foreach (['source', 'append', 'consumer', 'acknowledgement'] as $stage) {
            $filesystem = new CaptureFaultInjectingFilesystem();
            $datasetId = 'dataset-live-cleanup-precedence-' . $stage;
            $recorder = $this->recorder($datasetId, $filesystem);
            $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
            $original = new \RuntimeException('original_' . $stage);
            $stopFailure = new \RuntimeException('secondary_stop_' . $stage);
            if ($stage === 'append') {
                $filesystem->failNextAppend($original);
            }
            $sourceState = new CaptureSourceState(
                [$event],
                eventsFailure: $stage === 'source' ? $original : null,
                acknowledgementFailure: $stage === 'acknowledgement' ? $original : null,
                stopFailure: $stopFailure,
                beforeStopFailure: static function () use ($filesystem): void {
                    $filesystem->armMarkIncompleteCallObservation();
                },
            );
            $consumerState = new CaptureConsumerState(
                $recorder->datasetDirectory(),
                failure: $stage === 'consumer' ? $original : null,
            );
            $capture = new PaperLiveDatasetCapture();

            try {
                $capture->run(
                    $recorder,
                    captureTestSource($sourceState),
                    captureTestConsumer($consumerState),
                );
                self::fail(sprintf('The original %s failure must escape capture.', $stage));
            } catch (\Throwable $caught) {
                self::assertSame($original, $caught);
            }

            self::assertSame(1, $sourceState->stopCount);
            self::assertSame(
                1,
                $filesystem->markIncompleteCallCount,
                sprintf('The %s path must call markIncomplete() exactly once.', $stage),
            );
            self::assertSame(
                1,
                $filesystem->successfulIncompletePublicationCount,
                sprintf('The %s path must persist that markIncomplete() call successfully.', $stage),
            );
            self::assertSame($stopFailure, $this->privateFailure($capture, 'lastStopFailure'));
            self::assertNull($this->privateFailure($capture, 'lastIncompletePersistenceFailure'));
            $this->assertIncomplete($recorder);
        }
    }

    public function testIncompletePersistenceFailureWrapsOriginalAndRetainsCleanupFailures(): void
    {
        $filesystem = new CaptureFaultInjectingFilesystem();
        $recorder = $this->recorder('dataset-live-incomplete-persist-failure', $filesystem);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        $original = new \RuntimeException('original_consumer_failure');
        $stopFailure = new \RuntimeException('secondary_stop_failure');
        $publicationFailure = new \RuntimeException('secondary_incomplete_publication_failure');
        $sourceState = new CaptureSourceState([$event], stopFailure: $stopFailure);
        $consumerState = new CaptureConsumerState(
            $recorder->datasetDirectory(),
            failure: $original,
            beforeFailure: static function () use ($filesystem, $publicationFailure): void {
                $filesystem->failNextTerminalPublication($publicationFailure);
            },
        );
        $capture = new PaperLiveDatasetCapture();

        try {
            $capture->run(
                $recorder,
                captureTestSource($sourceState),
                captureTestConsumer($consumerState),
            );
            self::fail('An incomplete persistence failure must be wrapped.');
        } catch (\RuntimeException $caught) {
            self::assertSame('paper_live_capture_incomplete_persist_failed', $caught->getMessage());
            self::assertSame($original, $caught->getPrevious());
            self::assertStringNotContainsString($stopFailure->getMessage(), $caught->getMessage());
            self::assertStringNotContainsString($publicationFailure->getMessage(), $caught->getMessage());
        }

        self::assertSame(1, $sourceState->stopCount);
        self::assertSame(1, $filesystem->terminalPublicationAttempts);
        self::assertSame($stopFailure, $this->privateFailure($capture, 'lastStopFailure'));
        $incompleteFailure = $this->privateFailure($capture, 'lastIncompletePersistenceFailure');
        self::assertInstanceOf(\RuntimeException::class, $incompleteFailure);
        self::assertSame('paper_dataset_mark_incomplete_failed', $incompleteFailure->getMessage());
        self::assertSame(PaperDatasetState::RECORDING, $recorder->manifest()->state);
    }

    private function assertCrashWindowRecovery(
        string $mode,
        string $datasetId,
        int $expectedExitCode,
        bool $effectCommittedBeforeRestart,
    ): void {
        $root = $this->testRoot . '/crash-' . $mode;
        $checkpointPath = $this->testRoot . '/consumer-' . $mode . '.json';
        $logPath = $this->testRoot . '/process-' . $mode . '.log';
        $process = proc_open(
            [
                PHP_BINARY,
                __FILE__,
                '--paper-live-crash-harness',
                $mode,
                $root,
                $datasetId,
                $checkpointPath,
                $logPath,
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame($expectedExitCode, $exitCode, sprintf(
            "Crash harness stdout:\n%s\nstderr:\n%s",
            $stdout,
            $stderr,
        ));
        $datasetDirectory = $root . '/' . $datasetId;
        $eventsPath = $datasetDirectory . '/events.ndjson';
        $manifestPath = $datasetDirectory . '/manifest.json';
        $eventsBefore = file_get_contents($eventsPath);
        self::assertIsString($eventsBefore);
        $recordingManifest = $this->decodeJsonFile($manifestPath);
        self::assertSame('recording', $recordingManifest['state'] ?? null);
        self::assertSame(1, $recordingManifest['event_count'] ?? null);
        $logBefore = file_get_contents($logPath);
        self::assertIsString($logBefore);
        self::assertStringContainsString("consume_durable\n", $logBefore);
        self::assertStringNotContainsString('acknowledge:', $logBefore);
        self::assertSame($effectCommittedBeforeRestart, is_file($checkpointPath));
        if ($effectCommittedBeforeRestart) {
            self::assertSame(1, $this->decodeJsonFile($checkpointPath)['effect_count'] ?? null);
        }

        $manifest = self::manifest($datasetId);
        $restartFilesystem = new CaptureFaultInjectingFilesystem();
        $recorder = new PaperDatasetRecorder($root, $manifest, filesystem: $restartFilesystem);
        $event = self::event(PaperMarketDataChannel::PUBLIC_TRADE, '1');
        self::assertSame(PaperDatasetAppendResult::REPLAYED, $recorder->append($event));
        $restartFilesystem->armReplayAppendObservation();
        $sourceState = new CaptureSourceState([$event], persistentLogPath: $logPath);
        $source = captureTestSource($sourceState);
        $source->requestHealthyOperatorStop();
        $consumerState = new CaptureConsumerState(
            $recorder->datasetDirectory(),
            checkpointPath: $checkpointPath,
            persistentLogPath: $logPath,
            afterDurability: static function () use ($restartFilesystem): void {
                $restartFilesystem->assertReplayAppendObserved();
            },
        );

        $completed = (new PaperLiveDatasetCapture())->run(
            $recorder,
            $source,
            captureTestConsumer($consumerState),
        );

        self::assertSame(PaperDatasetState::COMPLETE, $completed->state);
        self::assertSame(1, $completed->eventCount);
        self::assertSame(hash('sha256', $eventsBefore), $completed->eventsFileSha256);
        self::assertSame($eventsBefore, file_get_contents($eventsPath));
        self::assertSame(1, $consumerState->consumeCount);
        self::assertSame(1, $consumerState->effectCount);
        self::assertSame(1, $sourceState->acknowledgementCount);
        self::assertSame(1, $this->decodeJsonFile($checkpointPath)['effect_count'] ?? null);
        $logAfter = file_get_contents($logPath);
        self::assertIsString($logAfter);
        self::assertStringContainsString('acknowledge:public_trade', $logAfter);
    }

    private function recorder(
        string $datasetId,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
    ): PaperDatasetRecorder {
        return new PaperDatasetRecorder(
            $this->testRoot . '/paper-market-data',
            self::manifest($datasetId),
            filesystem: $filesystem,
        );
    }

    private static function manifest(string $datasetId): PaperDatasetManifest
    {
        return new PaperDatasetManifest(
            schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
            recorderVersion: '1.0.0',
            datasetId: $datasetId,
            venue: PaperMarketDataVenue::OKX,
            network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
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

    /**
     * @param array<array-key, mixed>|null $payload
     */
    private static function event(
        PaperMarketDataChannel $channel,
        string $sequence,
        ?array $payload = null,
        int $microseconds = 1,
    ): PaperMarketEvent {
        return PaperMarketEvent::create(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
            venue: PaperMarketDataVenue::OKX,
            symbol: 'BTCUSDT',
            channel: $channel,
            exchangeTimestamp: new \DateTimeImmutable(sprintf(
                '2026-07-22T10:00:00.%06dZ',
                $microseconds,
            )),
            receivedTimestamp: new \DateTimeImmutable(sprintf(
                '2026-07-22T10:00:01.%06dZ',
                $microseconds,
            )),
            sequence: $sequence,
            payload: $payload ?? ['price' => '30000.0', 'size' => '0.25'],
        );
    }

    private function assertIncomplete(PaperDatasetRecorder $recorder): void
    {
        self::assertSame(PaperDatasetState::INCOMPLETE, $recorder->manifest()->state);
        self::assertSame(PaperMarketDataQuality::INCOMPLETE, $recorder->manifest()->quality);
    }

    private function privateFailure(PaperLiveDatasetCapture $capture, string $property): ?\Throwable
    {
        $reflection = new \ReflectionProperty($capture, $property);
        $value = $reflection->getValue($capture);
        self::assertTrue($value === null || $value instanceof \Throwable);

        return $value;
    }

    private static function rootPrevious(\Throwable $failure): \Throwable
    {
        while ($failure->getPrevious() !== null) {
            $failure = $failure->getPrevious();
        }

        return $failure;
    }

    /** @return array<string, mixed> */
    private function decodeJsonFile(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}

final class CaptureOrderLog
{
    /** @var list<string> */
    public array $entries = [];
}

final class CaptureSourceState
{
    public int $acknowledgementCount = 0;
    public int $stopCount = 0;
    public bool $healthyStopRequested = false;
    public bool $ended = false;

    /**
     * @param list<PaperMarketEvent> $events
     */
    public function __construct(
        public array $events,
        public ?\Throwable $eventsFailure = null,
        public ?\Throwable $acknowledgementFailure = null,
        public ?\Throwable $stopFailure = null,
        public ?\Closure $beforeStopFailure = null,
        public ?string $failureReason = null,
        public ?CaptureOrderLog $order = null,
        public ?string $persistentLogPath = null,
    ) {
    }
}

function captureTestSource(CaptureSourceState $state): PaperLiveMarketDataSourceInterface
{
    return new class($state) implements PaperLiveMarketDataSourceInterface {
        public function __construct(private readonly CaptureSourceState $state)
        {
        }

        public function venue(): PaperMarketDataVenue
        {
            return PaperMarketDataVenue::OKX;
        }

        public function events(): iterable
        {
            foreach ($this->state->events as $event) {
                yield $event;
            }
            if ($this->state->eventsFailure !== null) {
                throw $this->state->eventsFailure;
            }
            $this->state->ended = true;
        }

        public function acknowledge(string $eventId): void
        {
            $event = $this->eventById($eventId);
            $entry = 'acknowledge:' . $event->channel->value;
            if ($this->state->order !== null) {
                $this->state->order->entries[] = $entry;
            }
            if ($this->state->persistentLogPath !== null) {
                file_put_contents($this->state->persistentLogPath, $entry . "\n", FILE_APPEND | LOCK_EX);
            }
            if ($this->state->acknowledgementFailure !== null) {
                throw $this->state->acknowledgementFailure;
            }
            ++$this->state->acknowledgementCount;
        }

        public function stop(): void
        {
            ++$this->state->stopCount;
            if ($this->state->stopFailure !== null) {
                $this->state->beforeStopFailure?->__invoke();

                throw $this->state->stopFailure;
            }
        }

        public function requestHealthyOperatorStop(): void
        {
            $this->state->healthyStopRequested = true;
        }

        public function isComplete(): bool
        {
            return $this->state->healthyStopRequested
                && $this->state->ended
                && $this->state->eventsFailure === null
                && $this->state->failureReason === null
                && $this->state->acknowledgementCount === \count($this->state->events);
        }

        public function failureReason(): ?string
        {
            return $this->state->failureReason;
        }

        private function eventById(string $eventId): PaperMarketEvent
        {
            foreach ($this->state->events as $event) {
                if (hash_equals($event->eventId, $eventId)) {
                    return $event;
                }
            }

            throw new \LogicException('capture_test_acknowledgement_invalid');
        }
    };
}

final class CaptureConsumerState
{
    /** @var array<string, string> */
    public array $checkpoints;
    public int $consumeCount = 0;
    public int $effectCount;

    /**
     * @param array<string, string> $checkpoints
     */
    public function __construct(
        public string $datasetDirectory,
        array $checkpoints = [],
        int $effectCount = 0,
        public ?\Throwable $failure = null,
        public ?\Closure $beforeFailure = null,
        public ?CaptureOrderLog $order = null,
        public ?string $checkpointPath = null,
        public ?string $persistentLogPath = null,
        public ?string $crashMode = null,
        public ?\Closure $afterDurability = null,
    ) {
        $this->checkpoints = $checkpoints;
        $this->effectCount = $effectCount;
        $this->loadCheckpoint();
    }

    public function persist(): void
    {
        if ($this->checkpointPath === null) {
            return;
        }

        $contents = CanonicalJson::encode([
            'checkpoints' => $this->checkpoints,
            'effect_count' => $this->effectCount,
        ]) . "\n";
        $staging = $this->checkpointPath . '.staging.' . getmypid();
        $handle = fopen($staging, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('capture_test_checkpoint_open_failed');
        }
        try {
            if (fwrite($handle, $contents) !== \strlen($contents)
                || !fflush($handle)
                || !fsync($handle)
            ) {
                throw new \RuntimeException('capture_test_checkpoint_write_failed');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($staging, $this->checkpointPath)) {
            throw new \RuntimeException('capture_test_checkpoint_publish_failed');
        }
    }

    private function loadCheckpoint(): void
    {
        if ($this->checkpointPath === null || !is_file($this->checkpointPath)) {
            return;
        }
        $contents = file_get_contents($this->checkpointPath);
        if (!\is_string($contents)) {
            throw new \RuntimeException('capture_test_checkpoint_read_failed');
        }
        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)
            || !\is_array($decoded['checkpoints'] ?? null)
            || !\is_int($decoded['effect_count'] ?? null)
        ) {
            throw new \RuntimeException('capture_test_checkpoint_invalid');
        }
        $checkpoints = [];
        foreach ($decoded['checkpoints'] as $key => $hash) {
            if (!\is_string($key) || !\is_string($hash)) {
                throw new \RuntimeException('capture_test_checkpoint_invalid');
            }
            $checkpoints[$key] = $hash;
        }
        $this->checkpoints = $checkpoints;
        $this->effectCount = $decoded['effect_count'];
    }
}

function captureTestConsumer(CaptureConsumerState $state): PaperLiveEventConsumerInterface
{
    return new class($state) implements PaperLiveEventConsumerInterface {
        public function __construct(private readonly CaptureConsumerState $state)
        {
        }

        public function consume(string $datasetId, PaperMarketEvent $event): void
        {
            ++$this->state->consumeCount;
            $this->assertDurable($datasetId, $event);
            $this->state->afterDurability?->__invoke();
            $entry = 'consume:' . $event->channel->value;
            if ($this->state->order !== null) {
                $this->state->order->entries[] = $entry;
            }
            if ($this->state->persistentLogPath !== null) {
                file_put_contents($this->state->persistentLogPath, "consume_durable\n", FILE_APPEND | LOCK_EX);
            }
            $this->state->beforeFailure?->__invoke();
            if ($this->state->failure !== null) {
                throw $this->state->failure;
            }

            $key = $datasetId . '/' . $event->eventId;
            if (isset($this->state->checkpoints[$key])) {
                if (!hash_equals($this->state->checkpoints[$key], $event->payloadHash)) {
                    throw new \RuntimeException('market_event_identity_conflict');
                }

                return;
            }
            if ($this->state->crashMode === 'before_effect') {
                exit(71);
            }

            $this->state->checkpoints[$key] = $event->payloadHash;
            ++$this->state->effectCount;
            $this->state->persist();
            if ($this->state->persistentLogPath !== null) {
                file_put_contents($this->state->persistentLogPath, "effect_committed\n", FILE_APPEND | LOCK_EX);
            }
            if ($this->state->crashMode === 'after_effect') {
                exit(72);
            }
        }

        private function assertDurable(string $datasetId, PaperMarketEvent $event): void
        {
            $manifestContents = file_get_contents($this->state->datasetDirectory . '/manifest.json');
            $eventsContents = file_get_contents($this->state->datasetDirectory . '/events.ndjson');
            if (!\is_string($manifestContents) || !\is_string($eventsContents)) {
                throw new \RuntimeException('capture_test_event_not_durable');
            }

            try {
                $manifest = json_decode(
                    $manifestContents,
                    true,
                    32,
                    JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                );
                if (!\is_array($manifest)
                    || ($manifest['dataset_id'] ?? null) !== $datasetId
                    || !\is_int($manifest['event_count'] ?? null)
                    || !\is_string($manifest['last_event_id'] ?? null)
                    || !hash_equals($manifest['last_event_id'], $event->eventId)
                    || $eventsContents === ''
                    || !str_ends_with($eventsContents, "\n")
                ) {
                    throw new \RuntimeException('capture_test_event_not_durable');
                }

                $lines = explode("\n", substr($eventsContents, 0, -1));
                $lastObserved = null;
                foreach ($lines as $line) {
                    if ($line === '') {
                        throw new \RuntimeException('capture_test_event_not_durable');
                    }
                    $decoded = json_decode(
                        $line,
                        true,
                        512,
                        JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
                    );
                    if (!\is_array($decoded) || array_is_list($decoded)) {
                        throw new \RuntimeException('capture_test_event_not_durable');
                    }
                    /** @var array<string, mixed> $decoded */
                    $observed = PaperMarketEvent::fromArray($decoded);
                    if (!hash_equals(CanonicalJson::encode($observed->toArray()), $line)) {
                        throw new \RuntimeException('capture_test_event_not_durable');
                    }
                    $lastObserved = $observed;
                }

                if ($manifest['event_count'] !== \count($lines)
                    || !$lastObserved instanceof PaperMarketEvent
                    || !hash_equals($lastObserved->eventId, $event->eventId)
                    || !hash_equals($lastObserved->payloadHash, $event->payloadHash)
                ) {
                    throw new \RuntimeException('capture_test_event_not_durable');
                }
            } catch (\Throwable $failure) {
                if ($failure instanceof \RuntimeException
                    && $failure->getMessage() === 'capture_test_event_not_durable'
                ) {
                    throw $failure;
                }

                throw new \RuntimeException('capture_test_event_not_durable', 0, $failure);
            }
        }
    };
}

final class CaptureFaultInjectingFilesystem extends PaperDatasetRecorderFilesystem
{
    private ?\Throwable $appendFailure = null;
    private ?\Throwable $terminalPublicationFailure = null;
    private ?\Throwable $terminalPublicationFailureAfterRename = null;
    private ?\Throwable $recordingPublicationFailureAfterRename = null;
    private bool $observeReplayAppend = false;
    private bool $observeMarkIncompleteCalls = false;
    private int $observedReplayAppendReads = 0;
    public int $terminalPublicationAttempts = 0;
    public int $markIncompleteCallCount = 0;
    public int $successfulIncompletePublicationCount = 0;

    public function failNextAppend(\Throwable $failure): void
    {
        $this->appendFailure = $failure;
    }

    public function failNextTerminalPublication(\Throwable $failure): void
    {
        $this->terminalPublicationFailure = $failure;
    }

    public function failNextTerminalPublicationAfterRename(\Throwable $failure): void
    {
        $this->terminalPublicationFailureAfterRename = $failure;
    }

    public function failNextRecordingManifestPublicationAfterRename(\Throwable $failure): void
    {
        $this->recordingPublicationFailureAfterRename = $failure;
    }

    public function armReplayAppendObservation(): void
    {
        $this->observeReplayAppend = true;
        $this->observedReplayAppendReads = 0;
    }

    public function armMarkIncompleteCallObservation(): void
    {
        $this->observeMarkIncompleteCalls = true;
        $this->markIncompleteCallCount = 0;
    }

    public function assertReplayAppendObserved(): void
    {
        if ($this->observedReplayAppendReads === 0) {
            throw new \RuntimeException('capture_test_recorder_append_not_observed');
        }
    }

    /** @return resource|false */
    public function openDirectory(
        #[\SensitiveParameter] string $directory,
        string $operation,
    ) {
        if ($this->observeMarkIncompleteCalls && $operation === 'paper_dataset_lock_open_failed') {
            ++$this->markIncompleteCallCount;
        }

        return parent::openDirectory($directory, $operation);
    }

    public function stat($handle, string $operation): array|false
    {
        if ($this->observeReplayAppend && $operation === 'paper_dataset_events_read_failed') {
            ++$this->observedReplayAppendReads;
        }

        return parent::stat($handle, $operation);
    }

    public function write($handle, #[\SensitiveParameter] string $contents, string $operation): int|false
    {
        if ($operation === 'paper_dataset_events_write_failed' && $this->appendFailure !== null) {
            $failure = $this->appendFailure;
            $this->appendFailure = null;

            throw $failure;
        }

        return parent::write($handle, $contents, $operation);
    }

    public function move(
        #[\SensitiveParameter] string $source,
        #[\SensitiveParameter] string $destination,
        string $operation,
    ): bool {
        $manifestState = $operation === 'paper_dataset_manifest_publish'
            ? $this->manifestState($source)
            : null;
        $publishesIncompleteManifest = $manifestState === PaperDatasetState::INCOMPLETE;
        $publishesTerminalManifest = $manifestState === PaperDatasetState::COMPLETE
            || $publishesIncompleteManifest;
        if ($operation === 'paper_dataset_manifest_publish'
            && $publishesTerminalManifest
            && $this->terminalPublicationFailure !== null
        ) {
            ++$this->terminalPublicationAttempts;
            $failure = $this->terminalPublicationFailure;
            $this->terminalPublicationFailure = null;

            throw $failure;
        }

        $moved = parent::move($source, $destination, $operation);
        if ($moved
            && $publishesTerminalManifest
            && $this->terminalPublicationFailureAfterRename !== null
        ) {
            ++$this->terminalPublicationAttempts;
            $failure = $this->terminalPublicationFailureAfterRename;
            $this->terminalPublicationFailureAfterRename = null;

            throw $failure;
        }
        if ($moved
            && $manifestState === PaperDatasetState::RECORDING
            && $this->recordingPublicationFailureAfterRename !== null
        ) {
            $failure = $this->recordingPublicationFailureAfterRename;
            $this->recordingPublicationFailureAfterRename = null;

            throw $failure;
        }
        if ($moved && $publishesIncompleteManifest) {
            ++$this->successfulIncompletePublicationCount;
        }

        return $moved;
    }

    private function manifestState(string $path): PaperDatasetState
    {
        $contents = file_get_contents($path);
        if (!\is_string($contents)) {
            throw new \RuntimeException('capture_test_manifest_candidate_read_failed');
        }
        $manifest = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);

        if (!\is_array($manifest) || !\is_string($manifest['state'] ?? null)) {
            throw new \RuntimeException('capture_test_manifest_candidate_invalid');
        }

        return PaperDatasetState::from($manifest['state']);
    }
}

function runPaperLiveCrashHarness(
    string $mode,
    string $root,
    string $datasetId,
    string $checkpointPath,
    string $logPath,
): int {
    $manifest = new PaperDatasetManifest(
        schemaVersion: PaperDatasetManifest::SCHEMA_VERSION,
        recorderVersion: '1.0.0',
        datasetId: $datasetId,
        venue: PaperMarketDataVenue::OKX,
        network: \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
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
    $event = PaperMarketEvent::create(
            \App\Trading\Paper\MarketData\PaperMarketDataNetwork::MAINNET,
        venue: PaperMarketDataVenue::OKX,
        symbol: 'BTCUSDT',
        channel: PaperMarketDataChannel::PUBLIC_TRADE,
        exchangeTimestamp: new \DateTimeImmutable('2026-07-22T10:00:00.000001Z'),
        receivedTimestamp: new \DateTimeImmutable('2026-07-22T10:00:01.000001Z'),
        sequence: '1',
        payload: ['price' => '30000.0', 'size' => '0.25'],
    );
    $recorder = new PaperDatasetRecorder($root, $manifest);
    $source = captureTestSource(new CaptureSourceState([$event], persistentLogPath: $logPath));
    $source->requestHealthyOperatorStop();
    $consumer = captureTestConsumer(new CaptureConsumerState(
        $recorder->datasetDirectory(),
        checkpointPath: $checkpointPath,
        persistentLogPath: $logPath,
        crashMode: $mode,
    ));
    (new PaperLiveDatasetCapture())->run($recorder, $source, $consumer);

    return 70;
}

if (($argv[1] ?? null) === '--paper-live-crash-harness') {
    if (\count($argv) !== 7) {
        exit(69);
    }
    exit(runPaperLiveCrashHarness($argv[2], $argv[3], $argv[4], $argv[5], $argv[6]));
}
