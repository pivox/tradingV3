<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalEventStream;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalIntegrityException;
use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalRequest;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidHistoricalEventStream::class)]
final class HyperliquidHistoricalEventStreamTest extends TestCase
{
    private string $testRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $path = sys_get_temp_dir()
            . '/hyperliquid-historical-stream-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0700, true));
        $resolved = realpath($path);
        self::assertIsString($resolved);
        $this->testRoot = $resolved;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->testRoot);
        parent::tearDown();
    }

    public function testConstructorRejectsClientConfiguredForAnotherNetworkBeforeFetching(): void
    {
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'hyperliquid-network-binding',
            network: PaperMarketDataNetwork::TESTNET,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            to: new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_historical_client_network_mismatch');

        new HyperliquidHistoricalEventStream(
            new NetworkTaggedNoCallHyperliquidHistoricalClient(
                PaperMarketDataNetwork::MAINNET,
            ),
            $request,
            $this->testRoot,
        );
    }

    public function testFetchesTwoForwardPagesForEveryStreamBeforeDeterministicAcknowledgedEmission(): void
    {
        $from = new \DateTimeImmutable('2024-01-01T00:00:00.000000Z');
        $to = $from->modify('+2 hours');
        $client = new ScriptedHyperliquidHistoricalClient($to);
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'hyperliquid-two-forward-pages',
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['ETHUSDT', 'BTCUSDT'],
            from: $from,
            to: $to,
            maximumEvents: 1_000,
            maximumPages: 16,
            maximumResponseBytes: 123_456,
            maximumRetries: 3,
        );
        $stream = new HyperliquidHistoricalEventStream(
            restClient: $client,
            request: $request,
            datasetDirectory: $this->testRoot,
        );

        self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $stream->venue());
        self::assertFalse($stream->isComplete());

        $events = [];
        foreach ($stream->events() as $event) {
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            self::assertNotSame(PaperMarketDataChannel::PUBLIC_TRADE, $event->channel);
            $events[] = $event;
            $stream->acknowledge($event->eventId);
        }

        self::assertCount(616, $events);
        self::assertCount(16, $client->calls);
        self::assertTrue($stream->isComplete());
        foreach ($client->calls as $call) {
            self::assertSame(123_456, $call['maximum_response_bytes']);
            self::assertSame(3, $call['maximum_retries']);
            self::assertLessThanOrEqual(
                500,
                intdiv($call['end_time'] - $call['start_time'], $call['step']) + 1,
            );
            self::assertLessThanOrEqual(
                (int) $to->format('Uv'),
                $call['end_time'] + 1,
            );
        }
        foreach ($client->callsByStream() as $calls) {
            self::assertCount(2, $calls);
            self::assertSame(
                $calls[0]['returned_last_time'] + $calls[0]['step'],
                $calls[1]['start_time'],
            );
        }

        $sortKeys = array_map(
            static fn (PaperMarketEvent $event): string => implode('|', [
                $event->exchangeTimestamp->format('Y-m-d\TH:i:s.u\Z'),
                $event->symbol,
                str_pad(
                    (string) (new HyperliquidPaperInstrumentMap())->intervalMilliseconds(
                        $event->channel === PaperMarketDataChannel::TOP_OF_BOOK
                            ? self::bookInterval($event)
                            : (string) $event->payload['interval'],
                    ),
                    10,
                    '0',
                    \STR_PAD_LEFT,
                ),
                $event->channel->value,
                $event->eventId,
            ]),
            $events,
        );
        $sorted = $sortKeys;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $sortKeys);

        $checkpoint = $this->checkpoint();
        self::assertSame('complete', $checkpoint['phase']);
        self::assertSame(16, $checkpoint['page_count']);
        self::assertSame(308, $checkpoint['staged_row_count']);
        self::assertSame(616, $checkpoint['event_count']);
        self::assertSame(616, $checkpoint['emit_index']);
        self::assertNull($checkpoint['pending_event']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidResponseProvider(): iterable
    {
        yield 'duplicate' => ['duplicate', 'hyperliquid_history_candle_response_inconsistent'];
        yield 'decreasing' => ['decreasing', 'hyperliquid_history_candle_response_inconsistent'];
        yield 'overlap' => ['overlap', 'hyperliquid_history_candle_response_inconsistent'];
        yield 'stale initial range' => ['gap', 'hyperliquid_history_retention_incomplete'];
        yield 'missing grid candle' => ['internal_gap', 'hyperliquid_history_candle_grid_gap'];
        yield 'wrong coin' => ['wrong_coin', 'hyperliquid_history_candle_response_inconsistent'];
        yield 'wrong interval' => ['wrong_interval', 'hyperliquid_history_candle_response_inconsistent'];
        yield 'inconsistent close time' => ['wrong_close', 'hyperliquid_history_candle_response_inconsistent'];
        yield 'empty initial retention' => ['empty', 'hyperliquid_history_retention_incomplete'];
        yield '501 rows' => ['too_many', 'hyperliquid_history_candle_response_limit_exceeded'];
    }

    #[DataProvider('invalidResponseProvider')]
    public function testRejectsUntrustedCandleResponsesAndPersistsOnlyAllowlistedReason(
        string $fault,
        string $expectedReason,
    ): void {
        $client = new FaultingHyperliquidHistoricalClient($fault);
        $stream = $this->smallStream($client, 'fault-' . $fault);

        try {
            iterator_to_array($stream->events());
            self::fail('Invalid response must fail.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame($expectedReason, $exception->getMessage());
        }

        $checkpoint = $this->checkpoint();
        self::assertSame('failed', $checkpoint['phase']);
        self::assertSame($expectedReason, $checkpoint['failure_reason']);
        self::assertNull($checkpoint['pending_event']);
        self::assertContains(
            $checkpoint['failure_reason'],
            \App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalCheckpointStore::ALLOWED_FAILURE_REASONS,
        );
    }

    public function testEnforcesMaximumPagesBeforeAnotherHttpCall(): void
    {
        $client = new ScriptedHyperliquidHistoricalClient(
            new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
        );
        $stream = $this->smallStream($client, 'page-bound', maximumPages: 1);

        $this->expectIntegrityFailure(
            $stream,
            'hyperliquid_history_page_bound_exceeded',
        );
        self::assertCount(1, $client->calls);
    }

    public function testEnforcesMaximumEventsBeforeStagingOverBoundPage(): void
    {
        $client = new ScriptedHyperliquidHistoricalClient(
            new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
        );
        $stream = $this->smallStream($client, 'event-bound', maximumEvents: 1);

        $this->expectIntegrityFailure(
            $stream,
            'hyperliquid_history_event_bound_exceeded',
        );
        self::assertSame(0, $this->checkpoint()['page_count']);
    }

    public function testRejectsCursorArithmeticThatCannotProgress(): void
    {
        $seconds = intdiv(\PHP_INT_MAX, 1_000_000) + 1;
        $from = new \DateTimeImmutable('@' . $seconds);
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'cursor-overflow',
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT'],
            from: $from,
            to: $from->modify('+1 second'),
        );
        $stream = new HyperliquidHistoricalEventStream(
            new NoCallHyperliquidHistoricalClient(),
            $request,
            $this->testRoot,
        );

        $this->expectIntegrityFailure(
            $stream,
            'hyperliquid_history_candle_cursor_not_progressing',
        );
    }

    public function testRepeatedForwardPageIsRejectedBeforeOverlapProcessing(): void
    {
        $request = $this->requestTo(
            'repeated-page',
            new \DateTimeImmutable('2024-01-01T00:02:00.000000Z'),
        );
        $stream = new HyperliquidHistoricalEventStream(
            new RepeatedPageHyperliquidHistoricalClient(),
            $request,
            $this->testRoot,
        );

        $this->expectIntegrityFailure($stream, 'hyperliquid_history_repeated_page');
    }

    public function testEachInclusiveWindowContainsNoMoreThanFiveHundredGridIntervals(): void
    {
        $to = new \DateTimeImmutable('2024-01-01T08:21:00.000000Z');
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'window-500',
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            to: $to,
            maximumEvents: 2_000,
            maximumPages: 8,
        );
        $client = new FullWindowHyperliquidHistoricalClient();
        $stream = new HyperliquidHistoricalEventStream(
            $client,
            $request,
            $this->testRoot,
        );
        $events = $this->eventGenerator($stream);
        $events->rewind();
        self::assertInstanceOf(PaperMarketEvent::class, $events->current());
        $stream->stop();

        self::assertSame(500, $client->calls[0]['row_count']);
        self::assertSame(
            $client->calls[0]['start_time'] + (499 * 60_000),
            $client->calls[0]['end_time'],
        );
        self::assertSame(1, $client->calls[1]['row_count']);
        self::assertSame(
            $client->calls[0]['end_time'] + 60_000,
            $client->calls[1]['start_time'],
        );
        self::assertSame((int) $to->format('Uv') - 1, $client->calls[1]['end_time']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function interruptionProvider(): iterable
    {
        yield 'before first page write' => [1];
        yield 'after first page write' => [2];
    }

    #[DataProvider('interruptionProvider')]
    public function testFetchingRestartsFromLastDurableCursor(int $throwOnCall): void
    {
        $request = $this->requestTo(
            'restart-page-' . $throwOnCall,
            new \DateTimeImmutable('2024-01-01T00:02:00.000000Z'),
        );
        $this->interruptAcquisition($request, $throwOnCall);
        $expectedCursor = $throwOnCall === 1
            ? (int) $request->from->format('Uv')
            : (int) $request->from->format('Uv') + 60_000;
        gc_collect_cycles();

        $resumeClient = new ScriptedHyperliquidHistoricalClient($request->to);
        $restart = new HyperliquidHistoricalEventStream(
            $resumeClient,
            $request,
            $this->testRoot,
        );
        $events = $this->eventGenerator($restart);
        $events->rewind();
        self::assertInstanceOf(PaperMarketEvent::class, $events->current());
        self::assertSame($expectedCursor, $resumeClient->calls[0]['start_time']);
        $restart->stop();
        self::assertFalse($restart->isComplete());
    }

    private function interruptAcquisition(
        HyperliquidHistoricalRequest $request,
        int $throwOnCall,
    ): void {
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            try {
                $stream = new HyperliquidHistoricalEventStream(
                    new InterruptingHyperliquidHistoricalClient($throwOnCall),
                    $request,
                    $this->testRoot,
                );
                iterator_to_array($stream->events());
            } catch (\RuntimeException $exception) {
                exit($exception->getMessage() === 'scripted_transport_interruption' ? 0 : 2);
            }
            exit(3);
        }
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public function testMissingAndWrongAcknowledgementsPreventAdvancing(): void
    {
        $stream = $this->smallStream(
            new ScriptedHyperliquidHistoricalClient(
                new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
            ),
            'ack-required',
        );
        $events = $this->eventGenerator($stream);
        $events->rewind();
        $event = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $event);

        try {
            $stream->acknowledge(str_repeat('0', 64));
            self::fail('Wrong acknowledgement must fail.');
        } catch (\LogicException $exception) {
            self::assertSame(
                'hyperliquid_acquisition_acknowledgement_invalid',
                $exception->getMessage(),
            );
        }
        try {
            $events->next();
            self::fail('Missing acknowledgement must prevent the next event.');
        } catch (\LogicException $exception) {
            self::assertSame(
                'hyperliquid_acquisition_pending_event_not_acknowledged',
                $exception->getMessage(),
            );
        }
        self::assertFalse($stream->isComplete());
        self::assertSame(0, $this->checkpoint()['emit_index']);
    }

    public function testRestartReplaysPendingEventByteIdenticallyWithoutNewSequence(): void
    {
        $request = $this->smallRequest('pending-restart');
        $client = new ScriptedHyperliquidHistoricalClient($request->to);
        $stream = new HyperliquidHistoricalEventStream($client, $request, $this->testRoot);
        $events = $this->eventGenerator($stream);
        $events->rewind();
        $first = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $first);
        $firstState = $first->toArray();
        unset($events, $stream);
        gc_collect_cycles();

        $restarted = new HyperliquidHistoricalEventStream(
            new NoCallHyperliquidHistoricalClient(),
            $request,
            $this->testRoot,
        );
        $replay = $this->eventGenerator($restarted);
        $replay->rewind();
        $pending = $replay->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        self::assertSame($firstState, $pending->toArray());
        self::assertSame('1', $pending->sequence);
        $restarted->acknowledge($pending->eventId);
        $replay->next();
        $next = $replay->current();
        self::assertInstanceOf(PaperMarketEvent::class, $next);
        self::assertNotSame($pending->eventId, $next->eventId);
        $restarted->stop();
        self::assertFalse($restarted->isComplete());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function checkpointSequenceTamperingProvider(): iterable
    {
        yield 'emit index advanced without ordinal' => ['emit_index'];
        yield 'event count altered within store bounds' => ['event_count'];
        yield 'ordinal behind acknowledged prefix' => ['ordinal_behind'];
        yield 'ordinal advanced beyond acknowledged prefix' => ['ordinal_advanced'];
        yield 'foreign but allowed ordinal scope' => ['ordinal_foreign'];
        yield 'pending replaced by later self-consistent event' => ['pending_later'];
        yield 'pending removed while ordinal includes it' => ['pending_absent'];
    }

    #[DataProvider('checkpointSequenceTamperingProvider')]
    public function testEmittingRestartRejectsSelfConsistentSequenceTamperingBeforeYield(
        string $mode,
    ): void {
        [$directory, $request, $state] = $this->tamperedCheckpointFixture($mode);
        $this->writeCheckpoint($directory, $state);
        $stream = new HyperliquidHistoricalEventStream(
            new NoCallHyperliquidHistoricalClient(),
            $request,
            $directory,
        );

        $this->expectIntegrityFailure(
            $stream,
            'hyperliquid_acquisition_checkpoint_invalid',
        );
        $failed = $this->checkpointAt($directory);
        self::assertSame('failed', $failed['phase']);
        self::assertSame(
            'hyperliquid_acquisition_checkpoint_invalid',
            $failed['failure_reason'],
        );
        self::assertNull($failed['pending_event']);
    }

    public function testStoppedSourceDoesNotYieldExistingPendingAndRestartStillReplaysIt(): void
    {
        $request = $this->smallRequest('stopped-pending');
        $stream = new HyperliquidHistoricalEventStream(
            new ScriptedHyperliquidHistoricalClient($request->to),
            $request,
            $this->testRoot,
        );
        $events = $this->eventGenerator($stream);
        $events->rewind();
        $pending = $events->current();
        self::assertInstanceOf(PaperMarketEvent::class, $pending);
        $pendingState = $pending->toArray();
        unset($events);

        $stream->stop();
        $stopped = $this->eventGenerator($stream);
        $stopped->rewind();
        self::assertFalse($stopped->valid());
        self::assertNotNull($this->checkpoint()['pending_event']);
        unset($stopped, $stream);
        gc_collect_cycles();

        $restart = new HyperliquidHistoricalEventStream(
            new NoCallHyperliquidHistoricalClient(),
            $request,
            $this->testRoot,
        );
        $replay = $this->eventGenerator($restart);
        $replay->rewind();
        self::assertInstanceOf(PaperMarketEvent::class, $replay->current());
        self::assertSame($pendingState, $replay->current()->toArray());
    }

    public function testFirstGridCeilingOverflowFailsDurablyWithoutTypeError(): void
    {
        $from = $this->extremeTimestamp(775_000);
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'first-grid-overflow',
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT'],
            from: $from,
            to: $from->modify('+1 second'),
        );
        $stream = new HyperliquidHistoricalEventStream(
            new NoCallHyperliquidHistoricalClient(),
            $request,
            $this->testRoot,
        );

        $this->expectIntegrityFailure(
            $stream,
            'hyperliquid_history_candle_cursor_not_progressing',
        );
        self::assertSame('failed', $this->checkpoint()['phase']);
    }

    public function testExclusiveToCeilingOverflowFailsDurablyWithoutTypeError(): void
    {
        $request = new HyperliquidHistoricalRequest(
            datasetId: 'exclusive-to-overflow',
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            to: $this->extremeTimestamp(775_807),
        );
        $stream = new HyperliquidHistoricalEventStream(
            new NoCallHyperliquidHistoricalClient(),
            $request,
            $this->testRoot,
        );

        $this->expectIntegrityFailure(
            $stream,
            'hyperliquid_history_candle_cursor_not_progressing',
        );
        self::assertSame('failed', $this->checkpoint()['phase']);
    }

    public function testStopBeforeIterationDoesNotFetchOrFalselyComplete(): void
    {
        $client = new ScriptedHyperliquidHistoricalClient(
            new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
        );
        $stream = $this->smallStream($client, 'stopped');
        $stream->stop();

        self::assertSame([], iterator_to_array($stream->events()));
        self::assertSame([], $client->calls);
        self::assertFalse($stream->isComplete());
        self::assertSame('fetching', $this->checkpoint()['phase']);
    }

    public function testRestartDetectsStagedPageCorruptionClearsPendingAndFails(): void
    {
        $request = $this->smallRequest('corrupt-restart');
        $stream = new HyperliquidHistoricalEventStream(
            new ScriptedHyperliquidHistoricalClient($request->to),
            $request,
            $this->testRoot,
        );
        $events = $this->eventGenerator($stream);
        $events->rewind();
        self::assertInstanceOf(PaperMarketEvent::class, $events->current());
        $checkpoint = $this->checkpoint();
        $firstStream = reset($checkpoint['streams']);
        self::assertIsArray($firstStream);
        $file = $firstStream['pages'][0]['file'];
        unset($events, $stream);
        gc_collect_cycles();

        $path = $this->testRoot
            . '/checkpoints/hyperliquid-acquisition/mainnet/pages/' . $file;
        self::assertNotFalse(file_put_contents($path, "{}\n"));
        chmod($path, 0600);

        $restart = new HyperliquidHistoricalEventStream(
            new NoCallHyperliquidHistoricalClient(),
            $request,
            $this->testRoot,
        );
        $this->expectIntegrityFailure(
            $restart,
            'hyperliquid_acquisition_page_hash_mismatch',
        );
        $failed = $this->checkpoint();
        self::assertSame('failed', $failed['phase']);
        self::assertSame(
            'hyperliquid_acquisition_page_hash_mismatch',
            $failed['failure_reason'],
        );
        self::assertNull($failed['pending_event']);
    }

    public function testEveryPageWriteBoundaryRestartsWithExactDurableCursorAndOutput(): void
    {
        $streams = [];
        foreach (['BTC', 'ETH'] as $coin) {
            foreach (['1m', '5m', '15m', '1h'] as $interval) {
                $streams[] = $coin . '/candle_' . $interval;
            }
        }
        $boundaries = [];
        foreach ($streams as $key) {
            foreach ([1, 2] as $page) {
                foreach (['before_page_write', 'after_page_write', 'after_page_save'] as $stage) {
                    $boundaries[] = $stage . ':' . $key . ':' . $page;
                }
            }
        }

        foreach ($boundaries as $index => $boundary) {
            $directory = $this->datasetDirectory('page-boundary-' . $index);
            $request = $this->twoHourRequest('page-boundary-' . $index);
            $this->crashAtBoundary($request, $directory, $boundary);

            $resumeClient = new ScriptedHyperliquidHistoricalClient($request->to);
            $restart = new HyperliquidHistoricalEventStream(
                $resumeClient,
                $request,
                $directory,
            );
            $events = $this->eventGenerator($restart);
            $events->rewind();
            $first = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $first, $boundary);
            self::assertSame($this->expectedFirstEvent(), $first->toArray(), $boundary);
            $this->assertResumeCall($resumeClient, $boundary, $streams, $request);
            $restart->stop();
            unset($events, $restart);
            gc_collect_cycles();
        }
    }

    public function testPhasePendingAndAcknowledgementDurabilityBoundariesRestartExactly(): void
    {
        $baselineDirectory = $this->datasetDirectory('emission-boundary-baseline');
        $baselineRequest = $this->smallRequestForDirectory('emission-boundary-baseline');
        $baseline = new HyperliquidHistoricalEventStream(
            new ScriptedHyperliquidHistoricalClient($baselineRequest->to),
            $baselineRequest,
            $baselineDirectory,
        );
        $expected = [];
        foreach ($baseline->events() as $event) {
            $expected[] = $event->toArray();
            $baseline->acknowledge($event->eventId);
        }
        self::assertCount(16, $expected);

        $boundaries = [['after_emitting_save', 0]];
        foreach (array_keys($expected) as $emitIndex) {
            $boundaries[] = ['after_pending_save:' . $emitIndex, $emitIndex];
            $boundaries[] = ['after_ack_save:' . ($emitIndex + 1), $emitIndex + 1];
        }
        foreach ($boundaries as $index => [$boundary, $expectedIndex]) {
            $directory = $this->datasetDirectory('emission-boundary-' . $index);
            $request = $this->smallRequestForDirectory('emission-boundary-' . $index);
            $this->crashAtBoundary(
                $request,
                $directory,
                $boundary,
                driveAcknowledgements: $boundary !== 'after_emitting_save',
            );

            $restart = new HyperliquidHistoricalEventStream(
                new NoCallHyperliquidHistoricalClient(),
                $request,
                $directory,
            );
            $events = $this->eventGenerator($restart);
            $events->rewind();
            if ($expectedIndex === \count($expected)) {
                self::assertFalse($events->valid(), $boundary);
                self::assertTrue($restart->isComplete(), $boundary);

                continue;
            }
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event, $boundary);
            self::assertSame($expected[$expectedIndex], $event->toArray(), $boundary);
            $restart->stop();
            self::assertFalse($restart->isComplete());
        }
    }

    private function crashAtBoundary(
        HyperliquidHistoricalRequest $request,
        string $directory,
        string $boundary,
        bool $driveAcknowledgements = false,
    ): void {
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            $observer = static function (string $observed) use ($boundary): void {
                if ($observed === $boundary) {
                    exit(86);
                }
            };
            $stream = new HyperliquidHistoricalEventStream(
                restClient: new ScriptedHyperliquidHistoricalClient($request->to),
                request: $request,
                datasetDirectory: $directory,
                durabilityObserver: $observer,
            );
            if ($driveAcknowledgements) {
                foreach ($stream->events() as $event) {
                    $stream->acknowledge($event->eventId);
                }
            } else {
                iterator_to_array($stream->events());
            }
            exit(3);
        }
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(86, pcntl_wexitstatus($status), $boundary);
    }

    /**
     * @return array{string, HyperliquidHistoricalRequest, array<string, mixed>}
     */
    private function tamperedCheckpointFixture(string $mode): array
    {
        $name = 'sequence-tamper-' . str_replace('_', '-', $mode);
        $directory = $this->datasetDirectory($name);
        $request = $this->smallRequestForDirectory($name);
        if (\in_array($mode, ['emit_index', 'event_count', 'ordinal_foreign'], true)) {
            $this->crashAtBoundary(
                $request,
                $directory,
                'after_emitting_save',
            );
            $state = $this->checkpointAt($directory);
        } elseif ($mode === 'pending_later' || $mode === 'pending_absent') {
            $state = $this->seedEmissionCheckpoint($directory, $request, 0, true);
        } else {
            $state = $this->seedEmissionCheckpoint($directory, $request, 1, false);
        }

        if ($mode === 'emit_index') {
            $state['emit_index'] = 1;
        } elseif ($mode === 'event_count') {
            --$state['event_count'];
        } elseif ($mode === 'ordinal_behind') {
            $state['ordinal_state'] = (new \App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal())
                ->snapshot();
        } elseif ($mode === 'ordinal_advanced') {
            [$donorDirectory, $donorRequest] = $this->donorFixture($name . '-advanced');
            $donor = $this->seedEmissionCheckpoint(
                $donorDirectory,
                $donorRequest,
                2,
                false,
            );
            $state['ordinal_state'] = $donor['ordinal_state'];
        } elseif ($mode === 'ordinal_foreign') {
            $state['ordinal_state'] = $this->foreignAllowedOrdinalState();
        } elseif ($mode === 'pending_later') {
            [$donorDirectory, $donorRequest] = $this->donorFixture($name . '-later');
            $donor = $this->seedEmissionCheckpoint(
                $donorDirectory,
                $donorRequest,
                1,
                true,
            );
            $state['pending_event'] = $donor['pending_event'];
            $state['ordinal_state'] = $donor['ordinal_state'];
        } elseif ($mode === 'pending_absent') {
            $state['pending_event'] = null;
        } else {
            throw new \LogicException('test_tampering_mode_invalid');
        }

        return [$directory, $request, $state];
    }

    /**
     * @return array{string, HyperliquidHistoricalRequest}
     */
    private function donorFixture(string $name): array
    {
        $directory = $this->datasetDirectory($name);

        return [$directory, $this->smallRequestForDirectory($name)];
    }

    /** @return array<string, mixed> */
    private function seedEmissionCheckpoint(
        string $directory,
        HyperliquidHistoricalRequest $request,
        int $acknowledged,
        bool $pending,
    ): array {
        $stream = new HyperliquidHistoricalEventStream(
            new ScriptedHyperliquidHistoricalClient($request->to),
            $request,
            $directory,
        );
        $events = $this->eventGenerator($stream);
        $events->rewind();
        for ($index = 0; $index < $acknowledged; ++$index) {
            $event = $events->current();
            self::assertInstanceOf(PaperMarketEvent::class, $event);
            $stream->acknowledge($event->eventId);
            if ($index + 1 < $acknowledged || $pending) {
                $events->next();
            }
        }
        if ($pending) {
            self::assertInstanceOf(PaperMarketEvent::class, $events->current());
        }
        $state = $this->checkpointAt($directory);
        unset($events, $stream);
        gc_collect_cycles();

        return $state;
    }

    /** @return array<string, mixed> */
    private function foreignAllowedOrdinalState(): array
    {
        $row = ScriptedHyperliquidHistoricalClient::row(
            'ETH',
            '1m',
            1_704_067_200_000,
            60_000,
        );
        $candle = \App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle::fromApiRow(
            $row,
            'ETH',
            '1m',
        );
        $ordinals = new \App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal();
        $normalizer = new \App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
            $ordinals,
        );
        $normalizer->candle($candle);

        return $ordinals->snapshot();
    }

    /** @param array<string, mixed> $state */
    private function writeCheckpoint(string $directory, array $state): void
    {
        $path = $directory
            . '/checkpoints/hyperliquid-acquisition/mainnet/checkpoint.json';
        self::assertNotFalse(file_put_contents(
            $path,
            \App\Trading\Paper\MarketData\CanonicalJson::encode($state) . "\n",
        ));
        chmod($path, 0600);
    }

    /** @return array<string, mixed> */
    private function checkpointAt(string $directory): array
    {
        $path = $directory
            . '/checkpoints/hyperliquid-acquisition/mainnet/checkpoint.json';
        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function extremeTimestamp(int $microseconds): \DateTimeImmutable
    {
        $seconds = intdiv(\PHP_INT_MAX, 1_000_000);
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!U.u',
            $seconds . '.' . str_pad((string) $microseconds, 6, '0', \STR_PAD_LEFT),
            new \DateTimeZone('UTC'),
        );
        self::assertInstanceOf(\DateTimeImmutable::class, $timestamp);

        return $timestamp;
    }

    /**
     * @param list<string> $streams
     */
    private function assertResumeCall(
        ScriptedHyperliquidHistoricalClient $client,
        string $boundary,
        array $streams,
        HyperliquidHistoricalRequest $request,
    ): void {
        [$stage, $key, $pageText] = explode(':', $boundary);
        $page = (int) $pageText;
        $streamIndex = array_search($key, $streams, true);
        self::assertIsInt($streamIndex);
        if ($stage === 'after_page_save' && $page === 2) {
            ++$streamIndex;
            if (!isset($streams[$streamIndex])) {
                self::assertSame([], $client->calls, $boundary);

                return;
            }
            $key = $streams[$streamIndex];
            $page = 1;
        } elseif ($stage === 'after_page_save') {
            ++$page;
        }
        self::assertNotSame([], $client->calls, $boundary);
        [$coin, $channel] = explode('/', $key);
        $interval = substr($channel, \strlen('candle_'));
        $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
        $expectedStart = (int) $request->from->format('Uv')
            + (($page - 1) * $step);
        self::assertSame($coin, $client->calls[0]['coin'], $boundary);
        self::assertSame($interval, $client->calls[0]['interval'], $boundary);
        self::assertSame($expectedStart, $client->calls[0]['start_time'], $boundary);
    }

    /** @return array<string, mixed> */
    private function expectedFirstEvent(): array
    {
        $candle = \App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle::fromApiRow(
            ScriptedHyperliquidHistoricalClient::row('BTC', '1m', 1_704_067_200_000, 60_000),
            'BTC',
            '1m',
        );
        $event = (new \App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer(
            PaperMarketDataNetwork::MAINNET,
        ))->candle($candle);

        $state = json_decode(
            \App\Trading\Paper\MarketData\CanonicalJson::encode($event->toArray()),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return PaperMarketEvent::fromArray($state)->toArray();
    }

    private function datasetDirectory(string $name): string
    {
        $path = $this->testRoot . '/' . $name;
        self::assertTrue(mkdir($path, 0700));
        $resolved = realpath($path);
        self::assertIsString($resolved);

        return $resolved;
    }

    private function twoHourRequest(string $datasetId): HyperliquidHistoricalRequest
    {
        return new HyperliquidHistoricalRequest(
            datasetId: $datasetId,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            to: new \DateTimeImmutable('2024-01-01T02:00:00.000000Z'),
            maximumEvents: 1_000,
            maximumPages: 32,
        );
    }

    private function smallRequestForDirectory(string $datasetId): HyperliquidHistoricalRequest
    {
        return new HyperliquidHistoricalRequest(
            datasetId: $datasetId,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            to: new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
            maximumEvents: 100,
            maximumPages: 16,
        );
    }

    private function smallStream(
        HyperliquidPaperPublicRestClientInterface $client,
        string $datasetId,
        int $maximumPages = 16,
        int $maximumEvents = 100,
    ): HyperliquidHistoricalEventStream {
        return new HyperliquidHistoricalEventStream(
            $client,
            $this->smallRequest($datasetId, $maximumPages, $maximumEvents),
            $this->testRoot,
        );
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function eventGenerator(
        HyperliquidHistoricalEventStream $stream,
    ): \Generator {
        $events = $stream->events();
        self::assertInstanceOf(\Generator::class, $events);

        return $events;
    }

    private function smallRequest(
        string $datasetId,
        int $maximumPages = 16,
        int $maximumEvents = 100,
    ): HyperliquidHistoricalRequest {
        return $this->requestTo(
            $datasetId,
            new \DateTimeImmutable('2024-01-01T00:01:00.000000Z'),
            $maximumPages,
            $maximumEvents,
        );
    }

    private function requestTo(
        string $datasetId,
        \DateTimeImmutable $to,
        int $maximumPages = 16,
        int $maximumEvents = 100,
    ): HyperliquidHistoricalRequest {
        return new HyperliquidHistoricalRequest(
            datasetId: $datasetId,
            network: PaperMarketDataNetwork::MAINNET,
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            to: $to,
            maximumEvents: $maximumEvents,
            maximumPages: $maximumPages,
            maximumResponseBytes: 123_456,
            maximumRetries: 3,
        );
    }

    private function expectIntegrityFailure(
        HyperliquidHistoricalEventStream $stream,
        string $reason,
    ): void {
        try {
            iterator_to_array($stream->events());
            self::fail('Expected integrity failure.');
        } catch (HyperliquidHistoricalIntegrityException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    private static function bookInterval(PaperMarketEvent $event): string
    {
        $start = (int) $event->payload['source_candle_start'];
        foreach (['1m', '5m', '15m', '1h'] as $interval) {
            $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
            if ((int) $event->exchangeTimestamp->format('Uv') === $start + $step - 1) {
                return $interval;
            }
        }

        throw new \LogicException('test_book_interval_invalid');
    }

    /** @return array<string, mixed> */
    private function checkpoint(): array
    {
        $path = $this->testRoot
            . '/checkpoints/hyperliquid-acquisition/mainnet/checkpoint.json';
        $decoded = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}

trait MainnetHyperliquidHistoricalClientNetwork
{
    public function network(): PaperMarketDataNetwork
    {
        return PaperMarketDataNetwork::MAINNET;
    }
}

final class ScriptedHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    use MainnetHyperliquidHistoricalClientNetwork;

    /** @var list<array{
     *     coin: string,
     *     interval: string,
     *     start_time: int,
     *     end_time: int,
     *     maximum_response_bytes: int,
     *     maximum_retries: int,
     *     step: int,
     *     returned_last_time: int
     * }>
     */
    public array $calls = [];

    /** @var array<string, int> */
    private array $streamCalls = [];

    public function __construct(private readonly \DateTimeImmutable $to)
    {
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        if ($endTime >= (int) $this->to->format('Uv')) {
            throw new \LogicException('test_end_time_not_inclusive');
        }
        $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
        $key = $coin . '/' . $interval;
        $call = ($this->streamCalls[$key] ?? 0) + 1;
        $this->streamCalls[$key] = $call;
        $last = $call === 1 ? $startTime : $endTime - (($endTime - $startTime) % $step);
        $rows = [];
        for ($time = $startTime; $time <= $last; $time += $step) {
            $rows[] = self::row($coin, $interval, $time, $step);
        }
        $this->calls[] = [
            'coin' => $coin,
            'interval' => $interval,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'maximum_response_bytes' => $maximumResponseBytes,
            'maximum_retries' => $maximumRetries,
            'step' => $step,
            'returned_last_time' => $last,
        ];

        return $rows;
    }

    /** @return array<string, list<array<string, int|string>>> */
    public function callsByStream(): array
    {
        $grouped = [];
        foreach ($this->calls as $call) {
            $grouped[$call['coin'] . '/' . $call['interval']][] = $call;
        }

        return $grouped;
    }

    /** @return array<string, mixed> */
    public static function row(string $coin, string $interval, int $time, int $step): array
    {
        static $fixtures = [];
        if (!isset($fixtures[$coin])) {
            $path = dirname(__DIR__, 4)
                . '/Fixtures/HyperliquidPaperPublic/candles-'
                . strtolower($coin) . '-two-pages.json';
            $fixture = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                \JSON_THROW_ON_ERROR,
            );
            if (!\is_array($fixture)
                || !\is_array($fixture['pages'] ?? null)
                || !\is_array($fixture['pages'][0][0] ?? null)
            ) {
                throw new \LogicException('test_candle_fixture_invalid');
            }
            $fixtures[$coin] = $fixture['pages'][0][0];
        }

        return array_replace($fixtures[$coin], [
            'T' => $time + $step - 1,
            'i' => $interval,
            's' => $coin,
            't' => $time,
        ]);
    }
}

final class FaultingHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    use MainnetHyperliquidHistoricalClientNetwork;

    private int $calls = 0;

    public function __construct(private readonly string $fault)
    {
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        ++$this->calls;
        $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
        $row = ScriptedHyperliquidHistoricalClient::row($coin, $interval, $startTime, $step);
        if ($this->calls !== 1) {
            return [$row];
        }

        return match ($this->fault) {
            'duplicate' => [$row, $row],
            'decreasing' => [
                $row,
                ScriptedHyperliquidHistoricalClient::row(
                    $coin,
                    $interval,
                    $startTime - $step,
                    $step,
                ),
            ],
            'overlap' => [
                ScriptedHyperliquidHistoricalClient::row(
                    $coin,
                    $interval,
                    $startTime - $step,
                    $step,
                ),
            ],
            'gap' => [
                ScriptedHyperliquidHistoricalClient::row(
                    $coin,
                    $interval,
                    $startTime + $step,
                    $step,
                ),
            ],
            'internal_gap' => [
                $row,
                ScriptedHyperliquidHistoricalClient::row(
                    $coin,
                    $interval,
                    $startTime + (2 * $step),
                    $step,
                ),
            ],
            'wrong_coin' => [array_replace($row, ['s' => $coin === 'BTC' ? 'ETH' : 'BTC'])],
            'wrong_interval' => [array_replace($row, ['i' => $interval === '1m' ? '5m' : '1m'])],
            'wrong_close' => [array_replace($row, ['T' => $row['T'] + 1])],
            'empty' => [],
            'too_many' => array_fill(0, 501, $row),
            default => throw new \LogicException('test_fault_invalid'),
        };
    }
}

final class NoCallHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    use MainnetHyperliquidHistoricalClientNetwork;

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        throw new \LogicException('historical_client_must_not_be_called');
    }
}

final readonly class NetworkTaggedNoCallHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    public function __construct(private PaperMarketDataNetwork $configuredNetwork)
    {
    }

    public function network(): PaperMarketDataNetwork
    {
        return $this->configuredNetwork;
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        throw new \LogicException('historical_client_must_not_be_called');
    }
}

final class RepeatedPageHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    use MainnetHyperliquidHistoricalClientNetwork;

    /** @var array<string, mixed>|null */
    private ?array $first = null;

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
        $this->first ??= ScriptedHyperliquidHistoricalClient::row(
            $coin,
            $interval,
            $startTime,
            $step,
        );

        return [$this->first];
    }
}

final class FullWindowHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    use MainnetHyperliquidHistoricalClientNetwork;

    /** @var list<array{start_time: int, end_time: int, row_count: int}> */
    public array $calls = [];

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);
        $rows = [];
        for ($time = $startTime; $time <= $endTime; $time += $step) {
            $rows[] = ScriptedHyperliquidHistoricalClient::row(
                $coin,
                $interval,
                $time,
                $step,
            );
        }
        $this->calls[] = [
            'start_time' => $startTime,
            'end_time' => $endTime,
            'row_count' => \count($rows),
        ];

        return $rows;
    }
}

final class InterruptingHyperliquidHistoricalClient implements HyperliquidPaperPublicRestClientInterface
{
    use MainnetHyperliquidHistoricalClientNetwork;

    private int $calls = 0;

    public function __construct(private readonly int $throwOnCall)
    {
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array {
        if (++$this->calls === $this->throwOnCall) {
            throw new \RuntimeException('scripted_transport_interruption');
        }
        $step = (new HyperliquidPaperInstrumentMap())->intervalMilliseconds($interval);

        return [ScriptedHyperliquidHistoricalClient::row(
            $coin,
            $interval,
            $startTime,
            $step,
        )];
    }
}
