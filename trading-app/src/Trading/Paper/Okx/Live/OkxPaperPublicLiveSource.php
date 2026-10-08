<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\PaperDurableBatchSourceInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Okx\Http\OkxPaperFundingRateClientInterface;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\ClockInterface;

final class OkxPaperPublicLiveSource implements PaperDurableBatchSourceInterface
{
    private const MAX_WARMUP_EVENT_BATCH = 100;
    private const MAX_DURABLE_FRAME_BATCH = 128;
    private const MAX_DURABLE_EVENT_BATCH = 256;
    private const TRADE_JUNCTION_RETRY_DELAYS_SECONDS = [0.25, 0.5, 1.0, 2.0, 4.0];
    private const BOOK_AUTHORITY_LOG_SECONDS = 10.0;
    private const BOOK_AUTHORITY_RESUBSCRIBE_SECONDS = 15.0;
    private const BOOK_AUTHORITY_MAX_WAIT_SECONDS = 60.0;
    /** Covers a recovery, or draining a full inbound buffer before the stop. */
    private const HEALTHY_STOP_MAX_DEFERRAL_SECONDS = 1_800.0;
    private const INBOUND_BUFFER_LOG_SECONDS = 10.0;
    private const MAX_REMEMBERED_REST_TRADES = 5_000;
    private const MAX_READ_BACK_REST_TRADES = 1_000;
    private const CANDLE_BAR_MILLISECONDS = ['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1H' => 3_600_000];

    private readonly OkxPaperInstrumentMap $instruments;
    private readonly OkxPaperPublicSubscriptionSet $subscriptions;
    private readonly OkxPaperPublicFrameDecoder $decoder;
    private readonly OkxPaperPublicFrameQueue $publicQueue;
    private readonly OkxPaperPublicFrameQueue $businessQueue;
    private readonly OkxPaperSourceOrdinal $ordinals;
    private readonly OkxPaperMarketEventNormalizer $normalizer;

    /** @var array<string, OkxPaperOrderBookMaterializer> */
    private array $books = [];

    /** @var array<string, mixed>|null */
    private ?array $continuationTransition = null;

    /** @var array<string, bool> */
    private array $requiresOverlap = [];

    /** @var array<string, array<string, OkxPaperStreamFrontier>> */
    private array $observedFrontiers = [];

    /** @var array<string, true> */
    private array $publicAcknowledgements = [];

    /** @var array<string, true> */
    private array $businessAcknowledgements = [];

    /** @var array<string, TimerInterface> */
    private array $resyncTimers = [];

    /** @var array<string, int> */
    private array $resyncGenerations = ['BTCUSDT' => 0, 'ETHUSDT' => 0];

    /** @var array<string, int> */
    private array $expiredResyncGenerations = [];

    private int $connectionGeneration;

    private ?TimerInterface $reconnectTimer = null;
    private ?TimerInterface $stabilityTimer = null;

    /** @var array{public: \DateTimeImmutable|null, business: \DateTimeImmutable|null} */
    private array $lastInboundAt = ['public' => null, 'business' => null];

    /** @var array<string, TimerInterface> */
    private array $heartbeatTimers = [];

    /** @var array<string, TimerInterface> */
    private array $pongTimers = [];

    /** @var array{public: int, business: int} */
    private array $heartbeatGenerations = ['public' => 0, 'business' => 0];

    /** @var array{public: bool, business: bool} */
    private array $heartbeatDeferredForBacklog = ['public' => false, 'business' => false];

    /** @var array<string, float> socket => when its heartbeat timer is due (clock seconds) */
    private array $heartbeatDueAt = [];

    /**
     * Outstanding pings: when sent, how late the heartbeat ran, frames read since,
     * whether admissions paused since (a backlog), when a deferral was last logged,
     * event-loop polls since and the longest gap between two of them.
     *
     * @var array<string, array{sent_at: float, heartbeat_late_s: float, frames: int, backlog: bool, logged_at: float|null, polls: int, max_poll_gap_s: float}>
     */
    private array $pingProbes = [];

    /**
     * Since when each socket has been read without interruption: set when it opens,
     * when we resume it, and when the event loop polls again after a gap (see
     * noteNetworkPoll()). Silence proves a socket dead only from then on.
     *
     * @var array{public: float|null, business: float|null}
     */
    private array $readActiveSince = ['public' => null, 'business' => null];

    private ?float $lastNetworkPollAt = null;

    /** @var array<string, \DateTimeImmutable> */
    private array $lastPongAt = [];

    /** @var array{public: int, business: int} */
    private array $pongGenerations = ['public' => 0, 'business' => 0];

    /** @var array{public: bool, business: bool} */
    private array $socketOpen = ['public' => false, 'business' => false];

    /** @var array{public: bool, business: bool} */
    private array $socketAdmissionsPaused = ['public' => false, 'business' => false];

    private bool $resumedHealthyStop;
    private bool $resumedHealthyStopDrain;
    private bool $resumedUnprovenHealthyStop;
    private bool $resumedBookRecovery;
    private bool $resumedStreaming;

    private ?string $nextEventStream = null;

    /** @var array<string, mixed> */
    private array $nextEventTransition = [];

    private ?string $activeQueuedSocket = null;
    private ?string $lastCompletedQueuedSocket = null;
    private int $activeQueuedEventsRemaining = 0;
    private int $activeQueuedFramesRemaining = 0;

    private ?OkxPaperLiveCheckpoint $durableEventBatchBase = null;
    private bool $durableFrameBatchingEnabled = false;
    /**
     * Symbols whose next websocket trade anchors the REST↔WS trade junction.
     *
     * @var array<string, true>
     */
    private array $tradeJunctions = [];
    /**
     * "SYMBOL/bar" whose next confirmed websocket candle anchors the candle junction.
     *
     * @var array<string, true>
     */
    private array $candleJunctions = [];
    /**
     * REST trade rows recently recorded per symbol (trade id => raw fields, the
     * highest ids), to compare websocket aggregates dropped at a junction with
     * their REST range.
     *
     * @var array<string, array<array-key, array<string, string>>>
     */
    private array $recentRestTrades = [];
    /**
     * The last REST history pages read back for such comparisons (trade id => raw
     * fields), apart so that they never evict the recorded rows (see
     * restTradesForRange()).
     *
     * @var array<string, array<array-key, array<string, string>>>
     */
    private array $readBackRestTrades = [];
    private LoggerInterface $logger;
    /** Set by an OKX service-upgrade notice: reconnect once the current batch is done. */
    private bool $serviceNoticeReconnectRequested = false;
    /** @var array<string, array<string, true>> instrument => acknowledgements still expected */
    private array $bookResubscriptions = [];
    /** @var array<string, true> pending symbols whose websocket book snapshot was dropped */
    private array $discardedBookSnapshots = [];
    /** @var array<string, true> pending symbols whose websocket book snapshot is kept in reserve */
    private array $reservedBookSnapshots = [];
    /** A healthy stop requested while a reconnect recovery is still in progress. */
    private ?\DateTimeImmutable $healthyStopDeferredSince = null;
    /** @var array{socket: string, frame: string|null, reason: string}|null */
    private ?array $lastRejectedFrame = null;
    private bool $networkTickActive = false;
    private bool $streamingQueuesDirty = false;
    private ?float $lastStreamingQueueSaveAt = null;
    /** @var array{public: OkxPaperInboundFrameBuffer, business: OkxPaperInboundFrameBuffer} */
    private array $inboundBuffers;
    private int $inboundBufferMaxBytes;
    /** @var array{public: bool, business: bool} paused because the inbound buffer is full */
    private array $inboundBufferPaused = ['public' => false, 'business' => false];
    private ?float $inboundBufferLoggedAt = null;
    private ?\Throwable $deferredQueuedFailure = null;
    private bool $preparingQueuedFrameBatch = false;

    /** @var array<string, OkxPaperStreamFrontier> */
    private array $preparedBookFrontiers = [];

    private bool $healthyStopRequested = false;
    private bool $healthyStopAdmissionQuiesced = false;
    private bool $stopped = false;

    public function __construct(
        private readonly OkxPaperPublicRestClientInterface $restClient,
        private readonly OkxPaperPublicWebSocketTransportInterface $publicTransport,
        private readonly OkxPaperPublicWebSocketTransportInterface $businessTransport,
        private readonly OkxPaperPublicConfig $config,
        private readonly ClockInterface $clock,
        private readonly OkxPaperLiveCheckpointStore $checkpointStore,
        private OkxPaperLiveCheckpoint $checkpoint,
        private readonly LoopInterface $loop,
        ?OkxPaperInstrumentMap $instruments = null,
        ?OkxPaperPublicSubscriptionSet $subscriptions = null,
        ?OkxPaperPublicFrameDecoder $decoder = null,
        ?OkxPaperPublicFrameQueue $publicQueue = null,
        ?OkxPaperPublicFrameQueue $businessQueue = null,
        private readonly ?OkxPaperInstrumentMetadataClientInterface $metadataClient = null,
        private readonly ?OkxPaperFundingRateClientInterface $fundingClient = null,
        private readonly int $initialHourlyCandleTarget = 1,
        private readonly ?OkxPaperLoopPumpInterface $loopPump = null,
        private readonly bool $anchoredTradeJunctions = false,
        ?LoggerInterface $logger = null,
        ?int $inboundBufferMaxBytes = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->inboundBuffers = [
            'public' => new OkxPaperInboundFrameBuffer(),
            'business' => new OkxPaperInboundFrameBuffer(),
        ];
        $this->inboundBufferMaxBytes = $inboundBufferMaxBytes ?? OkxPaperLivePolicy::INBOUND_BUFFER_MAX_BYTES;
        if ($this->initialHourlyCandleTarget < 1
            || $this->initialHourlyCandleTarget > OkxPaperLivePolicy::INITIAL_HOURLY_CANDLE_TARGET
        ) {
            throw new \InvalidArgumentException('okx_paper_live_hourly_warmup_target_invalid');
        }
        $this->instruments = $instruments ?? new OkxPaperInstrumentMap();
        $this->subscriptions = $subscriptions ?? new OkxPaperPublicSubscriptionSet($this->instruments);
        $this->decoder = $decoder ?? new OkxPaperPublicFrameDecoder($this->subscriptions);
        $this->publicQueue = $publicQueue ?? new OkxPaperPublicFrameQueue();
        $this->businessQueue = $businessQueue ?? new OkxPaperPublicFrameQueue();
        $this->ordinals = OkxPaperSourceOrdinal::restore($checkpoint->ordinalState);
        $this->normalizer = new OkxPaperMarketEventNormalizer(
            $this->clock,
            $this->instruments,
            $this->ordinals,
        );
        $this->connectionGeneration = $checkpoint->connectionEpoch;
        $this->resumedHealthyStopDrain = $checkpoint->phase === 'streaming'
            && $checkpoint->healthyStop['requested']
            && $checkpoint->healthyStop['liveness_proven'];
        $this->resumedUnprovenHealthyStop = $checkpoint->phase === 'streaming'
            && $checkpoint->healthyStop['requested']
            && !$checkpoint->healthyStop['liveness_proven'];
        $this->resumedHealthyStop = $checkpoint->phase === 'stopping'
            || $this->resumedHealthyStopDrain;
        $this->resumedBookRecovery = $checkpoint->phase === 'resyncing';
        $this->resumedStreaming = $checkpoint->phase === 'streaming'
            && !$this->resumedHealthyStopDrain
            && !$this->resumedUnprovenHealthyStop;
        $this->healthyStopRequested = $this->resumedHealthyStopDrain;
        $this->healthyStopAdmissionQuiesced = $this->resumedHealthyStopDrain;
        foreach ($this->instruments->nativeInstrumentIds() as $instrumentId) {
            $this->books[$instrumentId] = new OkxPaperOrderBookMaterializer();
        }
        $streamingQueues = $this->checkpointStore->streamingQueues($checkpoint);
        if ($streamingQueues !== ['public' => [], 'business' => []]) {
            $this->publicQueue->replace($streamingQueues['public']);
            $this->businessQueue->replace($streamingQueues['business']);
        }
        foreach ($checkpoint->resyncBySymbol as $symbol => $resync) {
            if (!\is_array($resync) || $resync['policy'] !== 'book_seq_overlap_v1') {
                continue;
            }
            if (\is_array($resync['book_snapshot'] ?? null)) {
                $this->books[$this->instruments->nativeInstrumentId($symbol)]
                    ->replaceSnapshot($resync['book_snapshot']);
            }
            break;
        }
        foreach ($checkpoint->streamFrontiers as $stream => $frontier) {
            $this->requiresOverlap[$stream] = $frontier instanceof OkxPaperStreamFrontier;
        }
        if ($this->resumedHealthyStopDrain) {
            foreach ($this->requiresOverlap as $stream => $_requiresOverlap) {
                if (str_contains($stream, '/ws/')) {
                    $this->requiresOverlap[$stream] = false;
                }
            }
        }
        foreach ($checkpoint->resyncBySymbol as $symbol => $resync) {
            if (\is_array($resync)
                && $resync['policy'] === 'book_seq_overlap_v1'
                && \is_array($resync['book_snapshot'] ?? null)
            ) {
                $this->requiresOverlap[$symbol . '/ws/top_of_book'] = false;
            }
        }
        if ($checkpoint->phase === 'reconnecting'
            && ($checkpoint->pendingTransition['stage'] ?? null) === 'reconnect_delay'
            && \is_string($checkpoint->reconnect['deadline_at'])
        ) {
            $deadline = new \DateTimeImmutable($checkpoint->reconnect['deadline_at']);
            $remaining = max(
                0.0,
                (float) $deadline->format('U.u')
                    - (float) $this->clock->now()->format('U.u'),
            );
            $this->scheduleReconnectTimer($remaining);
        }
        if ($checkpoint->phase === 'streaming'
            && $checkpoint->reconnect['attempt'] > 0
            && \is_string($checkpoint->reconnect['stable_since'])
        ) {
            $this->scheduleStabilityResetTimer();
        }
    }

    public function venue(): PaperMarketDataVenue
    {
        return PaperMarketDataVenue::OKX;
    }

    public function events(): iterable
    {
        try {
            yield from $this->eventFlow();
        } catch (\Throwable $exception) {
            if ($this->durableEventBatchBase instanceof OkxPaperLiveCheckpoint) {
                $this->checkpoint = $this->durableEventBatchBase;
                $this->durableEventBatchBase = null;
            }
            if (!\in_array($this->checkpoint->phase, ['failed', 'complete'], true)) {
                $reason = $this->terminalPublicFailureReason($exception);
                if ($reason !== null) {
                    $this->failTerminal($reason, $exception);
                }
            }
            $this->logSourceFailure($exception);

            throw $exception;
        }
    }

    /** @return iterable<PaperMarketEvent> */
    private function eventFlow(): iterable
    {
        $initialCandleBridgeRequired = \in_array(
            $this->checkpoint->phase,
            ['warming', 'connecting', 'subscribing'],
            true,
        );
        if (!$this->config->acquisitionEnabled) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_acquisition_disabled');
        }

        if ($this->checkpoint->pendingEvent !== null) {
            $this->continuationTransition = $this->pendingWarmupContinuation()
                ?? $this->pendingReconnectContinuation();
            if ($this->continuationTransition !== null) {
                $this->requiresOverlap[$this->continuationTransition['stream']] = true;
            }
            yield $this->checkpoint->pendingEvent;
            $this->assertPendingWasAcknowledged();
        }

        if ($this->checkpoint->phase === 'failed') {
            throw new OkxPaperLiveIntegrityException(
                $this->checkpoint->failureReason
                    ?? 'okx_paper_public_reconnect_exhausted',
            );
        }
        if ($this->checkpoint->phase === 'complete') {
            return;
        }
        if ($this->resumedUnprovenHealthyStop) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        if ($this->checkpoint->phase === 'warming') {
            yield from $this->warmup();
        }
        if ($this->checkpoint->phase === 'stopping') {
            yield from $this->healthyStopFlow();

            return;
        }
        if ($this->resumedHealthyStopDrain) {
            yield from $this->resumedHealthyStopDrainFlow();

            return;
        }
        if ($this->resumedStreaming && $this->checkpoint->phase === 'streaming') {
            $this->resumedStreaming = false;
            $this->beginPairedReconnect();
        }

        if ($this->checkpoint->phase === 'resyncing') {
            if ($this->resumedBookRecovery) {
                $this->connectResumedBookRecoverySockets();
                $this->resumedBookRecovery = false;
                if ($this->stopped) {
                    return;
                }
            }
            if ($this->checkpoint->phase === 'resyncing') {
                if ($this->hasAcknowledgedResyncSnapshot()) {
                    yield from $this->emitResyncBoundary();
                } else {
                    $events = $this->resumePersistedBookResync();
                    $stream = $this->nextEventStream
                        ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
                    $transition = $this->nextEventTransition;
                    $this->nextEventStream = null;
                    $this->nextEventTransition = [];
                    yield from $this->yieldMarketEvents($events, $stream, $transition);
                }
            }
        } elseif ($this->checkpoint->phase === 'reconnecting') {
            $this->resumeReconnectTransportTransition();
        } elseif ($this->checkpoint->phase !== 'streaming') {
            $this->connectAndSubscribe();
        }
        if (!\in_array($this->checkpoint->phase, ['resyncing', 'reconnecting'], true)) {
            $this->awaitReadiness();
        }
        if (\in_array($this->checkpoint->phase, ['connecting', 'subscribing'], true)) {
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->checkpoint,
                'streaming',
                null,
            );
        }
        if ($initialCandleBridgeRequired && $this->checkpoint->phase === 'streaming') {
            $this->openTradeJunctions();
            $this->openCandleJunctions();
        }
        if ($initialCandleBridgeRequired && $this->checkpoint->phase === 'streaming') {
            yield from $this->bridgeInitialCandles();
        }
        if ($this->checkpoint->phase === 'streaming') {
            $this->startHeartbeatTimers();
        }
        while (!$this->stopped) {
            $this->resumeDeferredHealthyStop();
            if ($this->healthyStopRequested) {
                $this->persistHealthyStopWhenDrained();
            }
            if ($this->checkpoint->phase === 'stopping') {
                yield from $this->healthyStopFlow();

                return;
            }
            if ($this->serviceNoticeReconnectRequested) {
                $this->serviceNoticeReconnectRequested = false;
                if ($this->checkpoint->phase === 'streaming') {
                    $this->warn('okx_paper_public_planned_reconnect', ['trigger' => 'service_notice']);
                    $this->beginPairedReconnect();

                    continue;
                }
            }
            if ($this->checkpoint->phase === 'reconnecting'
                && !$this->subscriptions->isReady()
            ) {
                $this->awaitReadiness();

                continue;
            }
            if ($this->checkpoint->phase === 'reconnecting'
                && $this->subscriptions->isReady()
            ) {
                yield from $this->reconnectRecoveryFlow();

                continue;
            }
            if ($this->hasAcknowledgedResyncSnapshot()) {
                yield from $this->emitResyncBoundary();

                continue;
            }
            $junctionEvents = $this->tradeJunctionRestEvents();
            if ($junctionEvents !== []) {
                // Recorded from REST: acknowledged on the REST trade stream.
                $junctionStream = $junctionEvents[0]['event']->symbol . '/rest/public_trade';
                yield from $this->yieldMarketEvents(
                    $junctionEvents,
                    $junctionStream,
                    [],
                    eventStreamOverride: $junctionStream,
                );

                continue;
            }
            $candleJunction = $this->candleJunctionRestEvents();
            if ($candleJunction !== null) {
                [$candleStream, $candleEvents] = $candleJunction;
                yield from $this->yieldMarketEvents(
                    $candleEvents,
                    $candleStream,
                    [],
                    eventStreamOverride: $candleStream,
                );

                continue;
            }
            $events = $this->nextStreamingEvents();
            if ($events === []) {
                continue;
            }
            $stream = $this->nextEventStream ?? $this->streamForEvent($events[0]['event']);
            $transition = $this->nextEventTransition;
            $this->nextEventStream = null;
            $this->nextEventTransition = [];
            yield from $this->yieldMarketEvents(
                $events,
                $stream,
                $transition,
            );
            $this->completeActiveQueuedFrame();
        }
    }

    public function acknowledge(string $eventId): void
    {
        try {
            if ($this->durableEventBatchBase instanceof OkxPaperLiveCheckpoint) {
                $prepared = $this->continuationTransition === null
                    && $this->checkpoint->phase === 'streaming'
                    && $this->checkpoint->reconnect['attempt'] === 0
                    ? $this->checkpointStore->prepareStreamingBatchAcknowledgement(
                        $this->checkpoint,
                        $eventId,
                    )
                    : $this->checkpointStore->prepareDurableBatchAcknowledgement(
                        $this->checkpoint,
                        $eventId,
                        $this->continuationTransition,
                    );
                $this->checkpoint = $this->activeQueuedEventsRemaining === 1
                    ? $this->checkpointStore->commitPreparedEventBatch(
                        $this->durableEventBatchBase,
                        $prepared,
                    )
                    : $prepared;
                if ($this->activeQueuedEventsRemaining === 1) {
                    $this->durableEventBatchBase = null;
                }
            } else {
                $this->checkpoint = $this->checkpointStore->acknowledgeOpaqueUnsequenced(
                    $this->checkpoint,
                    $eventId,
                    $this->continuationTransition,
                );
            }
        } catch (\Throwable $exception) {
            if ($this->isIdentityConflict($exception)) {
                $this->failTerminal('market_event_identity_conflict');
            }
            if ($exception->getMessage() === 'market_data_backpressure_exhausted') {
                $this->failTerminal('market_data_backpressure_exhausted');
            }

            throw $exception;
        }
        $this->continuationTransition = null;
        if ($this->activeQueuedEventsRemaining > 0) {
            --$this->activeQueuedEventsRemaining;
            if ($this->activeQueuedEventsRemaining === 0) {
                if ($this->activeQueuedSocket !== null) {
                    $this->completeActiveQueuedFrame();
                } else {
                    // REST warmup/recovery batches are durable boundaries too.
                    // Give websocket control and data callbacks one bounded tick
                    // before the next synchronous REST/persistence unit starts.
                    $this->pumpNetworkLoop();
                }
            }
        } elseif ($this->activeQueuedSocket === null) {
            // Single REST/control events do not create an explicit batch, but
            // their acknowledgement is still a safe durable pump boundary.
            $this->pumpNetworkLoop();
        }
    }

    public function pendingDurableBatchSize(): int
    {
        if ($this->activeQueuedSocket !== null) {
            $this->durableFrameBatchingEnabled = true;
        }
        if ($this->activeQueuedEventsRemaining > 1
            && !$this->durableEventBatchBase instanceof OkxPaperLiveCheckpoint
        ) {
            if ($this->checkpoint->pendingEvent === null) {
                throw new \LogicException('okx_paper_durable_batch_boundary_invalid');
            }
            $this->durableEventBatchBase = $this->checkpoint;
        }

        return max(1, $this->activeQueuedEventsRemaining);
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        ++$this->connectionGeneration;
        $this->publicTransport->close();
        $this->businessTransport->close();
        if ($this->reconnectTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->reconnectTimer);
            $this->reconnectTimer = null;
        }
        if ($this->stabilityTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->stabilityTimer);
            $this->stabilityTimer = null;
        }
        foreach ($this->resyncTimers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->resyncTimers = [];
        $this->cancelHeartbeatTimers();
        $this->publicQueue->clear();
        $this->businessQueue->clear();
        $this->loop->stop();
    }

    public function isComplete(): bool
    {
        return $this->checkpoint->phase === 'complete';
    }

    public function requestHealthyOperatorStop(): void
    {
        if ($this->checkpoint->phase === 'stopping'
            && $this->checkpoint->healthyStop['requested']
        ) {
            return;
        }
        if ($this->healthyStopRequested) {
            return;
        }
        $recovering = !$this->healthyStopStructuralPreconditionsHold() && $this->reconnectRecoveryInProgress();
        if ($recovering || $this->inboundBufferedFrames() !== 0) {
            // Never a complete dataset with an unrecovered gap: the bounded recovery
            // finishes first, then the stop proceeds (see eventFlow()). Frames read
            // but only in memory are drained first too: a stop resumes without
            // reconnect after a restart, so its queue must be durable.
            if ($this->healthyStopDeferredSince === null) {
                $this->healthyStopDeferredSince = $this->clock->now();
                $this->warn('okx_paper_public_healthy_stop_deferred', [
                    'reason' => $recovering ? 'reconnect_recovery' : 'inbound_backlog',
                    'inbound_buffered_frames' => $this->inboundBufferedFrames(),
                ]);
            }

            return;
        }
        $this->healthyStopDeferredSince = null;
        if (!$this->healthyStopStructuralPreconditionsHold()
            || (!$this->socketLivenessProofCompleteForStop()
                && !$this->canAwaitSocketFreshness())
        ) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        $this->persistDirtyStreamingQueues(true);
        $this->checkpoint = $this->checkpointStore->requestHealthyStopDrain(
            $this->checkpoint,
        );
        $this->healthyStopRequested = true;
        $this->persistHealthyStopWhenDrained();
    }

    private function reconnectRecoveryInProgress(): bool
    {
        return \in_array($this->checkpoint->phase, ['reconnecting', 'resyncing'], true)
            || $this->checkpoint->reconnect['attempt'] !== 0
            || array_filter(
                $this->checkpoint->resyncBySymbol,
                static fn (mixed $resync): bool => $resync !== null,
            ) !== [];
    }

    private function resumeDeferredHealthyStop(): void
    {
        if ($this->healthyStopDeferredSince === null || $this->healthyStopRequested) {
            return;
        }
        if ($this->checkpoint->phase === 'streaming'
            && $this->checkpoint->pendingEvent === null
            && $this->checkpoint->pendingTransition === null
            && $this->healthyStopStructuralPreconditionsHold()
            && $this->inboundBufferedFrames() === 0
        ) {
            $this->warn('okx_paper_public_healthy_stop_resumed', []);
            $this->requestHealthyOperatorStop();

            return;
        }
        $deferred = (float) $this->clock->now()->format('U.u')
            - (float) $this->healthyStopDeferredSince->format('U.u');
        if ($deferred > self::HEALTHY_STOP_MAX_DEFERRAL_SECONDS) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
    }

    private function persistHealthyStopWhenDrained(): void
    {
        if (!$this->healthyStopRequested
        ) {
            return;
        }
        if (!$this->resumedHealthyStopDrain
            && !$this->healthyStopStructuralPreconditionsHold()
        ) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        if (!$this->healthyStopAdmissionQuiesced) {
            if (!$this->socketLivenessProofCompleteForStop()) {
                if (!$this->canAwaitSocketFreshness()) {
                    $this->failTerminal('okx_paper_public_healthy_stop_invalid');
                }

                return;
            }
            $this->confirmAndQuiesceHealthyStopAdmission();
        }
        if ($this->checkpoint->pendingEvent !== null
            || $this->checkpoint->pendingTransition !== null
            || $this->activeQueuedSocket !== null
            || $this->publicQueue->count() !== 0
            || $this->businessQueue->count() !== 0
        ) {
            return;
        }
        if (!$this->resumedHealthyStopDrain
            && !$this->healthyStopPreconditionsHold()
        ) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        $state = $this->checkpoint->toArray();
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['healthy_stop'] = [
            'liveness_proven' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
            'requested' => true,
        ];
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $candidate,
            'stopping',
            $this->stoppedEventTransition('BTCUSDT'),
        );
        $this->healthyStopRequested = false;
        $this->resumedHealthyStopDrain = false;
    }

    public function failureReason(): ?string
    {
        return $this->checkpoint->failureReason;
    }

    /**
     * @return \Generator<int, PaperMarketEvent>
     */
    private function warmup(): \Generator
    {
        foreach ($this->checkpoint->remainingSymbols as $symbol) {
            $instrumentId = $this->instruments->nativeInstrumentId($symbol);
            if ($this->metadataClient instanceof OkxPaperInstrumentMetadataClientInterface) {
                $metadataStream = $symbol . '/rest/instrument_metadata';
                $metadataTransition = $this->restTransition(
                    $symbol,
                    $metadataStream,
                    'instrument_metadata',
                );
                if ($this->shouldExecuteWarmupTransition($metadataStream, $metadataTransition)) {
                    $this->ensureTransition('warming', $metadataTransition);
                    $row = $this->metadataClient->instrumentMetadata($instrumentId);
                    $event = $this->normalizer->instrumentMetadata(
                        $row,
                        $this->checkpoint->sourceEpochs[$symbol],
                    );
                    $frontier = OkxPaperStreamFrontier::fromEvent($event);
                    yield from $this->yieldMarketEvents([[
                        'event' => $event,
                        'frontier' => $frontier,
                        'ordinal_state' => $this->ordinals->snapshot(),
                    ]], $metadataStream, $metadataTransition);
                }
            }
            if ($this->fundingClient instanceof OkxPaperFundingRateClientInterface) {
                $fundingStream = $symbol . '/rest/funding_rate';
                $fundingTransition = $this->restTransition($symbol, $fundingStream, 'funding_rate');
                if ($this->shouldExecuteWarmupTransition($fundingStream, $fundingTransition)) {
                    $this->ensureTransition('warming', $fundingTransition);
                    $row = $this->fundingClient->fundingRate($instrumentId);
                    $event = $this->normalizer->fundingRate(
                        $row,
                        $this->checkpoint->sourceEpochs[$symbol],
                    );
                    $frontier = OkxPaperStreamFrontier::fromEvent($event);
                    yield from $this->yieldMarketEvents([[
                        'event' => $event,
                        'frontier' => $frontier,
                        'ordinal_state' => $this->ordinals->snapshot(),
                    ]], $fundingStream, $fundingTransition);
                }
            }
            foreach (['1m', '5m', '15m', '1H'] as $bar) {
                $stream = $symbol . '/rest/candle_' . $bar;
                $transition = $this->restTransition($symbol, $stream, 'current_candles');
                if (!$this->shouldExecuteWarmupTransition($stream, $transition)) {
                    continue;
                }
                $this->ensureTransition('warming', $transition);
                $rows = $this->initialCandleRows(
                    $instrumentId,
                    $bar,
                );
                $this->sortCandleRows($rows);
                yield from $this->yieldWarmupRowEvents(
                    $stream,
                    $transition,
                    $rows,
                    fn (array $row, OkxPaperMarketEventNormalizer $normalizer): ?PaperMarketEvent => $normalizer
                        ->warmupCandle($instrumentId, $bar, $row),
                    fn (array $row): ?OkxPaperStreamFrontier => $this->candleFrontier(
                        $instrumentId,
                        $bar,
                        $row,
                    ),
                    true,
                );
            }

            $tradeStream = $symbol . '/rest/public_trade';
            $tradeTransition = $this->restTransition(
                $symbol,
                $tradeStream,
                'recent_trades',
            );
            if ($this->shouldExecuteWarmupTransition($tradeStream, $tradeTransition)) {
                $this->ensureTransition('warming', $tradeTransition);
                $rows = $this->restClient->recentTrades($instrumentId, 500);
                $this->sortTradeRows($rows);
                yield from $this->yieldWarmupRowEvents(
                    $tradeStream,
                    $tradeTransition,
                    $rows,
                    static fn (
                        array $row,
                        OkxPaperMarketEventNormalizer $normalizer,
                    ): PaperMarketEvent => $normalizer->recoveryTrade($row),
                    fn (array $row): OkxPaperStreamFrontier => $this->tradeFrontier($row),
                );
            }

            $bookStream = $symbol . '/rest/top_of_book';
            $bookTransition = $this->restTransition($symbol, $bookStream, 'order_book');
            if ($this->shouldExecuteWarmupTransition($bookStream, $bookTransition)) {
                $this->ensureTransition('warming', $bookTransition);
                $rows = $this->restClient->orderBook($instrumentId, 400);
                if (\count($rows) !== 1 || !\is_array($rows[0])) {
                    throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
                }
                $state = $this->books[$instrumentId]->replaceSnapshot($rows[0]);
                $events = $this->acceptedBookEvents(
                    $bookStream,
                    $instrumentId,
                    $state,
                    'rest_initial_snapshot',
                    $this->checkpoint->sourceEpochs[$symbol],
                );
                yield from $this->yieldMarketEvents($events, $bookStream, $bookTransition);
            }

            $boundaryStream = $symbol . '/control/snapshot_boundary';
            $boundaryTransition = [
                'kind' => 'emit_boundary',
                'symbol' => $symbol,
                'stream' => $boundaryStream,
                'stage' => 'initial',
            ];
            if (($this->checkpoint->remainingSymbols[0] ?? null) !== $symbol) {
                continue;
            }
            $this->ensureTransition('warming', $boundaryTransition);
            $bookFrontier = $this->checkpoint->streamFrontiers[$bookStream] ?? null;
            if (!$bookFrontier instanceof OkxPaperStreamFrontier) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
            }
            $boundary = $this->normalizer->snapshotBoundary(
                $instrumentId,
                'initial',
                $this->checkpoint->sourceEpochs[$symbol],
                $bookFrontier->sourceIdentity,
            );
            $this->checkpoint = $this->checkpointStore->savePending(
                $this->checkpoint,
                $boundary,
                $this->ordinals->snapshot(),
                null,
            );
            yield $this->checkpoint->pendingEvent
                ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            $this->assertPendingWasAcknowledged();
        }
    }

    /**
     * B1: once streaming, initially and after a reconnect recovery, the REST↔WS
     * trade junction of each symbol is anchored on its first websocket trade
     * (see tradeJunctionRestEvents()) instead of an exact overlap.
     */
    private function openTradeJunctions(): void
    {
        if (!$this->anchoredTradeJunctions) {
            return;
        }
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $this->tradeJunctions[$symbol] = true;
            $this->requiresOverlap[$symbol . '/ws/public_trade'] = false;
        }
    }

    /** @param array<array-key, mixed> $message */
    private function anchorsTradeJunction(array $message): bool
    {
        $argument = $message['arg'] ?? null;
        if ($this->tradeJunctions === []
            || !\is_array($argument)
            || ($argument['channel'] ?? null) !== 'trades'
            || !\is_string($argument['instId'] ?? null)
        ) {
            return false;
        }

        return isset($this->tradeJunctions[$this->instruments->normalizedSymbol($argument['instId'])]);
    }

    /**
     * Records from REST, before the first websocket trade of a symbol whose
     * junction is open, every trade between the newest recorded trade frontier
     * and that websocket trade, by trade id: whether the websocket started
     * before or after the REST snapshot, and when an aggregate straddles the
     * frontier (its trades are then recorded from REST instead of it). Websocket
     * trades already recorded are dropped by rowsAfterTradeJunction().
     *
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function tradeJunctionRestEvents(): array
    {
        if ($this->tradeJunctions === [] || $this->publicQueue->count() === 0) {
            return [];
        }
        try {
            $message = $this->decoder->decodePublic($this->publicQueue->frames()[0]);
            $instrumentId = $message['arg']['instId'] ?? null;
            $rows = $message['data'] ?? null;
            if (!$this->anchorsTradeJunction($message)
                || !\is_string($instrumentId)
                || !\is_array($rows)
            ) {
                return [];
            }
            $ranges = [];
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    return [];
                }
                $ranges[] = $this->tradeRowRange($row);
            }
        } catch (\Throwable) {
            // The queued frame path reports invalid frames.
            return [];
        }
        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        $newest = $this->newestTradeFrontier($symbol);
        if (!$newest instanceof OkxPaperStreamFrontier) {
            return [];
        }
        foreach ($ranges as [$first, $last]) {
            if (self::compareUnsigned($last, $newest->sourceIdentity) <= 0) {
                continue;
            }
            if ($first === self::nextTradeId($newest->sourceIdentity)) {
                return [];
            }
            $through = self::compareUnsigned($first, $newest->sourceIdentity) <= 0
                ? $last
                : (string) BigInteger::of($first)->minus(1);
            $junctionRows = $this->junctionHistoryRows($instrumentId, $newest, $through);
            if ($junctionRows === null) {
                return [];
            }
            // junctionHistoryRows() proved the overlap with the newest frontier.
            $restStream = $symbol . '/rest/public_trade';
            $this->requiresOverlap[$restStream] = false;

            return $this->acceptedEvents(
                $restStream,
                $junctionRows,
                static fn (
                    array $row,
                    OkxPaperMarketEventNormalizer $normalizer,
                ): PaperMarketEvent => $normalizer->recoveryTrade($row),
                fn (array $row): OkxPaperStreamFrontier => $this->tradeFrontier($row),
                false,
                'rest',
            );
        }

        return [];
    }

    /**
     * Trades (newest, through] from history pages by trade id. The page reaching
     * the newest frontier must hold it unchanged and every id in between must be
     * served: ids REST does not serve yet (it can lag behind the websocket) are
     * fetched again after a bounded backoff, never recorded as a gap.
     *
     * @return list<array<array-key, mixed>>|null null once the connection changed
     */
    private function junctionHistoryRows(
        string $instrumentId,
        OkxPaperStreamFrontier $newest,
        string $through,
    ): ?array {
        $expected = BigInteger::of($through)->minus($newest->sourceIdentity);
        if ($expected->isLessThan(1)
            || $expected->isGreaterThan(OkxPaperLivePolicy::MAX_RETAINED_RECOVERY_ROWS)
        ) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $generation = $this->connectionGeneration;
        /** @var array<array-key, array<array-key, mixed>> $rows */
        $rows = [];
        /** @var array<array-key, string> $digests */
        $digests = [];
        foreach ([0.0, ...self::TRADE_JUNCTION_RETRY_DELAYS_SECONDS] as $delay) {
            if ($delay > 0.0) {
                $this->clock->sleep($delay);
                $this->pumpNetworkLoop();
                if (!$this->junctionConnectionUnchanged($generation)) {
                    return null;
                }
            }
            $anchored = false;
            $cursor = self::nextTradeId($through);
            for ($page = 0; ; ++$page) {
                if ($page >= OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES) {
                    $this->failTerminal('market_data_gap_unresolved');
                }
                $older = $this->restClient->historyTrades($instrumentId, 1, $cursor, 100);
                $this->pumpNetworkLoop();
                if (!$this->junctionConnectionUnchanged($generation)) {
                    return null;
                }
                if ($older === []) {
                    break;
                }
                if (\count($older) > 100) {
                    $this->failTerminal('market_data_gap_unresolved');
                }
                $cursor = $this->validatedOldestHistoryTradeId($older, $instrumentId, 1, $cursor);
                foreach ($older as $row) {
                    $candidate = $this->tradeFrontier($row);
                    $tradeId = $candidate->sourceIdentity;
                    if (hash_equals($newest->naturalIdentity, $candidate->naturalIdentity)) {
                        if (!hash_equals($newest->overlapDigest, $candidate->overlapDigest)) {
                            $this->failTerminal('market_event_identity_conflict');
                        }
                        $anchored = true;
                    } elseif (self::compareUnsigned($tradeId, $newest->sourceIdentity) > 0) {
                        if (isset($digests[$tradeId])
                            && !hash_equals($digests[$tradeId], $candidate->canonicalDigest)
                        ) {
                            $this->failTerminal('market_event_identity_conflict');
                        }
                        $digests[$tradeId] = $candidate->canonicalDigest;
                        $rows[$tradeId] = $row;
                    }
                }
                if (self::compareUnsigned($cursor, $newest->sourceIdentity) <= 0) {
                    break;
                }
            }
            if ($anchored && $expected->isEqualTo(\count($rows))) {
                $junctionRows = array_values($rows);
                $this->sortTradeRows($junctionRows);

                return $junctionRows;
            }
        }
        $this->failTerminal('market_data_gap_unresolved');
    }

    /** @phpstan-impure */
    private function junctionConnectionUnchanged(int $generation): bool
    {
        return !$this->stopped
            && $this->checkpoint->phase === 'streaming'
            && $this->connectionGeneration === $generation;
    }

    /**
     * Drops websocket trades already recorded (through REST), then closes the
     * junction on the first trade that directly follows the newest frontier;
     * tradeJunctionRestEvents() recorded any trade in between beforehand.
     *
     * @param array<array-key, mixed> $rows
     * @return array<array-key, mixed>
     */
    private function rowsAfterTradeJunction(string $symbol, array $rows): array
    {
        $newest = $this->newestTradeFrontier($symbol);
        if (!$newest instanceof OkxPaperStreamFrontier) {
            unset($this->tradeJunctions[$symbol]);

            return $rows;
        }
        foreach (array_values($rows) as $index => $row) {
            if (!\is_array($row)) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
            }
            [$first, $last] = $this->tradeRowRange($row);
            if (self::compareUnsigned($last, $newest->sourceIdentity) <= 0) {
                $this->assertDroppedTradeMatchesRest($symbol, $row, $first, $last);

                continue;
            }
            if ($first !== self::nextTradeId($newest->sourceIdentity)) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            unset($this->tradeJunctions[$symbol]);

            return \array_slice(array_values($rows), $index);
        }

        return [];
    }

    /** @param array<array-key, mixed> $rows */
    private function rememberRestTradeRows(string $symbol, array $rows): void
    {
        $rowsById = self::restTradeFields($rows);
        if (\count($rowsById) > self::MAX_REMEMBERED_REST_TRADES) {
            // A long recovery accepts up to 25,500 rows at once: only its highest
            // ids can stay, so the others are not even copied (memory stays bounded).
            ksort($rowsById, \SORT_NUMERIC);
            $rowsById = \array_slice($rowsById, -self::MAX_REMEMBERED_REST_TRADES, null, true);
        }
        foreach ($rowsById as $tradeId => $fields) {
            $this->recentRestTrades[$symbol][$tradeId] = $fields;
        }
        if (\count($this->recentRestTrades[$symbol] ?? []) > self::MAX_REMEMBERED_REST_TRADES) {
            // The highest ids: the websocket aggregates dropped after a recovery
            // overlap its newest rows, whatever order the rows were recorded in.
            ksort($this->recentRestTrades[$symbol], \SORT_NUMERIC);
            $this->recentRestTrades[$symbol] = \array_slice(
                $this->recentRestTrades[$symbol],
                -self::MAX_REMEMBERED_REST_TRADES,
                null,
                true,
            );
        }
    }

    /** @param array<array-key, mixed> $rows */
    private function rememberReadBackRestTradeRows(string $symbol, array $rows): void
    {
        foreach (self::restTradeFields($rows) as $tradeId => $fields) {
            unset($this->readBackRestTrades[$symbol][$tradeId]);
            $this->readBackRestTrades[$symbol][$tradeId] = $fields;
        }
        if (\count($this->readBackRestTrades[$symbol] ?? []) > self::MAX_READ_BACK_REST_TRADES) {
            $this->readBackRestTrades[$symbol] = \array_slice(
                $this->readBackRestTrades[$symbol],
                -self::MAX_READ_BACK_REST_TRADES,
                null,
                true,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $rows
     * @return array<array-key, array<string, string>> trade id => raw fields
     */
    private static function restTradeFields(array $rows): array
    {
        $trades = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $fields = [];
            foreach (['tradeId', 'px', 'sz', 'side', 'ts', 'source'] as $key) {
                if (!\is_string($row[$key] ?? null)) {
                    continue 2;
                }
                $fields[$key] = $row[$key];
            }
            $trades[$fields['tradeId']] = $fields;
        }

        return $trades;
    }

    /**
     * A websocket trade dropped at a junction is already recorded through REST.
     * OKX aggregates the fills of one taker order at one price and timestamp under
     * the id of its last fill (1,482 aggregates compared with REST: always one
     * price, the same side and timestamp, sizes adding up exactly), so it is
     * compared with every REST row of [first, last], never with the last one only.
     * Any difference fails closed, the aggregate and its REST rows chained. `source`
     * is not part of the identity: OKX websocket and REST can disagree on it for the
     * same trade (production: 1 vs 0); a divergence is logged. The dataset keeps the
     * `source` of the path that recorded the trade (REST rows for warmup, recovery
     * and junctions, websocket rows while streaming); each trade is recorded once.
     *
     * @param array<array-key, mixed> $row
     */
    private function assertDroppedTradeMatchesRest(
        string $symbol,
        array $row,
        string $first,
        string $last,
    ): void {
        $restRows = $this->restTradesForRange($symbol, $first, $last);
        $matches = $restRows !== null;
        $size = BigDecimal::zero();
        $sourceDivergences = [];
        foreach ($restRows ?? [] as $restRow) {
            $size = $size->plus($restRow['sz']);
            $matches = $matches
                && BigDecimal::of($restRow['px'])->isEqualTo((string) $row['px'])
                && $restRow['side'] === $row['side']
                && $restRow['ts'] === $row['ts'];
            if ($restRow['source'] !== $row['source']) {
                $sourceDivergences[] = ['trade_id' => $restRow['tradeId'], 'rest_source' => $restRow['source']];
            }
        }
        if ($matches && $size->isEqualTo((string) $row['sz'])) {
            if ($sourceDivergences !== []) {
                $this->warn('okx_paper_public_trade_source_divergence', [
                    'symbol' => $symbol,
                    'trade_id' => $row['tradeId'] ?? null,
                    'aggregate_count' => $row['count'] ?? null,
                    'websocket_source' => $row['source'],
                    'rest_sources' => $sourceDivergences,
                ]);
            }

            return;
        }

        throw new OkxPaperLiveIntegrityException(
            'market_event_identity_conflict',
            0,
            new \RuntimeException('okx_paper_junction_trade_mismatch ' . json_encode([
                'websocket' => array_intersect_key(
                    $row,
                    array_flip(['tradeId', 'count', 'px', 'sz', 'side', 'ts', 'source']),
                ),
                'rest' => $restRows,
            ], \JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * REST rows [first, last] as recorded, read back from REST history when they
     * were recorded before a restart (or beyond the remembered rows). The dropped
     * aggregates come in increasing ids, so a read-back page starts at `first`
     * and also covers the following ~99 ids: one REST request per ~100 ids, not
     * one per aggregate (which drained ~3.5 aggregates/s after a long recovery).
     *
     * @return list<array<string, string>>|null null when REST does not serve them all
     */
    private function restTradesForRange(string $symbol, string $first, string $last): ?array
    {
        $count = BigInteger::of($last)->minus($first)->plus(1);
        if ($count->isGreaterThan(1_000)) {
            return null;
        }
        $rows = $this->rememberedRestTrades($symbol, $first, $count->toInt());
        if ($rows !== null) {
            return $rows;
        }
        $instrumentId = $this->instruments->nativeInstrumentId($symbol);
        // History type 1 serves the 100 trades older than the cursor.
        $cursor = (string) BigInteger::max(
            BigInteger::of($first)->plus(100),
            BigInteger::of($last)->plus(1),
        );
        for ($page = 0; $page <= intdiv($count->toInt(), 100) + 1; ++$page) {
            $older = $this->restClient->historyTrades($instrumentId, 1, $cursor, 100);
            if ($older === [] || \count($older) > 100) {
                break;
            }
            $this->rememberReadBackRestTradeRows($symbol, $older);
            $cursor = $this->validatedOldestHistoryTradeId($older, $instrumentId, 1, $cursor);
            if (self::compareUnsigned($cursor, $first) <= 0) {
                break;
            }
        }

        return $this->rememberedRestTrades($symbol, $first, $count->toInt());
    }

    /** @return list<array<string, string>>|null */
    private function rememberedRestTrades(string $symbol, string $first, int $count): ?array
    {
        $rows = [];
        $tradeId = BigInteger::of($first);
        for ($index = 0; $index < $count; ++$index) {
            $row = $this->recentRestTrades[$symbol][(string) $tradeId]
                ?? $this->readBackRestTrades[$symbol][(string) $tradeId]
                ?? null;
            if ($row === null) {
                return null;
            }
            $rows[] = $row;
            $tradeId = $tradeId->plus(1);
        }

        return $rows;
    }

    private function newestTradeFrontier(string $symbol): ?OkxPaperStreamFrontier
    {
        $newest = null;
        foreach (['/rest/public_trade', '/ws/public_trade'] as $suffix) {
            $frontier = $this->checkpoint->streamFrontiers[$symbol . $suffix] ?? null;
            if ($frontier instanceof OkxPaperStreamFrontier
                && (!$newest instanceof OkxPaperStreamFrontier
                    || self::compareUnsigned($frontier->sourceIdentity, $newest->sourceIdentity) > 0)
            ) {
                $newest = $frontier;
            }
        }

        return $newest;
    }

    /**
     * C1: once streaming (initially and after a reconnect recovery), the REST↔WS
     * candle junction of each symbol and bar is anchored on its first confirmed
     * websocket candle: the websocket never sends an already confirmed candle
     * again, so the exact overlap with the recovered frontier that the websocket
     * stream required after a reconnect could never come (every reconnect failed
     * at the next candle close).
     */
    private function openCandleJunctions(): void
    {
        if (!$this->anchoredTradeJunctions) {
            return;
        }
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            foreach (array_keys(self::CANDLE_BAR_MILLISECONDS) as $bar) {
                $this->candleJunctions[$symbol . '/' . $bar] = true;
                $this->requiresOverlap[$symbol . '/ws/candle_' . $bar] = false;
            }
        }
    }

    /** @param array<array-key, mixed> $message */
    private function anchorsCandleJunction(array $message): bool
    {
        $argument = $message['arg'] ?? null;
        if ($this->candleJunctions === []
            || !\is_array($argument)
            || !\is_string($argument['channel'] ?? null)
            || !str_starts_with($argument['channel'], 'candle')
            || !\is_string($argument['instId'] ?? null)
        ) {
            return false;
        }

        return isset($this->candleJunctions[
            $this->instruments->normalizedSymbol($argument['instId'])
            . '/' . substr($argument['channel'], \strlen('candle'))
        ]);
    }

    /**
     * Records from REST, before the first confirmed websocket candle of a symbol
     * and bar whose junction is open, the confirmed candles between the newest
     * recorded candle frontier and that candle (anchored on that frontier).
     *
     * @return array{string, list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>}|null
     */
    private function candleJunctionRestEvents(): ?array
    {
        if ($this->candleJunctions === [] || $this->businessQueue->count() === 0) {
            return null;
        }
        try {
            $message = $this->decoder->decodeBusiness($this->businessQueue->frames()[0]);
            $instrumentId = $message['arg']['instId'] ?? null;
            $channel = $message['arg']['channel'] ?? null;
            $rows = $message['data'] ?? null;
            if (!$this->anchorsCandleJunction($message)
                || !\is_string($instrumentId)
                || !\is_string($channel)
                || !\is_array($rows)
            ) {
                return null;
            }
            $confirmed = [];
            foreach ($rows as $row) {
                $frontier = \is_array($row) ? $this->candleFrontier($instrumentId, $channel, $row) : null;
                if ($frontier instanceof OkxPaperStreamFrontier) {
                    $confirmed[] = self::candleTimestamp($frontier);
                }
            }
        } catch (\Throwable) {
            // The queued frame path reports invalid frames.
            return null;
        }
        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        $bar = substr($channel, \strlen('candle'));
        $newest = $this->newestCandleFrontier($symbol, $bar);
        if (!$newest instanceof OkxPaperStreamFrontier) {
            return null;
        }
        $newestTimestamp = self::candleTimestamp($newest);
        foreach ($confirmed as $timestamp) {
            if (self::compareUnsigned($timestamp, $newestTimestamp) <= 0) {
                continue;
            }
            if ($timestamp === self::nextCandleTimestamp($newestTimestamp, $bar)) {
                return null;
            }
            $junctionRows = $this->candleJunctionRows($instrumentId, $bar, $newest, $timestamp);
            if ($junctionRows === null) {
                return null;
            }
            $restStream = $symbol . '/rest/candle_' . $bar;
            // candleJunctionRows() proved the overlap with the newest frontier.
            $this->requiresOverlap[$restStream] = false;
            $events = $this->acceptedEvents(
                $restStream,
                $junctionRows,
                fn (array $row, OkxPaperMarketEventNormalizer $normalizer): ?PaperMarketEvent => $normalizer
                    ->warmupCandle($instrumentId, $bar, $row),
                fn (array $row): ?OkxPaperStreamFrontier => $this->candleFrontier($instrumentId, $bar, $row),
                false,
                'rest',
            );

            return $events === [] ? null : [$restStream, $events];
        }

        return null;
    }

    /**
     * Confirmed candles strictly between the newest frontier and $before, from
     * the current candles (300 bars) anchored on that frontier; REST can lag
     * behind the websocket, so missing bars are fetched again after a bounded
     * backoff, never recorded as a gap. A junction wider than those 300 bars
     * fails closed (market_data_gap_unresolved): it cannot happen after a
     * recovery, which pages candle history back to the frontier and records up
     * to the newest confirmed candle just before the websocket resumes.
     *
     * @return list<array<array-key, mixed>>|null null once the connection changed
     */
    private function candleJunctionRows(
        string $instrumentId,
        string $bar,
        OkxPaperStreamFrontier $newest,
        string $before,
    ): ?array {
        $newestTimestamp = self::candleTimestamp($newest);
        $expected = intdiv(
            (int) $before - (int) $newestTimestamp,
            self::CANDLE_BAR_MILLISECONDS[$bar],
        ) - 1;
        if ($expected < 1 || $expected > 298) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $generation = $this->connectionGeneration;
        foreach ([0.0, ...self::TRADE_JUNCTION_RETRY_DELAYS_SECONDS] as $delay) {
            if ($delay > 0.0) {
                $this->clock->sleep($delay);
                $this->pumpNetworkLoop();
                if (!$this->junctionConnectionUnchanged($generation)) {
                    return null;
                }
            }
            $rows = $this->restClient->currentCandles($instrumentId, $bar, null, null, 300);
            $this->pumpNetworkLoop();
            if (!$this->junctionConnectionUnchanged($generation)) {
                return null;
            }
            $anchored = false;
            /** @var array<array-key, array<array-key, mixed>> $found */
            $found = [];
            foreach ($rows as $row) {
                $candidate = $this->candleFrontier($instrumentId, $bar, $row);
                if (!$candidate instanceof OkxPaperStreamFrontier) {
                    continue;
                }
                $timestamp = self::candleTimestamp($candidate);
                if (hash_equals($newest->naturalIdentity, $candidate->naturalIdentity)) {
                    if (!hash_equals($newest->overlapDigest, $candidate->overlapDigest)) {
                        $this->failTerminal('market_event_identity_conflict');
                    }
                    $anchored = true;
                } elseif (self::compareUnsigned($timestamp, $newestTimestamp) > 0
                    && self::compareUnsigned($timestamp, $before) < 0
                ) {
                    $found[$timestamp] = $row;
                }
            }
            if ($anchored && \count($found) === $expected) {
                $junctionRows = array_values($found);
                $this->sortCandleRows($junctionRows);

                return $junctionRows;
            }
        }
        $this->failTerminal('market_data_gap_unresolved');
    }

    /**
     * Drops confirmed websocket candles already recorded, then closes the
     * junction on the confirmed candle that directly follows the newest frontier
     * (candleJunctionRestEvents() recorded any candle in between beforehand).
     *
     * @param array<array-key, mixed> $rows
     * @return list<mixed>
     */
    private function rowsAfterCandleJunction(
        string $symbol,
        string $bar,
        string $instrumentId,
        string $channel,
        array $rows,
    ): array {
        $newest = $this->newestCandleFrontier($symbol, $bar);
        if (!$newest instanceof OkxPaperStreamFrontier) {
            unset($this->candleJunctions[$symbol . '/' . $bar]);

            return array_values($rows);
        }
        $newestTimestamp = self::candleTimestamp($newest);
        $kept = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
            }
            $frontier = $this->candleFrontier($instrumentId, $channel, $row);
            if ($frontier instanceof OkxPaperStreamFrontier
                && isset($this->candleJunctions[$symbol . '/' . $bar])
            ) {
                $timestamp = self::candleTimestamp($frontier);
                if (self::compareUnsigned($timestamp, $newestTimestamp) <= 0) {
                    $this->assertDroppedJunctionRowMatches(
                        $symbol . '/ws/candle_' . $bar,
                        $frontier,
                        $newest,
                    );

                    continue;
                }
                if ($timestamp !== self::nextCandleTimestamp($newestTimestamp, $bar)) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                unset($this->candleJunctions[$symbol . '/' . $bar]);
            }
            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * A websocket row dropped at a junction was recorded from REST: it must match
     * that record where it is still known (the newest frontier, or an identity of
     * the acknowledged window); a REST row that differs fails closed.
     */
    private function assertDroppedJunctionRowMatches(
        string $stream,
        OkxPaperStreamFrontier $row,
        OkxPaperStreamFrontier $newest,
    ): void {
        $recorded = hash_equals($newest->naturalIdentity, $row->naturalIdentity)
            ? $newest->overlapDigest
            : ($this->acknowledgedIdentity($stream, $row)['overlap_digest'] ?? null);
        if (\is_string($recorded) && !hash_equals($recorded, $row->overlapDigest)) {
            throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
        }
    }

    private function newestCandleFrontier(string $symbol, string $bar): ?OkxPaperStreamFrontier
    {
        $newest = null;
        foreach (['/rest/candle_', '/ws/candle_'] as $infix) {
            $frontier = $this->checkpoint->streamFrontiers[$symbol . $infix . $bar] ?? null;
            if ($frontier instanceof OkxPaperStreamFrontier
                && (!$newest instanceof OkxPaperStreamFrontier
                    || self::compareUnsigned(
                        self::candleTimestamp($frontier),
                        self::candleTimestamp($newest),
                    ) > 0)
            ) {
                $newest = $frontier;
            }
        }

        return $newest;
    }

    private static function candleTimestamp(OkxPaperStreamFrontier $frontier): string
    {
        $separator = strrpos($frontier->sourceIdentity, '|');
        if ($separator === false) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }

        return substr($frontier->sourceIdentity, $separator + 1);
    }

    private static function nextCandleTimestamp(string $timestamp, string $bar): string
    {
        return (string) ((int) $timestamp + self::CANDLE_BAR_MILLISECONDS[$bar]);
    }

    /**
     * Trade ids a trade row covers: OKX aggregates `count` trades of one taker
     * order under the last trade id.
     *
     * @param array<array-key, mixed> $row
     * @return array{string, string}
     */
    private function tradeRowRange(array $row): array
    {
        $last = $this->tradeFrontier($row)->sourceIdentity;
        $count = $row['count'] ?? '1';
        if (!\is_string($count) || preg_match('/\A[1-9][0-9]{0,8}\z/D', $count) !== 1) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
        }
        $first = BigInteger::of($last)->minus((int) $count - 1);
        if ($first->isLessThan(1)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
        }

        return [(string) $first, $last];
    }

    private static function nextTradeId(string $tradeId): string
    {
        return (string) BigInteger::of($tradeId)->plus(1);
    }

    /** @param list<array<array-key, mixed>> $rows */
    private function newestTradeId(array $rows): string
    {
        $newest = null;
        foreach ($rows as $row) {
            $tradeId = $row['tradeId'] ?? null;
            if (!\is_string($tradeId)) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            if ($newest === null || self::compareUnsigned($tradeId, $newest) > 0) {
                $newest = $tradeId;
            }
        }
        if (!\is_string($newest)) {
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $newest;
    }

    /**
     * Recent-trade and timestamp-paginated OKX responses are time ordered, but when
     * a response is cut by its limit its oldest millisecond can be incomplete: a
     * capture recorded one trade of a millisecond while 13 trades of the same
     * millisecond with higher ids were missing (OKX history-trades returns them).
     * Dropping that millisecond keeps the snapshot gap free; the dropped trades
     * are older than every kept trade and are recovered by trade-id pagination.
     *
     * @param list<array<array-key, mixed>> $rows
     * @return list<array<array-key, mixed>>
     */
    private static function withoutPartialOldestMillisecond(array $rows, int $limit): array
    {
        if (\count($rows) < $limit) {
            return $rows;
        }
        $oldest = null;
        foreach ($rows as $row) {
            $timestamp = $row['ts'] ?? null;
            if (!\is_string($timestamp)) {
                return $rows;
            }
            if ($oldest === null || self::compareUnsigned($timestamp, $oldest) < 0) {
                $oldest = $timestamp;
            }
        }

        $complete = array_values(array_filter(
            $rows,
            static fn (mixed $row): bool => \is_array($row) && ($row['ts'] ?? null) !== $oldest,
        ));

        // A response entirely within one millisecond has nothing to keep; callers
        // then page by trade id, which is exact.
        return $complete === [] ? $rows : $complete;
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function bridgeInitialCandles(): \Generator
    {
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $instrumentId = $this->instruments->nativeInstrumentId($symbol);
            foreach (['1m', '5m', '15m', '1H'] as $bar) {
                $stream = $symbol . '/rest/candle_' . $bar;
                $rows = $this->initialCandleBridgeRows($stream, $instrumentId, $bar);
                if ($rows === null) {
                    return;
                }
                $this->requiresOverlap[$stream] = true;
                yield from $this->yieldWarmupRowEvents(
                    $stream,
                    [],
                    $rows,
                    fn (array $row, OkxPaperMarketEventNormalizer $normalizer): ?PaperMarketEvent => $normalizer
                        ->warmupCandle($instrumentId, $bar, $row),
                    fn (array $row): ?OkxPaperStreamFrontier => $this->candleFrontier(
                        $instrumentId,
                        $bar,
                        $row,
                    ),
                    streamWithoutTransition: true,
                );
                if ($this->checkpoint->phase !== 'streaming') {
                    return;
                }
            }
        }
    }

    /** @return list<array<array-key, mixed>>|null */
    private function initialCandleBridgeRows(
        string $stream,
        string $instrumentId,
        string $bar,
    ): ?array {
        $rows = $this->restClient->currentCandles($instrumentId, $bar, null, null, 300);
        $this->pumpNetworkLoop();
        if ($this->checkpoint->phase !== 'streaming') {
            return null;
        }
        if ($rows === [] || \count($rows) > 300) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $cursor = $this->validatedOldestCandleTimestamp(
            $rows,
            $instrumentId,
            $bar,
            null,
        );
        for ($page = 0; !$this->bridgeRowsContainRequiredFrontier(
            $stream,
            $instrumentId,
            $bar,
            $rows,
        ); ++$page) {
            if ($page >= OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $older = $this->restClient->historyCandles($instrumentId, $bar, $cursor, 300);
            $this->pumpNetworkLoop();
            // @phpstan-ignore notIdentical.alwaysFalse (the network pump can transition the checkpoint)
            if ($this->checkpoint->phase !== 'streaming') {
                return null;
            }
            if ($older === [] || \count($older) > 300) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $cursor = $this->validatedOldestCandleTimestamp(
                $older,
                $instrumentId,
                $bar,
                $cursor,
            );
            $rows = [...$older, ...$rows];
        }
        $this->sortCandleRows($rows);

        return $rows;
    }

    /** @param list<array<array-key, mixed>> $rows */
    private function bridgeRowsContainRequiredFrontier(
        string $stream,
        string $instrumentId,
        string $bar,
        array $rows,
    ): bool {
        $required = $this->checkpoint->streamFrontiers[$stream] ?? null;
        if (!$required instanceof OkxPaperStreamFrontier) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        foreach ($rows as $row) {
            $candidate = $this->candleFrontier($instrumentId, $bar, $row);
            if (!$candidate instanceof OkxPaperStreamFrontier
                || !hash_equals($required->naturalIdentity, $candidate->naturalIdentity)
            ) {
                continue;
            }
            if (!hash_equals($required->canonicalDigest, $candidate->canonicalDigest)) {
                throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
            }

            return true;
        }

        return false;
    }

    /** @return list<array<array-key, mixed>> */
    private function initialCandleRows(string $instrumentId, string $bar): array
    {
        if ($bar !== '1H' || $this->initialHourlyCandleTarget === 1) {
            return $this->restClient->currentCandles(
                $instrumentId,
                $bar,
                null,
                null,
                300,
            );
        }

        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        $observedEnd = $this->checkpoint->initialHourlyWindowEnds[$symbol];
        /** @var array<string, array{canonical: string, row: array<array-key, mixed>}> $byTimestamp */
        $byTimestamp = [];
        if ($observedEnd === null) {
            $rows = $this->restClient->currentCandles(
                $instrumentId,
                $bar,
                null,
                null,
                300,
            );
            $cursor = $this->mergeInitialHourlyPage(
                $instrumentId,
                $rows,
                null,
                $byTimestamp,
            );
            $observedEnd = $this->newestConfirmedInitialHourlyTimestamp($byTimestamp);
            $this->checkpoint = $this->checkpointStore->pinInitialHourlyWindowEnd(
                $this->checkpoint,
                $symbol,
                $observedEnd,
            );
            $captureEnd = $observedEnd;
        } else {
            $currentRows = $this->restClient->currentCandles(
                $instrumentId,
                $bar,
                null,
                null,
                300,
            );
            $this->mergeInitialHourlyPage(
                $instrumentId,
                $currentRows,
                null,
                $byTimestamp,
            );
            $captureEnd = $this->newestConfirmedInitialHourlyTimestamp($byTimestamp);
            if (self::compareUnsigned($captureEnd, $observedEnd) < 0) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
            }
            $cursor = (string) BigInteger::of($observedEnd)->plus(1);
        }
        $alignedWindowEnd = $this->alignedInitialHourlyWindowEnd($observedEnd);
        for ($page = 0; $page < OkxPaperLivePolicy::MAX_INITIAL_HOURLY_HISTORY_PAGES; ++$page) {
            if ($this->confirmedInitialHourlyCountThrough($byTimestamp, $alignedWindowEnd)
                >= $this->initialHourlyCandleTarget
            ) {
                break;
            }
            $older = $this->restClient->historyCandles($instrumentId, $bar, $cursor, 300);
            $cursor = $this->mergeInitialHourlyPage(
                $instrumentId,
                $older,
                $cursor,
                $byTimestamp,
            );
        }
        if ($this->confirmedInitialHourlyCountThrough($byTimestamp, $alignedWindowEnd)
            < $this->initialHourlyCandleTarget
            || !isset(
                $byTimestamp[$observedEnd],
                $byTimestamp[$alignedWindowEnd],
                $byTimestamp[$captureEnd],
            )
            || ($byTimestamp[$observedEnd]['row'][8] ?? null) !== '1'
            || ($byTimestamp[$alignedWindowEnd]['row'][8] ?? null) !== '1'
            || ($byTimestamp[$captureEnd]['row'][8] ?? null) !== '1'
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        $confirmed = array_values(array_filter(
            $byTimestamp,
            static fn (array $entry): bool => ($entry['row'][8] ?? null) === '1'
                && self::compareUnsigned($entry['row'][0] ?? null, $captureEnd) <= 0,
        ));
        usort(
            $confirmed,
            static fn (array $left, array $right): int => self::compareUnsigned(
                $left['row'][0] ?? null,
                $right['row'][0] ?? null,
            ),
        );
        $alignedPosition = null;
        foreach ($confirmed as $position => $entry) {
            if (hash_equals($alignedWindowEnd, $entry['row'][0])) {
                $alignedPosition = $position;
                break;
            }
        }
        if (!\is_int($alignedPosition)
            || $alignedPosition < $this->initialHourlyCandleTarget - 1
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $confirmed = array_slice(
            $confirmed,
            $alignedPosition - $this->initialHourlyCandleTarget + 1,
        );
        $previous = null;
        foreach ($confirmed as $entry) {
            $timestamp = $entry['row'][0];
            if ($previous !== null && (int) $timestamp - (int) $previous !== 3_600_000) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
            }
            $previous = $timestamp;
        }

        return array_column($confirmed, 'row');
    }

    /** @param array<string, array{canonical: string, row: array<array-key, mixed>}> $rows */
    private function newestConfirmedInitialHourlyTimestamp(array $rows): string
    {
        $newest = null;
        foreach ($rows as $entry) {
            if (($entry['row'][8] ?? null) !== '1') {
                continue;
            }
            $timestamp = $entry['row'][0] ?? null;
            $newest = $newest === null || self::compareUnsigned($timestamp, $newest) > 0
                ? $timestamp
                : $newest;
        }
        if ($newest === null) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        return $newest;
    }

    private function alignedInitialHourlyWindowEnd(string $observedEnd): string
    {
        if ($this->initialHourlyCandleTarget !== OkxPaperLivePolicy::INITIAL_HOURLY_CANDLE_TARGET) {
            return $observedEnd;
        }
        $observed = BigInteger::of($observedEnd);
        $baseStart = $observed->minus(
            ($this->initialHourlyCandleTarget - 1) * 3_600_000,
        );
        if ($baseStart->isNegative()) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        return (string) $observed->minus($baseStart->mod(4 * 3_600_000));
    }

    /** @param array<string, array{canonical: string, row: array<array-key, mixed>}> $rows */
    private function confirmedInitialHourlyCountThrough(array $rows, string $windowEnd): int
    {
        return \count(array_filter(
            $rows,
            static fn (array $entry): bool => ($entry['row'][8] ?? null) === '1'
                && self::compareUnsigned($entry['row'][0] ?? null, $windowEnd) <= 0,
        ));
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @param array<string, array{canonical: string, row: array<array-key, mixed>}> $byTimestamp
     */
    private function mergeInitialHourlyPage(
        string $instrumentId,
        array $rows,
        ?string $cursor,
        array &$byTimestamp,
    ): string {
        if ($rows === [] || \count($rows) > 300) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $previous = null;
        $oldestNew = null;
        foreach ($rows as $row) {
            $this->candleFrontier($instrumentId, '1H', $row);
            $timestamp = $row[0];
            if ($previous !== null && self::compareUnsigned($timestamp, $previous) >= 0) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
            }
            $previous = $timestamp;
            $canonical = CanonicalJson::encode($row);
            $known = $byTimestamp[$timestamp] ?? null;
            if ($known !== null) {
                if (!hash_equals($known['canonical'], $canonical)) {
                    throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
                }
                continue;
            }
            if ($cursor !== null && self::compareUnsigned($timestamp, $cursor) >= 0) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
            }
            $byTimestamp[$timestamp] = ['canonical' => $canonical, 'row' => $row];
            $oldestNew = $oldestNew === null
                || self::compareUnsigned($timestamp, $oldestNew) < 0
                ? $timestamp
                : $oldestNew;
        }
        if ($oldestNew === null) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        return $oldestNew;
    }

    /** @return array{kind: string, symbol: string, stream: string, stage: string} */
    private function restTransition(string $symbol, string $stream, string $stage): array
    {
        return [
            'kind' => 'rest_fetch',
            'symbol' => $symbol,
            'stream' => $stream,
            'stage' => $stage,
        ];
    }

    /** @return array{kind: string, symbol: string, stream: string, stage: string}|null */
    private function pendingWarmupContinuation(): ?array
    {
        if ($this->checkpoint->phase !== 'warming'
            || $this->checkpoint->pendingEvent === null
            || $this->checkpoint->pendingFrontier === null
        ) {
            return null;
        }

        $pendingStream = $this->checkpoint->pendingFrontier['stream'];
        foreach ($this->checkpoint->remainingSymbols as $symbol) {
            foreach (['1m', '5m', '15m', '1H'] as $bar) {
                $transition = $this->restTransition(
                    $symbol,
                    $symbol . '/rest/candle_' . $bar,
                    'current_candles',
                );
                if ($transition['stream'] === $pendingStream) {
                    return $transition;
                }
            }
            $tradeTransition = $this->restTransition(
                $symbol,
                $symbol . '/rest/public_trade',
                'recent_trades',
            );
            if ($tradeTransition['stream'] === $pendingStream) {
                return $tradeTransition;
            }
        }

        return null;
    }

    /** @return array{kind: string, symbol: string, stream: string, stage: string}|null */
    private function pendingReconnectContinuation(): ?array
    {
        if ($this->checkpoint->phase !== 'reconnecting'
            || $this->checkpoint->pendingEvent === null
            || $this->checkpoint->pendingFrontier === null
        ) {
            return null;
        }
        $stream = $this->checkpoint->pendingFrontier['stream'];
        $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;
        if (!\is_array($pagination)
            || !\is_array($pagination['retained_rows'] ?? null)
        ) {
            return null;
        }
        $pending = $this->checkpoint->pendingFrontier['frontier'];
        $seenPending = false;
        $isTrade = str_ends_with($stream, '/public_trade');
        foreach ($pagination['retained_rows'] as $row) {
            if (!\is_array($row) && !\is_string($row)) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            }
            $candidate = $isTrade
                ? $this->tradeFrontier(OkxPaperRetainedTradeRow::expand($row))
                : $this->candleFrontier(
                    $this->instruments->nativeInstrumentId($this->checkpoint->pendingEvent->symbol),
                    substr($stream, strrpos($stream, '_') + 1),
                    OkxPaperRetainedCandleRow::expand($row),
                );
            if (!$candidate instanceof OkxPaperStreamFrontier) {
                continue;
            }
            if ($seenPending) {
                return $this->restTransition(
                    $this->checkpoint->pendingEvent->symbol,
                    $stream,
                    $pagination['endpoint'],
                );
            }
            $seenPending = hash_equals($pending->naturalIdentity, $candidate->naturalIdentity)
                && hash_equals($pending->canonicalDigest, $candidate->canonicalDigest);
        }

        return null;
    }

    /** @param array<string, mixed> $transition */
    private function shouldExecuteWarmupTransition(string $stream, array $transition): bool
    {
        return $this->checkpoint->pendingTransition === $transition
            || ($this->checkpoint->streamFrontiers[$stream] ?? null) === null;
    }

    /** @param array<string, mixed> $transition */
    private function ensureTransition(string $phase, array $transition): void
    {
        if ($this->checkpoint->pendingEvent !== null) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        }
        if ($this->checkpoint->pendingTransition === $transition) {
            return;
        }
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->checkpoint,
            $phase,
            $transition,
        );
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @param callable(array<array-key, mixed>, OkxPaperMarketEventNormalizer): ?PaperMarketEvent $normalize
     * @param callable(array<array-key, mixed>): ?OkxPaperStreamFrontier $frontierForRow
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function acceptedEvents(
        string $stream,
        array $rows,
        callable $normalize,
        callable $frontierForRow,
        bool $requireSiblingOverlap = false,
        ?string $candidateSourceKind = null,
    ): array {
        if ($rows === []) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $candidateSourceKind ??= self::identitySourceKind($stream);
        if (!\in_array($candidateSourceKind, ['rest', 'ws'], true)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }
        if ($candidateSourceKind === 'rest' && str_ends_with($stream, '/public_trade')) {
            $this->rememberRestTradeRows(substr($stream, 0, (int) strpos($stream, '/')), $rows);
        }

        $frontier = $this->checkpoint->streamFrontiers[$stream] ?? null;
        /** @var list<array{natural_identity: string, canonical_digest: string, overlap_digest: string, source_kind: string}> $requiredOverlaps */
        $requiredOverlaps = [];
        /** @var array<string, string> $requiredOverlapByIdentity */
        $requiredOverlapByIdentity = [];
        /** @var array<string, true> $requiredIdentities */
        $requiredIdentities = [];
        if ($frontier instanceof OkxPaperStreamFrontier
            && ($this->requiresOverlap[$stream] ?? false)
        ) {
            $requiredFrontiers = $requireSiblingOverlap
                ? $this->requiredRecoveryOverlaps($stream, $frontier)
                : [[
                    'frontier' => $frontier,
                    'source_kind' => self::identitySourceKind($stream),
                ]];
            foreach ($requiredFrontiers as $required) {
                $requiredFrontier = $required['frontier'];
                $existingDigest = $requiredOverlapByIdentity[
                    $requiredFrontier->naturalIdentity
                ] ?? null;
                if (\is_string($existingDigest)
                    && !hash_equals($existingDigest, $requiredFrontier->overlapDigest)
                ) {
                    throw new OkxPaperLiveIntegrityException(
                        'market_event_identity_conflict',
                    );
                }
                $requiredOverlapByIdentity[$requiredFrontier->naturalIdentity] =
                    $requiredFrontier->overlapDigest;
                $requiredIdentities[$requiredFrontier->naturalIdentity] = true;
                $requiredOverlaps[] = [
                    'natural_identity' => $requiredFrontier->naturalIdentity,
                    'canonical_digest' => $requiredFrontier->canonicalDigest,
                    'overlap_digest' => $requiredFrontier->overlapDigest,
                    'source_kind' => $required['source_kind'],
                ];
            }
        }
        $candidates = [];
        $allBatchFrontiersAcknowledged = true;
        /** @var array<string, true> $representedInBatch */
        $representedInBatch = [];
        foreach ($rows as $row) {
            $candidateFrontier = $frontierForRow($row);
            if ($candidateFrontier === null) {
                continue;
            }
            $observedKey = $candidateSourceKind . '/' . $candidateFrontier->sourceIdentity;
            $observed = $this->observedFrontiers[$stream][$observedKey]
                ?? null;
            if ($observed instanceof OkxPaperStreamFrontier
                && !hash_equals($observed->canonicalDigest, $candidateFrontier->canonicalDigest)
            ) {
                throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
            }
            $acknowledged = $this->acknowledgedIdentity(
                $stream,
                $candidateFrontier,
            );
            if ($acknowledged === null) {
                $allBatchFrontiersAcknowledged = false;
            }
            $requiredDurableOverlap = isset(
                $requiredIdentities[$candidateFrontier->naturalIdentity],
            );
            if ($acknowledged !== null) {
                $originCanonicalDigest = $candidateSourceKind === 'rest'
                    ? $acknowledged['rest_canonical_digest']
                    : $acknowledged['ws_canonical_digest'];
                if (!hash_equals(
                    $originCanonicalDigest ?? $acknowledged['overlap_digest'],
                    $originCanonicalDigest !== null
                        ? $candidateFrontier->canonicalDigest
                        : $candidateFrontier->overlapDigest,
                )) {
                    throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
                }
                if ($originCanonicalDigest === null) {
                    $this->checkpoint = $this->checkpointStore
                        ->rememberAcknowledgedIdentityObservation(
                            $this->checkpoint,
                            $stream,
                            $candidateFrontier,
                            $candidateSourceKind,
                        );
                }
                if (!$requiredDurableOverlap) {
                    continue;
                }
            }
            $alreadyRepresented = isset(
                $representedInBatch[$candidateFrontier->naturalIdentity],
            );
            if ($alreadyRepresented
                || ($observed instanceof OkxPaperStreamFrontier
                    && !$requiredDurableOverlap)
            ) {
                continue;
            }
            $this->rememberObservedFrontier(
                $stream,
                $candidateSourceKind,
                $candidateFrontier,
            );
            $representedInBatch[$candidateFrontier->naturalIdentity] = true;
            $candidates[] = [
                'row' => $row,
                'frontier' => $candidateFrontier,
                'previous' => $observed,
            ];
        }

        $start = 0;
        if ($requiredOverlaps !== []) {
            $overlaps = [];
            $missingOverlap = false;
            foreach ($requiredOverlaps as $required) {
                $overlap = null;
                foreach ($candidates as $index => $candidate) {
                    $candidateFrontier = $candidate['frontier'];
                    if (!hash_equals(
                        $required['natural_identity'],
                        $candidateFrontier->naturalIdentity,
                    )) {
                        continue;
                    }
                    $sameOrigin = $required['source_kind'] === $candidateSourceKind;
                    if (!hash_equals(
                        $sameOrigin
                            ? $required['canonical_digest']
                            : $required['overlap_digest'],
                        $sameOrigin
                            ? $candidateFrontier->canonicalDigest
                            : $candidateFrontier->overlapDigest,
                    )) {
                        throw new OkxPaperLiveIntegrityException(
                            'market_event_identity_conflict',
                        );
                    }
                    $overlap = $index;
                    break;
                }
                if (!\is_int($overlap)) {
                    $missingOverlap = true;

                    continue;
                }
                $overlaps[] = $overlap;
            }
            if ($missingOverlap) {
                if ($this->canDrainReconnectHandoffBatch(
                    $stream,
                    $candidateSourceKind,
                    $requiredOverlaps,
                    $allBatchFrontiersAcknowledged,
                )) {
                    return [];
                }

                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            $this->requiresOverlap[$stream] = false;
            $start = max($overlaps) + 1;
        }

        $events = [];
        foreach ($candidates as $index => $candidate) {
            if ($index < $start) {
                continue;
            }
            $row = $candidate['row'];
            $candidateFrontier = $candidate['frontier'];
            if ($frontier instanceof OkxPaperStreamFrontier
                && hash_equals(
                    $frontier->naturalIdentity,
                    $candidateFrontier->naturalIdentity,
                )
            ) {
                if (!hash_equals(
                    $frontier->canonicalDigest,
                    $candidateFrontier->canonicalDigest,
                )) {
                    throw new OkxPaperLiveIntegrityException(
                        'market_event_identity_conflict',
                    );
                }

                continue;
            }
            $accepted = $normalize($row, $this->normalizer);
            if ($accepted !== null) {
                $events[] = [
                    'event' => $accepted,
                    'frontier' => $candidateFrontier,
                    'ordinal_state' => $this->ordinals->snapshot(),
                ];
            }
        }

        return $events;
    }

    /**
     * A reconnect REST recovery can move the durable logical frontier beyond
     * websocket frames admitted before that recovery completed. Drain only a
     * batch whose identities are already durable and digest-compatible. Trade
     * identifiers alone are not an ordering guarantee, so any unacknowledged
     * identity without the exact overlap remains fail-closed.
     *
     * @param list<array{natural_identity: string, canonical_digest: string, overlap_digest: string, source_kind: string}> $requiredOverlaps
     */
    private function canDrainReconnectHandoffBatch(
        string $stream,
        string $candidateSourceKind,
        array $requiredOverlaps,
        bool $allBatchFrontiersAcknowledged,
    ): bool {
        if ($candidateSourceKind !== 'ws'
            || !str_contains($stream, '/ws/')
            || (!str_ends_with($stream, '/public_trade')
                && !str_contains($stream, '/candle_'))
            || ($this->checkpoint->reconnect['attempt'] ?? 0) < 1
            || \count($requiredOverlaps) !== 1
        ) {
            return false;
        }

        return $allBatchFrontiersAcknowledged;
    }

    private function rememberObservedFrontier(
        string $stream,
        string $sourceKind,
        OkxPaperStreamFrontier $frontier,
    ): void {
        $observedKey = $sourceKind . '/' . $frontier->sourceIdentity;
        unset($this->observedFrontiers[$stream][$observedKey]);
        $this->observedFrontiers[$stream][$observedKey] = $frontier;
        $logicalStream = str_replace(['/rest/', '/ws/'], '/', $stream);
        $window = OkxPaperLivePolicy::acknowledgedIdentityHistoryWindow($logicalStream);
        if (\count($this->observedFrontiers[$stream]) <= $window) {
            return;
        }
        $this->observedFrontiers[$stream] = \array_slice(
            $this->observedFrontiers[$stream],
            -$window,
            null,
            true,
        );
    }

    /**
     * Frontiers a REST recovery batch must overlap. A trade recovery only has to
     * reach the newest of its stream and sibling frontiers (OKX trade ids are
     * contiguous and increasing per instrument): the older frontier is already
     * covered by the newer one, and events only start after the newest overlap.
     * Otherwise the REST frontier, which stops moving once the websocket streams,
     * would make every reconnect page back to the last REST recovery. Candles
     * keep both overlaps (one page covers hours of candles).
     *
     * @return list<array{frontier: OkxPaperStreamFrontier, source_kind: string}>
     */
    private function requiredRecoveryOverlaps(
        string $stream,
        OkxPaperStreamFrontier $frontier,
    ): array {
        $own = [
            'frontier' => $frontier,
            'source_kind' => self::identitySourceKind($stream),
        ];
        $sibling = $this->siblingFrontier($stream);
        if (!$sibling instanceof OkxPaperStreamFrontier) {
            return [$own];
        }
        $siblingOverlap = [
            'frontier' => $sibling,
            'source_kind' => self::identitySourceKind(
                str_contains($stream, '/rest/')
                    ? str_replace('/rest/', '/ws/', $stream)
                    : str_replace('/ws/', '/rest/', $stream),
            ),
        ];
        if (str_ends_with($stream, '/public_trade')) {
            $order = self::compareUnsigned($sibling->sourceIdentity, $frontier->sourceIdentity);
            if ($order > 0) {
                return [$siblingOverlap];
            }
            if ($order < 0) {
                return [$own];
            }
        }

        return [$own, $siblingOverlap];
    }

    private function siblingFrontier(string $stream): ?OkxPaperStreamFrontier
    {
        if ((!str_contains($stream, '/candle_') && !str_ends_with($stream, '/public_trade'))
            || (!str_contains($stream, '/rest/') && !str_contains($stream, '/ws/'))
        ) {
            return null;
        }
        $siblingStream = str_contains($stream, '/rest/')
            ? str_replace('/rest/', '/ws/', $stream)
            : str_replace('/ws/', '/rest/', $stream);
        $frontier = $this->checkpoint->streamFrontiers[$siblingStream] ?? null;

        return $frontier instanceof OkxPaperStreamFrontier ? $frontier : null;
    }

    /** @return array{overlap_digest: string, rest_canonical_digest: string|null, ws_canonical_digest: string|null}|null */
    private function acknowledgedIdentity(
        string $stream,
        OkxPaperStreamFrontier $candidate,
    ): ?array {
        if ((!str_contains($stream, '/candle_') && !str_ends_with($stream, '/public_trade'))
            || (!str_contains($stream, '/rest/') && !str_contains($stream, '/ws/'))
        ) {
            return null;
        }
        $logicalStream = str_replace(['/rest/', '/ws/'], '/', $stream);
        $identityHash = hash('sha256', $candidate->naturalIdentity);
        $entry = $this->checkpointStore->acknowledgedIdentityEntry(
            $this->checkpoint,
            $logicalStream,
            $identityHash,
        );
        if ($entry !== null) {
            return [
                'overlap_digest' => $entry[1],
                'rest_canonical_digest' => $entry[2]
                    === OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST
                        ? null
                        : $entry[2],
                'ws_canonical_digest' => $entry[3]
                    === OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST
                        ? null
                        : $entry[3],
            ];
        }

        return null;
    }

    private static function identitySourceKind(string $stream): string
    {
        if (str_contains($stream, '/rest/')) {
            return 'rest';
        }
        if (str_contains($stream, '/ws/')) {
            return 'ws';
        }

        throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
    }

    /**
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function acceptedBookEvents(
        string $stream,
        string $instrumentId,
        OkxMaterializedBookState $state,
        string $origin,
        int $sourceEpoch,
        bool $emitExactReplacement = false,
    ): array {
        $candidateFrontier = $this->bookFrontier($instrumentId, $state);
        $frontier = $this->preparingQueuedFrameBatch
            ? ($this->preparedBookFrontiers[$stream]
                ?? ($this->checkpoint->streamFrontiers[$stream] ?? null))
            : ($this->checkpoint->streamFrontiers[$stream] ?? null);
        if ($frontier instanceof OkxPaperStreamFrontier
            && ($this->requiresOverlap[$stream] ?? false)
        ) {
            if (!hash_equals($frontier->naturalIdentity, $candidateFrontier->naturalIdentity)) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            if (!hash_equals($frontier->canonicalDigest, $candidateFrontier->canonicalDigest)) {
                throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
            }

            $this->requiresOverlap[$stream] = false;

            return [];
        }
        if ($frontier instanceof OkxPaperStreamFrontier
            && hash_equals($frontier->naturalIdentity, $candidateFrontier->naturalIdentity)
        ) {
            if (!hash_equals($frontier->canonicalDigest, $candidateFrontier->canonicalDigest)) {
                throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
            }
            if (!$emitExactReplacement) {
                return [];
            }
        }

        $event = $this->normalizer->materializedTopOfBook(
            $instrumentId,
            $state,
            $sourceEpoch,
            $origin,
        );
        if ($this->preparingQueuedFrameBatch) {
            $this->preparedBookFrontiers[$stream] = $candidateFrontier;
        }

        return [[
            'event' => $event,
            'frontier' => $candidateFrontier,
            'ordinal_state' => $this->ordinals->snapshot(),
        ]];
    }

    /**
     * @param list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }> $events
     * @param array<string, mixed> $transition
     * @return \Generator<int, PaperMarketEvent>
     */
    private function yieldMarketEvents(
        array $events,
        string $stream,
        array $transition,
        bool $continueTransitionAfterBatch = false,
        ?string $eventStreamOverride = null,
    ): \Generator
    {
        if ($events === []) {
            return;
        }
        if ($this->activeQueuedSocket === null
            && \count($events) > self::MAX_DURABLE_EVENT_BATCH
        ) {
            $chunks = array_chunk($events, self::MAX_DURABLE_EVENT_BATCH);
            $lastChunk = \count($chunks) - 1;
            $expectedPhase = $this->checkpoint->phase;
            $expectedConnectionGeneration = $this->connectionGeneration;
            foreach ($chunks as $index => $chunk) {
                yield from $this->yieldMarketEvents(
                    $chunk,
                    $stream,
                    $transition,
                    $index < $lastChunk || $continueTransitionAfterBatch,
                    $eventStreamOverride,
                );
                if ($this->stopped
                    || $this->checkpoint->phase !== $expectedPhase
                    || $this->connectionGeneration !== $expectedConnectionGeneration
                ) {
                    return;
                }
            }

            return;
        }
        if ($this->activeQueuedSocket === null && \count($events) > 1) {
            if ($this->activeQueuedEventsRemaining !== 0) {
                throw new \LogicException('okx_paper_durable_batch_boundary_invalid');
            }
            $this->activeQueuedEventsRemaining = \count($events);
        }
        $last = \count($events) - 1;
        foreach ($events as $index => $accepted) {
            $event = $accepted['event'];
            $frontier = $accepted['frontier'];
            $eventStream = $transition === []
                ? ($eventStreamOverride ?? $this->streamForEvent($event))
                : $stream;
            $pendingFrontier = [
                'stream' => $eventStream,
                'frontier' => $frontier->toArray(),
            ];
            if ($this->durableEventBatchBase instanceof OkxPaperLiveCheckpoint) {
                $this->checkpoint = $this->checkpointStore->preparePending(
                    $this->checkpoint,
                    $event,
                    $accepted['ordinal_state'],
                    $pendingFrontier,
                );
            } else {
                $this->checkpoint = $this->checkpointStore->savePending(
                    $this->checkpoint,
                    $event,
                    $accepted['ordinal_state'],
                    $pendingFrontier,
                );
            }
            $this->continuationTransition = ($index < $last || $continueTransitionAfterBatch)
                && $transition !== []
                ? $transition
                : null;
            yield $this->checkpoint->pendingEvent
                ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            $this->assertPendingWasAcknowledged();
        }
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @param callable(array<array-key, mixed>, OkxPaperMarketEventNormalizer): ?PaperMarketEvent $normalize
     * @param callable(array<array-key, mixed>): ?OkxPaperStreamFrontier $frontierForRow
     * @param array<string, mixed> $transition
     * @return \Generator<int, PaperMarketEvent>
     */
    private function yieldWarmupRowEvents(
        string $stream,
        array $transition,
        array $rows,
        callable $normalize,
        callable $frontierForRow,
        bool $requireInitialEvent = false,
        bool $streamWithoutTransition = false,
    ): \Generator {
        if ($rows === []) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        if ($this->requiresOverlap[$stream] ?? false) {
            $required = $this->checkpoint->streamFrontiers[$stream] ?? null;
            if (!$required instanceof OkxPaperStreamFrontier) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            $overlap = null;
            /** @var array<string, OkxPaperStreamFrontier> $prefixFrontiers */
            $prefixFrontiers = [];
            foreach ($rows as $index => $row) {
                $candidate = $frontierForRow($row);
                if (!$candidate instanceof OkxPaperStreamFrontier
                    || !hash_equals($required->naturalIdentity, $candidate->naturalIdentity)
                ) {
                    if ($candidate instanceof OkxPaperStreamFrontier) {
                        $this->assertWarmupPrefixIdentity(
                            $stream,
                            $candidate,
                            $prefixFrontiers,
                        );
                    }
                    continue;
                }
                if (!hash_equals($required->canonicalDigest, $candidate->canonicalDigest)) {
                    throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
                }
                $overlap = $index;
                break;
            }
            if (!\is_int($overlap)) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            $rows = array_slice($rows, $overlap);
        }

        $acceptedAny = false;
        $pendingEvents = null;
        foreach ($this->warmupRowBatches($rows) as $batch) {
            $events = $this->acceptedEvents(
                $stream,
                $batch,
                $normalize,
                $frontierForRow,
            );
            if ($events === []) {
                continue;
            }
            $acceptedAny = true;
            if (\is_array($pendingEvents)) {
                yield from $this->yieldMarketEvents(
                    $pendingEvents,
                    $stream,
                    $streamWithoutTransition ? [] : $transition,
                    !$streamWithoutTransition,
                    $streamWithoutTransition ? $stream : null,
                );
                if ($streamWithoutTransition) {
                    $this->pumpNetworkLoop();
                    if ($this->checkpoint->phase !== 'streaming') {
                        return;
                    }
                }
            }
            $pendingEvents = $events;
        }
        if (\is_array($pendingEvents)) {
            yield from $this->yieldMarketEvents(
                $pendingEvents,
                $stream,
                $streamWithoutTransition ? [] : $transition,
                eventStreamOverride: $streamWithoutTransition ? $stream : null,
            );
            if ($streamWithoutTransition) {
                $this->pumpNetworkLoop();
            }
        }
        if ($requireInitialEvent
            && !$acceptedAny
            && ($this->checkpoint->streamFrontiers[$stream] ?? null) === null
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
    }

    /**
     * @param array<string, OkxPaperStreamFrontier> $prefixFrontiers
     */
    private function assertWarmupPrefixIdentity(
        string $stream,
        OkxPaperStreamFrontier $candidate,
        array &$prefixFrontiers,
    ): void {
        $sourceKind = self::identitySourceKind($stream);
        $observedKey = $sourceKind . '/' . $candidate->sourceIdentity;
        $observed = $prefixFrontiers[$observedKey] ?? null;
        if ($observed instanceof OkxPaperStreamFrontier
            && !hash_equals($observed->canonicalDigest, $candidate->canonicalDigest)
        ) {
            throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
        }
        $prefixFrontiers[$observedKey] = $candidate;

        $acknowledged = $this->acknowledgedIdentity($stream, $candidate);
        if ($acknowledged === null) {
            return;
        }
        $originCanonicalDigest = $sourceKind === 'rest'
            ? $acknowledged['rest_canonical_digest']
            : $acknowledged['ws_canonical_digest'];
        if (!hash_equals(
            $originCanonicalDigest ?? $acknowledged['overlap_digest'],
            $originCanonicalDigest !== null
                ? $candidate->canonicalDigest
                : $candidate->overlapDigest,
        )) {
            throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
        }
        if ($originCanonicalDigest === null) {
            $this->checkpoint = $this->checkpointStore
                ->rememberAcknowledgedIdentityObservation(
                    $this->checkpoint,
                    $stream,
                    $candidate,
                    $sourceKind,
                );
        }
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @return \Generator<int, list<array<array-key, mixed>>>
     */
    private function warmupRowBatches(array $rows): \Generator
    {
        $count = \count($rows);
        for ($offset = 0; $offset < $count; $offset += self::MAX_WARMUP_EVENT_BATCH) {
            yield array_slice($rows, $offset, self::MAX_WARMUP_EVENT_BATCH);
        }
    }

    private function assertPendingWasAcknowledged(): void
    {
        if ($this->checkpoint->pendingEvent !== null) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        }
    }

    /** @param list<array<array-key, mixed>> $rows */
    private function sortCandleRows(array &$rows): void
    {
        usort($rows, static fn (array $left, array $right): int => self::compareUnsigned(
            $left[0] ?? null,
            $right[0] ?? null,
        ));
    }

    /**
     * @param list<array<array-key, mixed>|string> $rows
     * @return list<list<string>>
     */
    private function expandedRetainedCandleRows(array $rows): array
    {
        try {
            return array_map(
                static fn (array|string $row): array => OkxPaperRetainedCandleRow::expand($row),
                $rows,
            );
        } catch (\InvalidArgumentException) {
            $this->failTerminal('market_data_gap_unresolved');
        }
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @return list<string>
     */
    private function compactRetainedTradeRows(array $rows): array
    {
        try {
            return array_map(
                static fn (array $row): string => OkxPaperRetainedTradeRow::compact($row),
                $rows,
            );
        } catch (\InvalidArgumentException) {
            $this->failTerminal('market_data_gap_unresolved');
        }
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @return list<string>
     */
    private function compactRetainedCandleRows(array $rows): array
    {
        try {
            return array_map(
                static fn (array $row): string => OkxPaperRetainedCandleRow::compact($row),
                $rows,
            );
        } catch (\InvalidArgumentException) {
            $this->failTerminal('market_data_gap_unresolved');
        }
    }

    /** @param array<string, mixed> $state */
    private function recoveryCheckpointWithinBudget(array $state): OkxPaperLiveCheckpoint
    {
        try {
            $checkpoint = OkxPaperLiveCheckpoint::fromArray($state);
            $encoded = CanonicalJson::encode($checkpoint->toArray()) . "\n";
        } catch (\InvalidArgumentException $exception) {
            $this->failTerminal('market_data_gap_unresolved', $exception);
        }
        if (\strlen($encoded) > OkxPaperLivePolicy::MAX_CHECKPOINT_BYTES) {
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $checkpoint;
    }

    /** @param list<array<array-key, mixed>> $rows */
    private function sortTradeRows(array &$rows): void
    {
        usort($rows, self::compareTradeRows(...));
    }

    /**
     * Merge one bounded history page into the compact chronological recovery suffix.
     *
     * @param list<array<array-key, mixed>> $pageRows
     * @param list<array<array-key, mixed>|string> $retainedRows
     * @return list<string>
     */
    private function mergeRetainedTradePage(array $pageRows, array $retainedRows): array
    {
        if (\count($pageRows) + \count($retainedRows)
            > OkxPaperLivePolicy::MAX_RETAINED_RECOVERY_ROWS
        ) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $this->sortTradeRows($pageRows);
        $merged = [];
        $pageIndex = 0;
        $retainedIndex = 0;
        $retained = null;
        $previous = null;
        while ($pageIndex < \count($pageRows) || $retainedIndex < \count($retainedRows)) {
            if ($retained === null && $retainedIndex < \count($retainedRows)) {
                try {
                    $retained = OkxPaperRetainedTradeRow::expand(
                        $retainedRows[$retainedIndex],
                    );
                } catch (\InvalidArgumentException $exception) {
                    $this->failTerminal('market_data_gap_unresolved', $exception);
                }
            }
            $page = $pageRows[$pageIndex] ?? null;
            if (\is_array($page)
                && \is_array($retained)
                && self::compareTradeRows($page, $retained) === 0
            ) {
                // The inclusive first history page overlaps the retained rows:
                // keep one copy of an identical trade, reject a conflicting one.
                try {
                    $pageCompact = OkxPaperRetainedTradeRow::compact($page);
                    $retainedCompact = \is_string($retainedRows[$retainedIndex])
                        ? $retainedRows[$retainedIndex]
                        : OkxPaperRetainedTradeRow::compact($retained);
                } catch (\InvalidArgumentException $exception) {
                    $this->failTerminal('market_data_gap_unresolved', $exception);
                }
                if (!hash_equals($retainedCompact, $pageCompact)) {
                    throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
                }
                ++$pageIndex;

                continue;
            }
            $takePage = \is_array($page)
                && ($retained === null || self::compareTradeRows($page, $retained) <= 0);
            if ($takePage) {
                $selected = $page;
                try {
                    $compact = OkxPaperRetainedTradeRow::compact($selected);
                } catch (\InvalidArgumentException $exception) {
                    $this->failTerminal('market_data_gap_unresolved', $exception);
                }
                ++$pageIndex;
            } else {
                if (!\is_array($retained)) {
                    $this->failTerminal('market_data_gap_unresolved');
                }
                $selected = $retained;
                $compact = $retainedRows[$retainedIndex];
                if (!\is_string($compact)) {
                    try {
                        $compact = OkxPaperRetainedTradeRow::compact($selected);
                    } catch (\InvalidArgumentException $exception) {
                        $this->failTerminal('market_data_gap_unresolved', $exception);
                    }
                }
                ++$retainedIndex;
                $retained = null;
            }
            if (\is_array($previous) && self::compareTradeRows($previous, $selected) > 0) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $merged[] = $compact;
            $previous = $selected;
        }

        return $merged;
    }

    /**
     * Locate every durable overlap without materializing the retained suffix.
     * Only the overlap anchors and one bounded chronological batch after the
     * newest anchor are expanded for normalization. The compact durable suffix
     * remains in the checkpoint, so the next batch resumes from the newly
     * acknowledged frontier after a crash or process restart.
     *
     * @param list<array<array-key, mixed>|string> $rows
     * @return list<array<string, int|string>>|null
     */
    private function boundedRetainedTradeRecoveryRows(string $stream, array $rows): ?array
    {
        $frontier = $this->checkpoint->streamFrontiers[$stream] ?? null;
        if (!$frontier instanceof OkxPaperStreamFrontier) {
            return null;
        }
        $required = $this->requiredRecoveryOverlaps($stream, $frontier);
        /** @var list<array{natural_identity: string, digest: string, uses_canonical: bool}> $expected */
        $expected = [];
        /** @var array<string, string> $requiredOverlapDigests */
        $requiredOverlapDigests = [];
        foreach ($required as $overlap) {
            $requiredFrontier = $overlap['frontier'];
            $existing = $requiredOverlapDigests[$requiredFrontier->naturalIdentity] ?? null;
            if (\is_string($existing)
                && !hash_equals($existing, $requiredFrontier->overlapDigest)
            ) {
                throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
            }
            $requiredOverlapDigests[$requiredFrontier->naturalIdentity] =
                $requiredFrontier->overlapDigest;
            $digest = $overlap['source_kind'] === 'rest'
                ? $requiredFrontier->canonicalDigest
                : $requiredFrontier->overlapDigest;
            $expected[] = [
                'natural_identity' => $requiredFrontier->naturalIdentity,
                'digest' => $digest,
                'uses_canonical' => $overlap['source_kind'] === 'rest',
            ];
        }
        /** @var array<int, array{index: int, row: array<string, int|string>, compact: string, frontier: OkxPaperStreamFrontier}> $found */
        $found = [];
        foreach ($rows as $rowIndex => $row) {
            try {
                $expanded = OkxPaperRetainedTradeRow::expand($row);
                $candidate = $this->tradeFrontier($expanded);
            } catch (\InvalidArgumentException $exception) {
                $this->failTerminal('market_data_gap_unresolved', $exception);
            }
            foreach ($expected as $index => $overlap) {
                if (!hash_equals($overlap['natural_identity'], $candidate->naturalIdentity)) {
                    continue;
                }
                $candidateDigest = $overlap['uses_canonical']
                    ? $candidate->canonicalDigest
                    : $candidate->overlapDigest;
                if (!hash_equals($overlap['digest'], $candidateDigest)) {
                    throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
                }
                $found[$index] ??= [
                    'index' => $rowIndex,
                    'row' => $expanded,
                    'compact' => \is_string($row)
                        ? $row
                        : OkxPaperRetainedTradeRow::compact($expanded),
                    'frontier' => $candidate,
                ];
            }
            if (\count($found) === \count($expected)) {
                // Only first occurrences are used. The retained rows were validated
                // with the checkpoint, and every later row is expanded and checked
                // against acknowledged identities when its batch is emitted, so the
                // anchors kept at the head keep each resumed batch O(batch).
                break;
            }
        }

        if (\count($found) !== \count($expected)) {
            return null;
        }
        uasort(
            $found,
            static fn (array $left, array $right): int => $left['index'] <=> $right['index'],
        );
        $newestOverlapIndex = max(array_column($found, 'index'));
        $selected = [];
        $selectedIdentities = [];
        $retainedAnchors = [];
        foreach ($found as $overlap) {
            $identity = $overlap['frontier']->naturalIdentity;
            if (isset($selectedIdentities[$identity])) {
                continue;
            }
            $selectedIdentities[$identity] = true;
            $selected[] = $overlap['row'];
            $retainedAnchors[] = $overlap['compact'];
        }
        if (\count($retainedAnchors) + \count($rows) - $newestOverlapIndex - 1
            < \count($rows)
        ) {
            $retainedSuffix = [];
            foreach (array_slice($rows, $newestOverlapIndex + 1) as $row) {
                try {
                    $retainedSuffix[] = \is_string($row)
                        ? $row
                        : OkxPaperRetainedTradeRow::compact($row);
                } catch (\InvalidArgumentException $exception) {
                    $this->failTerminal('market_data_gap_unresolved', $exception);
                }
            }
            $state = $this->checkpoint->toArray();
            $state['overlap_pagination_by_stream'][$stream]['retained_rows'] = [
                ...$retainedAnchors,
                ...$retainedSuffix,
            ];
            $transition = $this->checkpoint->pendingTransition;
            if (!\is_array($transition)
                || ($transition['stage'] ?? null) !== 'history_trades'
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->recoveryCheckpointWithinBudget($state),
                'reconnecting',
                $transition,
            );
        }
        foreach (array_slice(
            $rows,
            $newestOverlapIndex + 1,
            self::MAX_DURABLE_EVENT_BATCH,
        ) as $row) {
            try {
                $selected[] = OkxPaperRetainedTradeRow::expand($row);
            } catch (\InvalidArgumentException $exception) {
                $this->failTerminal('market_data_gap_unresolved', $exception);
            }
        }

        return $selected;
    }

    /**
     * @param array<array-key, mixed> $left
     * @param array<array-key, mixed> $right
     */
    /**
     * OKX history-trades with type=2 returns trades strictly older than the cursor
     * timestamp. When the oldest retained trade shares its millisecond with trades
     * that were not retained (a burst), a cursor equal to that timestamp skips them.
     * Starting one millisecond later makes the first page overlap the retained rows;
     * identical rows are merged once by mergeRetainedTradePage().
     */
    private static function inclusiveHistoryTradeCursor(string $oldestTimestamp): string
    {
        return (string) BigInteger::of($oldestTimestamp)->plus(1);
    }

    private static function compareTradeRows(array $left, array $right): int
    {
        $timestamp = self::compareUnsigned($left['ts'] ?? null, $right['ts'] ?? null);

        return $timestamp !== 0
            ? $timestamp
            : self::compareUnsigned($left['tradeId'] ?? null, $right['tradeId'] ?? null);
    }

    private static function compareUnsigned(mixed $left, mixed $right): int
    {
        if (!\is_string($left)
            || !\is_string($right)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $left) !== 1
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $right) !== 1
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $length = \strlen($left) <=> \strlen($right);

        return $length !== 0 ? $length : strcmp($left, $right);
    }

    private function connectAndSubscribe(): void
    {
        $actions = [
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe'],
        ];
        $start = 0;
        if ($this->checkpoint->pendingTransition !== null) {
            $start = array_search($this->checkpoint->pendingTransition, $actions, true);
            if ($start === false && $this->checkpoint->phase !== 'warming') {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            }
            $start = $start === false ? 0 : $start;
        }
        for ($index = 0; $index < \count($actions); ++$index) {
            $action = $actions[$index];
            $durable = $index >= $start;
            if ($action['kind'] === 'transport_connect') {
                if (!$this->connectSocket(
                    $action['stream'],
                    $action['stream'] === 'public'
                        ? $this->config->webSocketUri
                        : $this->config->businessWebSocketUri,
                    $action['stream'] === 'public'
                        ? $this->publicTransport
                        : $this->businessTransport,
                    $action['stream'] === 'public'
                        ? $this->publicQueue
                        : $this->businessQueue,
                    $durable,
                )) {
                    return;
                }

                continue;
            }
            if ($durable) {
                $this->ensureTransition('subscribing', $action);
            }
            $transport = $action['stream'] === 'public'
                ? $this->publicTransport
                : $this->businessTransport;
            $transport->send([
                'op' => 'subscribe',
                'args' => $action['stream'] === 'public'
                    ? $this->subscriptions->publicArguments()
                    : $this->subscriptions->businessArguments(),
            ]);
        }
    }

    private function connectSocket(
        string $socket,
        string $uri,
        OkxPaperPublicWebSocketTransportInterface $transport,
        OkxPaperPublicFrameQueue $queue,
        bool $durable = true,
    ): bool {
        if ($durable) {
            $this->ensureTransition('connecting', [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => $socket,
                'stage' => 'connect',
            ]);
        }
        $opened = false;
        $terminal = null;
        $generation = $this->connectionGeneration;
        $transport->connect(
            $uri,
            function () use (&$opened, $generation, $socket, $queue): void {
                if ($generation !== $this->connectionGeneration) {
                    return;
                }
                $opened = true;
                $this->socketOpen[$socket] = true;
                $this->socketAdmissionsPaused[$socket] = false;
                $this->markReadActive($socket);
                $this->pauseSocketAdmissionsAtHighWatermark($socket, $queue);
                $this->loop->stop();
            },
            function (string $frame) use ($queue, $generation, $socket): void {
                $this->admitSocketFrame($frame, $queue, $generation, $socket);
            },
            function (?int $code = null, ?string $reason = null) use (&$terminal, &$opened, $generation, $socket): void {
                $this->logSocketClosed($socket, $code, $reason, $generation);
                if ($generation !== $this->connectionGeneration) {
                    return;
                }
                $this->socketOpen[$socket] = false;
                if (!$opened) {
                    $terminal = new OkxPaperLiveIntegrityException(
                        'okx_paper_public_reconnect_exhausted',
                        0,
                        new \LogicException(
                            'okx_paper_public_connect_closed_before_open_' . $socket,
                        ),
                    );
                } else {
                    $this->beginPairedReconnect();
                }
                $this->loop->stop();
            },
            function (\Throwable $error) use (&$terminal, &$opened, $generation, $socket): void {
                $this->logSocketError($socket, $error, $generation);
                if ($generation !== $this->connectionGeneration) {
                    return;
                }
                $this->socketOpen[$socket] = false;
                if (!$opened) {
                    $terminal = $error;
                } else {
                    $reason = $this->terminalPublicFailureReason($error);
                    if ($reason !== null) {
                        $this->failTerminal($reason);
                    }
                    $this->beginPairedReconnect();
                }
                $this->loop->stop();
            },
        );
        while (!$opened && $terminal === null) {
            $before = [
                $this->connectionGeneration,
                $this->socketOpen,
                $this->publicQueue->count(),
                $this->publicQueue->bytes(),
                $this->businessQueue->count(),
                $this->businessQueue->bytes(),
            ];
            $this->runNetworkLoop();
            if ($generation !== $this->connectionGeneration) {
                return false;
            }
            $after = [
                $this->connectionGeneration,
                $this->socketOpen,
                $this->publicQueue->count(),
                $this->publicQueue->bytes(),
                $this->businessQueue->count(),
                $this->businessQueue->bytes(),
            ];
            if ($before === $after) {
                break;
            }
        }
        if ($terminal instanceof \Throwable) {
            throw $terminal;
        }
        if (!$opened) {
            throw new OkxPaperLiveIntegrityException(
                'okx_paper_public_reconnect_exhausted',
                0,
                new \LogicException('okx_paper_public_connect_no_progress_' . $socket),
            );
        }

        return true;
    }

    private function admitSocketFrame(
        string $frame,
        OkxPaperPublicFrameQueue $queue,
        int $generation,
        string $socket,
    ): void {
        if ($generation !== $this->connectionGeneration) {
            return;
        }
        if ($this->checkpoint->phase === 'stopping') {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        if ($this->stopped
            || \in_array($this->checkpoint->phase, ['complete', 'failed'], true)
        ) {
            return;
        }
        try {
            $decoded = $socket === 'public'
                ? $this->decoder->decodePublic($frame)
                : $this->decoder->decodeBusiness($frame);
            $this->refreshInboundFreshness($socket, $frame);
            if ($socket === 'public' && $this->consumesBookResubscriptionAcknowledgement($decoded)) {
                $this->loop->stop();

                return;
            }
            if ($this->healthyStopRequested
                && !$this->healthyStopAdmissionQuiesced
            ) {
                if ($this->socketLivenessProofCompleteForStop()) {
                    $this->confirmAndQuiesceHealthyStopAdmission();
                }
                $this->loop->stop();

                return;
            }
        } catch (OkxPaperLiveIntegrityException $exception) {
            // Control frames are decoded on admission: an unusable one is logged here.
            $this->logRejectedFrame($socket, $frame, $exception);
            $reason = $this->terminalPublicFailureReason($exception);
            if ($reason !== null) {
                $this->failTerminal($reason, $exception);
            }

            throw $exception;
        }
        try {
            if ($this->inboundBufferingActive()
                && ($this->inboundBuffers[$socket]->count() !== 0 || $queue->shouldPauseAdmissions())
            ) {
                $this->bufferInboundFrame($socket, $frame);
            } else {
                $queue->enqueue($frame);
                $this->streamingQueuesDirty = true;
                $this->pauseSocketAdmissionsAtHighWatermark($socket, $queue);
                if (!$this->networkTickActive) {
                    $this->persistDirtyStreamingQueues();
                }
            }
        } catch (OkxPaperLiveIntegrityException $exception) {
            if ($exception->getMessage() === 'market_data_backpressure_exhausted') {
                $this->failTerminal('market_data_backpressure_exhausted');
            }
            throw $exception;
        }
        $this->loop->stop();
    }

    private function quiesceHealthyStopAdmission(): void
    {
        if ($this->healthyStopAdmissionQuiesced) {
            return;
        }
        $this->healthyStopAdmissionQuiesced = true;
        ++$this->connectionGeneration;
        $this->cancelHeartbeatTimers();
    }

    private function confirmAndQuiesceHealthyStopAdmission(): void
    {
        if (!$this->checkpoint->healthyStop['liveness_proven']) {
            $this->checkpoint = $this->checkpointStore
                ->confirmHealthyStopLiveness($this->checkpoint);
        }
        $this->quiesceHealthyStopAdmission();
    }

    private function healthyStopPreconditionsHold(): bool
    {
        if (!$this->healthyStopStructuralPreconditionsHold()
            || !$this->socketLivenessProofCompleteForStop()
            || $this->activeQueuedSocket !== null
            || $this->publicQueue->count() !== 0
            || $this->businessQueue->count() !== 0
        ) {
            return false;
        }

        return true;
    }

    private function socketLivenessProofCompleteForStop(): bool
    {
        return $this->pongTimers === []
            && $this->socketFreshnessWithinPolicy();
    }

    private function healthyStopStructuralPreconditionsHold(): bool
    {
        if ($this->checkpoint->phase !== 'streaming'
            || $this->checkpoint->pendingEvent !== null
            || $this->checkpoint->pendingTransition !== null
            || !$this->subscriptions->isReady()
            || !$this->socketOpen['public']
            || !$this->socketOpen['business']
            || $this->checkpoint->reconnect['attempt'] !== 0
            || array_filter(
                $this->checkpoint->resyncBySymbol,
                static fn (mixed $resync): bool => $resync !== null,
            ) !== []
        ) {
            return false;
        }
        return true;
    }

    private function canAwaitSocketFreshness(): bool
    {
        foreach (['public', 'business'] as $socket) {
            if (!isset($this->heartbeatTimers[$socket])
                && !isset($this->pongTimers[$socket])
            ) {
                return false;
            }
        }

        return true;
    }

    private function socketFreshnessWithinPolicy(): bool
    {
        $now = $this->clock->now();
        foreach ($this->lastInboundAt as $lastInbound) {
            if (!$lastInbound instanceof \DateTimeImmutable
                || $now > $lastInbound->modify(sprintf(
                    '+%d seconds',
                    (int) OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS,
                ))
            ) {
                return false;
            }
        }

        return true;
    }

    /** @return array{kind: string, symbol: string, stream: string, stage: string} */
    private function stoppedEventTransition(string $symbol): array
    {
        return [
            'kind' => 'healthy_stop',
            'symbol' => $symbol,
            'stream' => $symbol . '/control/connection_state',
            'stage' => 'emit_stopped',
        ];
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function resumedHealthyStopDrainFlow(): \Generator
    {
        while ($this->publicQueue->count() !== 0
            || $this->businessQueue->count() !== 0
        ) {
            $events = $this->nextQueuedEvents();
            if ($events === []) {
                continue;
            }
            $stream = $this->nextEventStream ?? $this->streamForEvent($events[0]['event']);
            $transition = $this->nextEventTransition;
            $this->nextEventStream = null;
            $this->nextEventTransition = [];
            yield from $this->yieldMarketEvents($events, $stream, $transition);
            $this->completeActiveQueuedFrame();
        }
        $this->persistHealthyStopWhenDrained();
        if ($this->checkpoint->phase !== 'stopping') {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        yield from $this->healthyStopFlow();
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function healthyStopFlow(): \Generator
    {
        while (($symbol = $this->checkpoint->healthyStop['remaining_symbols'][0] ?? null)
            !== null
        ) {
            if (!\is_string($symbol)) {
                $this->failTerminal('okx_paper_public_healthy_stop_invalid');
            }
            if (!$this->resumedHealthyStop && !$this->healthyStopRuntimeRemainsValid()) {
                $this->failTerminal('okx_paper_public_healthy_stop_invalid');
            }
            $transition = $this->stoppedEventTransition($symbol);
            $this->ensureTransition('stopping', $transition);
            $event = $this->normalizer->connectionState(
                $this->instruments->nativeInstrumentId($symbol),
                'stopped',
                $this->checkpoint->connectionEpoch,
            );
            $this->checkpoint = $this->checkpointStore->savePending(
                $this->checkpoint,
                $event,
                $this->ordinals->snapshot(),
                null,
            );
            yield $this->checkpoint->pendingEvent
                ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            $this->assertPendingWasAcknowledged();
        }
        if (!$this->resumedHealthyStop && !$this->healthyStopRuntimeRemainsValid()) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }

        $cleanup = [
            ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'public', 'stage' => 'close'],
            ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'business', 'stage' => 'close'],
            ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'cancel_reconnect_timer'],
            ['kind' => 'timer_cancel', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
            ['kind' => 'timer_cancel', 'symbol' => 'ETHUSDT', 'stream' => 'ETHUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
            ['kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'stop_loop'],
        ];
        $start = 0;
        if ($this->checkpoint->pendingTransition !== null) {
            $position = array_search($this->checkpoint->pendingTransition, $cleanup, true);
            if (\is_int($position)) {
                $start = $position;
            } elseif (($this->checkpoint->pendingTransition['stage'] ?? null) !== 'finalize') {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            } else {
                $start = \count($cleanup);
            }
        }
        for ($index = $start; $index < \count($cleanup); ++$index) {
            $transition = $cleanup[$index];
            $this->ensureTransition('stopping', $transition);
            $this->executeCleanupTransition($transition);
        }
        $finalize = [
            'kind' => 'healthy_stop',
            'symbol' => null,
            'stream' => null,
            'stage' => 'finalize',
        ];
        $this->ensureTransition('stopping', $finalize);
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->checkpoint,
            'complete',
            null,
        );
        $this->stopped = true;
    }

    private function healthyStopRuntimeRemainsValid(): bool
    {
        return $this->checkpoint->phase === 'stopping'
            && $this->checkpoint->healthyStop['requested']
            && $this->checkpoint->reconnect['attempt'] === 0
            && $this->subscriptions->isReady()
            && $this->socketOpen['public']
            && $this->socketOpen['business']
            && $this->socketFreshnessWithinPolicy()
            && $this->publicQueue->count() === 0
            && $this->businessQueue->count() === 0
            && array_filter(
                $this->checkpoint->resyncBySymbol,
                static fn (mixed $resync): bool => $resync !== null,
            ) === [];
    }

    /** @param array<string, mixed> $context */
    private function warn(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context + [
                'phase' => $this->checkpoint->phase,
                'connection_epoch' => $this->checkpoint->connectionEpoch,
                'source_epochs' => $this->checkpoint->sourceEpochs,
                'reconnect_attempt' => $this->checkpoint->reconnect['attempt'],
                'connection_generation' => $this->connectionGeneration,
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    private function logSourceFailure(\Throwable $exception): void
    {
        try {
            $this->warn('okx_paper_public_source_failed', [
                'public_reason' => $this->checkpoint->failureReason
                    ?? $this->terminalPublicFailureReason($exception)
                    ?? OkxPaperLiveDiagnostics::text($exception->getMessage()),
                ...OkxPaperLiveDiagnostics::exception($exception),
                'rejected_frame' => $this->lastRejectedFrame,
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    /**
     * A heartbeat decision on a missing pong (see livenessDecision()): a reconnect,
     * or a deferral while frames prove the connection alive, logged when first
     * deferred and then once a minute.
     */
    private function logLivenessReconnect(string $socket, string $trigger, string $decision): void
    {
        try {
            $now = (float) $this->clock->now()->format('U.u');
            $probe = $this->pingProbes[$socket] ?? null;
            if (str_starts_with($decision, 'deferred_') && $probe !== null) {
                if ($probe['logged_at'] !== null && $now - $probe['logged_at'] < 60.0) {
                    return;
                }
                $this->pingProbes[$socket]['logged_at'] = $now;
            }
            $age = static fn (?\DateTimeImmutable $at): ?float => $at === null
                ? null
                : round($now - (float) $at->format('U.u'), 3);
            $this->warn('okx_paper_public_liveness_reconnect', [
                'socket' => $socket,
                'trigger' => $trigger,
                'decision' => $decision,
                'backlog' => $probe['backlog'] ?? null,
                'last_frame_age_s' => $age($this->lastInboundAt[$socket] ?? null),
                'last_pong_age_s' => $age($this->lastPongAt[$socket] ?? null),
                'ping_age_s' => $probe === null ? null : round($now - $probe['sent_at'], 3),
                'frames_since_ping' => $probe['frames'] ?? null,
                'loop_stall_s' => $probe['heartbeat_late_s'] ?? null,
                'public_queue_frames' => $this->publicQueue->count(),
                'business_queue_frames' => $this->businessQueue->count(),
                // Whether the silence could be ours: our pause, our reading time,
                // how often the event loop polled the sockets since the ping.
                'paused_by_us' => $this->socketAdmissionsPaused[$socket] ?? null,
                'read_active_s' => ($this->readActiveSince[$socket] ?? null) === null
                    ? null
                    : round($now - (float) $this->readActiveSince[$socket], 3),
                'polls_since_ping' => $probe['polls'] ?? null,
                'max_poll_gap_s' => $probe['max_poll_gap_s'] ?? null,
                'inbound_buffered_frames' => $this->inboundBufferedFrames(),
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    /**
     * Every terminal failure, with the check that raised it: failures raised outside
     * events() (by the stop controller's timer) never reach logSourceFailure().
     */
    private function logTerminalFailure(string $reason, ?\Throwable $previous): void
    {
        try {
            $trace = debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $this->warn('okx_paper_public_terminal_failure', [
                'public_reason' => $reason,
                'failed_in' => ($trace[2]['function'] ?? '?')
                    . '@' . basename($trace[1]['file'] ?? '?') . ':' . ($trace[1]['line'] ?? '?'),
                'previous' => $previous === null ? null : OkxPaperLiveDiagnostics::exception($previous),
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    private function logRejectedFrame(string $socket, string $frame, \Throwable $exception): void
    {
        try {
            $this->lastRejectedFrame = [
                'socket' => $socket,
                'frame' => OkxPaperLiveDiagnostics::frame($frame),
                'reason' => OkxPaperLiveDiagnostics::text($exception->getMessage()) ?? '',
            ];
            $this->warn('okx_paper_public_frame_rejected', [
                'socket' => $socket,
                'frame_bytes' => \strlen($frame),
                'frame_excerpt' => $this->lastRejectedFrame['frame'],
                ...OkxPaperLiveDiagnostics::exception($exception),
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    private function logSocketClosed(string $socket, ?int $code, ?string $reason, int $generation): void
    {
        $this->warn('okx_paper_public_ws_closed', [
            'socket' => $socket,
            'ws_close_code' => $code,
            'ws_close_reason' => OkxPaperLiveDiagnostics::text($reason),
            'current_connection' => $generation === $this->connectionGeneration && !$this->stopped,
        ]);
    }

    private function logSocketError(string $socket, \Throwable $error, int $generation): void
    {
        try {
            $this->warn('okx_paper_public_ws_error', [
                'socket' => $socket,
                ...OkxPaperLiveDiagnostics::exception($error),
                'current_connection' => $generation === $this->connectionGeneration && !$this->stopped,
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    private function beginPairedReconnect(): void
    {
        if ($this->checkpoint->phase === 'stopping'
            || $this->healthyStopRequested
            || $this->checkpoint->healthyStop['requested']
        ) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        if ($this->stopped
            || \in_array($this->checkpoint->phase, [
                'reconnecting',
                'complete',
                'failed',
            ], true)
        ) {
            return;
        }
        if ($this->checkpoint->reconnect['attempt']
            >= \count(OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS)
        ) {
            $this->failTerminal('okx_paper_public_reconnect_exhausted');
        }
        $this->serviceNoticeReconnectRequested = false;
        $this->persistDirtyStreamingQueues(true);
        $this->discardQueuedBooksFromPreviousConnection();
        $this->subscriptions->reset();
        $this->publicAcknowledgements = [];
        $this->businessAcknowledgements = [];
        $this->cancelHeartbeatTimers();

        $publicClose = [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ];
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->checkpoint,
            'reconnecting',
            $publicClose,
        );
        $this->publicTransport->close();
        $businessClose = [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'close',
        ];
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->checkpoint,
            'reconnecting',
            $businessClose,
        );
        $this->businessTransport->close();

        $attempt = $this->checkpoint->reconnect['attempt'] + 1;
        $delay = OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS[$attempt - 1];
        $deadline = $this->clock->now()->modify(sprintf('+%d seconds', (int) $delay))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
        $state = $this->checkpoint->toArray();
        ++$state['connection_epoch'];
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => $attempt,
            'deadline_at' => $deadline,
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $timerTransition = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $candidate,
            'reconnecting',
            $timerTransition,
        );
        $this->connectionGeneration = $this->checkpoint->connectionEpoch;
        $this->scheduleReconnectTimer($delay);
    }

    private function scheduleReconnectTimer(float $delay): void
    {
        $generation = $this->connectionGeneration;
        $this->reconnectTimer = $this->loop->addTimer(
            $delay,
            function () use ($generation): void {
                if ($generation !== $this->connectionGeneration
                    || $this->stopped
                    || $this->checkpoint->phase !== 'reconnecting'
                ) {
                    return;
                }
                $this->executeReconnectTimer($generation);
            },
        );
    }

    private function executeReconnectTimer(int $generation): void
    {
        $cancel = [
            'kind' => 'timer_cancel',
            'symbol' => null,
            'stream' => null,
            'stage' => 'cancel_reconnect_timer',
        ];
        $this->ensureTransition('reconnecting', $cancel);
        if ($this->reconnectTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->reconnectTimer);
            $this->reconnectTimer = null;
        }
        if ($generation !== $this->connectionGeneration) {
            return;
        }

        try {
            $this->startAsynchronousReconnectPair();
        } catch (\Throwable) {
            $this->scheduleNextReconnectAttempt();
        }
    }

    private function startAsynchronousReconnectPair(): void
    {
        $publicConnect = [
            'kind' => 'transport_connect',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'connect',
        ];
        $this->ensureTransition('reconnecting', $publicConnect);
        $this->connectSocketAsynchronously(
            'public',
            $this->config->webSocketUri,
            $this->publicTransport,
            $this->publicQueue,
            function (): void {
                $businessConnect = [
                    'kind' => 'transport_connect',
                    'symbol' => null,
                    'stream' => 'business',
                    'stage' => 'connect',
                ];
                $this->ensureTransition('reconnecting', $businessConnect);
                $this->connectSocketAsynchronously(
                    'business',
                    $this->config->businessWebSocketUri,
                    $this->businessTransport,
                    $this->businessQueue,
                    function (): void {
                        $publicSubscribe = [
                            'kind' => 'subscription_send',
                            'symbol' => null,
                            'stream' => 'public',
                            'stage' => 'subscribe',
                        ];
                        $this->ensureTransition('reconnecting', $publicSubscribe);
                        $this->publicTransport->send([
                            'op' => 'subscribe',
                            'args' => $this->subscriptions->publicArguments(),
                        ]);
                        $businessSubscribe = [
                            'kind' => 'subscription_send',
                            'symbol' => null,
                            'stream' => 'business',
                            'stage' => 'subscribe',
                        ];
                        $this->ensureTransition('reconnecting', $businessSubscribe);
                        $this->businessTransport->send([
                            'op' => 'subscribe',
                            'args' => $this->subscriptions->businessArguments(),
                        ]);
                    },
                );
            },
        );
    }

    private function connectSocketAsynchronously(
        string $socket,
        string $uri,
        OkxPaperPublicWebSocketTransportInterface $transport,
        OkxPaperPublicFrameQueue $queue,
        \Closure $afterOpen,
    ): void {
        $generation = $this->connectionGeneration;
        $opened = false;
        $transport->connect(
            $uri,
            function () use (&$opened, $generation, $socket, $queue, $afterOpen): void {
                if ($generation !== $this->connectionGeneration || $this->stopped) {
                    return;
                }
                $opened = true;
                $this->socketOpen[$socket] = true;
                $this->socketAdmissionsPaused[$socket] = false;
                $this->markReadActive($socket);
                $this->pauseSocketAdmissionsAtHighWatermark($socket, $queue);
                try {
                    $afterOpen();
                } catch (\Throwable $exception) {
                    $reason = $this->terminalPublicFailureReason($exception);
                    if ($reason !== null) {
                        $this->failTerminal($reason);
                    }
                    $this->scheduleNextReconnectAttempt();
                }
            },
            function (string $frame) use ($queue, $generation, $socket): void {
                $this->admitSocketFrame($frame, $queue, $generation, $socket);
            },
            function (?int $code = null, ?string $reason = null) use (&$opened, $generation, $socket): void {
                $this->logSocketClosed($socket, $code, $reason, $generation);
                if ($generation !== $this->connectionGeneration || $this->stopped) {
                    return;
                }
                $this->socketOpen[$socket] = false;
                if ($opened) {
                    $this->scheduleNextReconnectAttempt();
                } else {
                    $this->scheduleNextReconnectAttempt();
                }
            },
            function (\Throwable $error) use (&$opened, $generation, $socket): void {
                $this->logSocketError($socket, $error, $generation);
                if ($generation !== $this->connectionGeneration || $this->stopped) {
                    return;
                }
                $this->socketOpen[$socket] = false;
                $reason = $this->terminalPublicFailureReason($error);
                if ($reason !== null) {
                    $this->failTerminal($reason);
                }
                if ($opened) {
                    $this->scheduleNextReconnectAttempt();
                } else {
                    $this->scheduleNextReconnectAttempt();
                }
            },
        );
    }

    private function resumeReconnectTransportTransition(): void
    {
        $transition = $this->checkpoint->pendingTransition;
        // A restarted process always rebuilds a fresh physical socket pair.
        // Any restored book frame therefore belongs to the dead connection
        // generation and cannot authorize the new REST snapshot.
        $this->discardQueuedBooksFromPreviousConnection();
        if (($transition['stage'] ?? null) === 'reconnect_delay') {
            return;
        }
        if (($transition['kind'] ?? null) === 'transport_close') {
            if (($transition['stream'] ?? null) === 'public') {
                $this->socketOpen['public'] = false;
                $this->publicTransport->close();
                $businessClose = [
                    'kind' => 'transport_close',
                    'symbol' => null,
                    'stream' => 'business',
                    'stage' => 'close',
                ];
                $this->ensureTransition('reconnecting', $businessClose);
                $this->socketOpen['business'] = false;
                $this->businessTransport->close();
            } elseif (($transition['stream'] ?? null) === 'business') {
                $this->socketOpen['business'] = false;
                $this->businessTransport->close();
            } else {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            }
            $this->scheduleReconnectAfterClosedPair();

            return;
        }
        $actions = [
            [
                'kind' => 'timer_cancel',
                'symbol' => null,
                'stream' => null,
                'stage' => 'cancel_reconnect_timer',
            ],
            [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'connect',
            ],
            [
                'kind' => 'transport_connect',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'connect',
            ],
            [
                'kind' => 'subscription_send',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'subscribe',
            ],
            [
                'kind' => 'subscription_send',
                'symbol' => null,
                'stream' => 'business',
                'stage' => 'subscribe',
            ],
        ];
        $start = array_search($transition, $actions, true);
        if (!\is_int($start)) {
            if (($transition['kind'] ?? null) === 'rest_fetch'
                || ($transition['kind'] ?? null) === 'emit_connection_state'
                || ($transition['kind'] ?? null) === 'emit_boundary'
            ) {
                $this->ensureTransition('reconnecting', $actions[1]);
                $this->resumeReconnectTransportTransition();
            }

            return;
        }

        if ($start === 0 && $this->reconnectTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->reconnectTimer);
            $this->reconnectTimer = null;
        }

        // A new process has no socket state. Journal each prerequisite again
        // before rebuilding the pair, even when the durable head was a later
        // connect/send/recovery action at the crash point.
        $this->ensureTransition('reconnecting', $actions[1]);
        $this->startAsynchronousReconnectPair();
    }

    private function scheduleReconnectAfterClosedPair(): void
    {
        $attempt = $this->checkpoint->reconnect['attempt'];
        if ($attempt >= \count(OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS)) {
            $this->failTerminal('okx_paper_public_reconnect_exhausted');
        }
        $nextAttempt = $attempt + 1;
        $delay = OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS[$nextAttempt - 1];
        $state = $this->checkpoint->toArray();
        ++$state['connection_epoch'];
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => $nextAttempt,
            'deadline_at' => $this->clock->now()->modify(sprintf(
                '+%d seconds',
                (int) $delay,
            ))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $timer = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $this->checkpoint = $this->checkpointStore->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($state),
            'reconnecting',
            $timer,
        );
        $this->connectionGeneration = $this->checkpoint->connectionEpoch;
        $this->scheduleReconnectTimer($delay);
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function reconnectRecoveryFlow(): \Generator
    {
        while ($this->checkpoint->phase === 'reconnecting') {
            $transition = $this->checkpoint->pendingTransition;
            if ($transition === null) {
                $this->checkpoint = $this->checkpointStore->saveTransition(
                    $this->checkpoint,
                    'streaming',
                    null,
                );
                $this->openTradeJunctions();
                $this->openCandleJunctions();
                $this->startHeartbeatTimers();
                $this->scheduleStabilityResetTimer();

                return;
            }
            if (($transition['kind'] ?? null) === 'subscription_send'
                && ($transition['stream'] ?? null) === 'business'
            ) {
                $transition = $this->persistedReconnectRecoveryTransition();
                $transitionSymbol = $transition['symbol'];
                if ($transition['kind'] === 'rest_fetch'
                    && !\is_array(
                        $this->checkpoint->resyncBySymbol[$transitionSymbol] ?? null,
                    )
                ) {
                    $this->beginReconnectRecovery($transition);
                } else {
                    $this->ensureTransition('reconnecting', $transition);
                }
            }
            if (($transition['kind'] ?? null) === 'emit_connection_state') {
                yield from $this->emitReconnectingState($transition);

                continue;
            }
            if (($transition['kind'] ?? null) === 'emit_boundary') {
                yield from $this->emitReconnectBoundary($transition);

                continue;
            }
            if (($transition['kind'] ?? null) !== 'rest_fetch'
                || !\is_string($transition['symbol'] ?? null)
                || !\is_string($transition['stream'] ?? null)
            ) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            }

            $symbol = $transition['symbol'];
            $stream = $transition['stream'];
            if (!\is_array($this->checkpoint->resyncBySymbol[$symbol] ?? null)) {
                if (!$this->reconnectingStateWasEmitted($symbol)) {
                    $connectionTransition = [
                        'kind' => 'emit_connection_state',
                        'symbol' => $symbol,
                        'stream' => $symbol . '/control/connection_state',
                        'stage' => 'reconnecting',
                    ];
                    $this->ensureTransition('reconnecting', $connectionTransition);

                    continue;
                }
                $this->beginReconnectRecovery($transition);
            }
            if (($transition['stage'] ?? null) === 'order_book') {
                $recoveryGeneration = $this->connectionGeneration;
                try {
                    $events = $this->reconnectBookEvents($symbol, $transition);
                } catch (\Throwable $exception) {
                    if ($this->stopped) {
                        throw $exception;
                    }
                    if ($this->isIdentityConflict($exception)) {
                        $this->failTerminal('market_event_identity_conflict', $exception);
                    }
                    // Keep the cause (REST failure, missing book authority...).
                    $this->failTerminal('market_data_gap_unresolved', $exception);
                }
                if ($this->stopped || $this->connectionGeneration !== $recoveryGeneration) {
                    return;
                }
                yield from $this->yieldMarketEvents($events, $stream, $transition);

                continue;
            }

            $recoveryGeneration = $this->connectionGeneration;
            try {
                $events = $this->reconnectFrontierEvents($symbol, $stream, $transition);
            } catch (\Throwable $exception) {
                if ($this->isIdentityConflict($exception)) {
                    $this->failTerminal('market_event_identity_conflict', $exception);
                }
                if ($exception->getMessage() !== 'market_data_gap_unresolved' || $this->stopped) {
                    throw $exception;
                }
                $this->failTerminal('market_data_gap_unresolved');
            }
            if ($events === []) {
                $this->completeReconnectFrontierOverlap($symbol, $stream);

                continue;
            }
            $transition = $this->checkpoint->pendingTransition ?? $transition;
            $continuesBoundedTradeHistory = ($transition['stage'] ?? null)
                === 'history_trades'
                && \is_array(
                    $this->checkpoint->overlapPaginationByStream[$stream] ?? null,
                );
            yield from $this->yieldMarketEvents(
                $events,
                $stream,
                $transition,
                $continuesBoundedTradeHistory,
            );
            if ($this->stopped || $this->connectionGeneration !== $recoveryGeneration) {
                return;
            }
            if (str_contains($stream, '/ws/')) {
                $this->requiresOverlap[$stream] = true;
            }
        }
    }

    /** @return array{kind: string, symbol: string, stream: string, stage: string} */
    private function persistedReconnectRecoveryTransition(): array
    {
        $symbol = $this->checkpoint->remainingSymbols[0] ?? null;
        if (!\is_string($symbol)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }
        $paginations = [];
        foreach ($this->checkpoint->overlapPaginationByStream as $stream => $pagination) {
            if (\is_array($pagination) && str_starts_with($stream, $symbol . '/')) {
                $paginations[$stream] = $pagination;
            }
        }
        if ($paginations !== []) {
            ksort($paginations, \SORT_STRING);
            $stream = array_key_first($paginations);
            $pagination = $paginations[$stream];

            return $this->restTransition(
                $symbol,
                $stream,
                $pagination['endpoint'],
            );
        }
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)) {
            return $this->firstReconnectRecoveryTransition();
        }
        if ($resync['policy'] === 'book_seq_overlap_v1') {
            $bookFrontier = $this->checkpoint->streamFrontiers[
                $symbol . '/rest/top_of_book'
            ] ?? null;
            $boundary = $this->checkpoint->remainingBoundaries[0] ?? null;
            if ($bookFrontier instanceof OkxPaperStreamFrontier
                && \is_array($resync['book_snapshot'] ?? null)
                && \is_array($boundary)
                && $boundary['symbol'] === $symbol
                && hash_equals(
                    (string) ($resync['book_snapshot']['seqId'] ?? ''),
                    $bookFrontier->sourceIdentity,
                )
            ) {
                if ($boundary['reason'] === 'reconnect') {
                    foreach ($this->reconnectRecoveryTransitions($symbol) as $transition) {
                        if ($transition['stage'] !== 'order_book') {
                            return $transition;
                        }
                    }
                }

                return [
                    'kind' => 'emit_boundary',
                    'symbol' => $symbol,
                    'stream' => $symbol . '/control/snapshot_boundary',
                    'stage' => $boundary['reason'],
                ];
            }

            return $this->restTransition(
                $symbol,
                $symbol . '/rest/top_of_book',
                'order_book',
            );
        }
        foreach ($this->reconnectRecoveryTransitions($symbol) as $transition) {
            $frontier = $this->checkpoint->streamFrontiers[$transition['stream']] ?? null;
            if ($frontier instanceof OkxPaperStreamFrontier
                && hash_equals($resync['frontier']->naturalIdentity, $frontier->naturalIdentity)
                && hash_equals($resync['frontier']->canonicalDigest, $frontier->canonicalDigest)
            ) {
                return $transition;
            }
        }

        throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
    }

    /** @return array{kind: string, symbol: string, stream: string, stage: string} */
    private function firstReconnectRecoveryTransition(): array
    {
        $symbol = $this->checkpoint->remainingSymbols[0] ?? null;
        if (!\is_string($symbol)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }
        $connectionFrontier = $this->checkpoint->streamFrontiers[
            $symbol . '/control/connection_state'
        ] ?? null;
        if (!$connectionFrontier instanceof OkxPaperStreamFrontier
            || !hash_equals(
                $this->checkpoint->connectionEpoch . '|reconnecting',
                $connectionFrontier->sourceIdentity,
            )
        ) {
            return [
                'kind' => 'emit_connection_state',
                'symbol' => $symbol,
                'stream' => $symbol . '/control/connection_state',
                'stage' => 'reconnecting',
            ];
        }
        $transitions = $this->reconnectRecoveryTransitions($symbol);

        return $transitions[0]
            ?? throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
    }

    private function reconnectingStateWasEmitted(string $symbol): bool
    {
        $frontier = $this->checkpoint->streamFrontiers[
            $symbol . '/control/connection_state'
        ] ?? null;

        return $frontier instanceof OkxPaperStreamFrontier
            && hash_equals(
                $this->checkpoint->connectionEpoch . '|reconnecting',
                $frontier->sourceIdentity,
            );
    }

    /** @param array<string, mixed> $transition */
    private function emitReconnectingState(array $transition): \Generator
    {
        $symbol = $transition['symbol'] ?? null;
        if (!\is_string($symbol)
            || ($transition['stage'] ?? null) !== 'reconnecting'
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }
        $event = $this->normalizer->connectionState(
            $this->instruments->nativeInstrumentId($symbol),
            'reconnecting',
            $this->checkpoint->connectionEpoch,
        );
        $this->checkpoint = $this->checkpointStore->savePending(
            $this->checkpoint,
            $event,
            $this->ordinals->snapshot(),
            null,
        );
        yield $this->checkpoint->pendingEvent
            ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        $this->assertPendingWasAcknowledged();
    }

    /**
     * @return list<array{kind: string, symbol: string, stream: string, stage: string}>
     */
    private function reconnectRecoveryTransitions(string $symbol): array
    {
        $transitions = [];
        if (($this->checkpoint->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null)
            instanceof OkxPaperStreamFrontier
            || ($this->checkpoint->streamFrontiers[$symbol . '/rest/top_of_book'] ?? null)
                instanceof OkxPaperStreamFrontier
        ) {
            $transitions[] = $this->restTransition(
                $symbol,
                $symbol . '/rest/top_of_book',
                'order_book',
            );
        }
        foreach ($this->checkpoint->streamFrontiers as $stream => $frontier) {
            if (!$frontier instanceof OkxPaperStreamFrontier
                || !str_starts_with($stream, $symbol . '/')
            ) {
                continue;
            }
            $stage = match (true) {
                preg_match('/\/(?:rest|ws)\/candle_(?:1m|5m|15m|1H)\z/D', $stream) === 1
                    => 'current_candles',
                preg_match('/\/(?:rest|ws)\/public_trade\z/D', $stream) === 1
                    => 'recent_trades',
                default => null,
            };
            if ($stage !== null) {
                $transitions[] = $this->restTransition($symbol, $stream, $stage);
            }
        }

        return $transitions;
    }

    /** @param array<string, mixed> $transition */
    private function beginReconnectRecovery(array $transition): void
    {
        $symbol = $transition['symbol'] ?? null;
        $stream = $transition['stream'] ?? null;
        if (!\is_string($symbol) || !\is_string($stream)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }
        $frontier = ($transition['stage'] ?? null) === 'order_book'
            ? ($this->checkpoint->streamFrontiers[$symbol . '/ws/top_of_book']
                ?? $this->checkpoint->streamFrontiers[$symbol . '/rest/top_of_book']
                ?? null)
            : ($this->checkpoint->streamFrontiers[$stream] ?? null);
        if (!$frontier instanceof OkxPaperStreamFrontier) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $state = $this->checkpoint->toArray();
        if (($transition['stage'] ?? null) === 'order_book') {
            ++$state['source_epochs'][$symbol];
        }
        $state['resync_by_symbol'][$symbol] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => ($transition['stage'] ?? null) === 'order_book'
                ? $frontier->sourceIdentity
                : null,
            'deadline_at' => $this->clock->now()->modify(sprintf(
                '+%d seconds',
                (int) OkxPaperLivePolicy::RESYNC_ATTEMPT_TIMEOUT_SECONDS,
            ))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'policy' => ($transition['stage'] ?? null) === 'order_book'
                ? 'book_seq_overlap_v1'
                : 'frontier_overlap_v1',
        ];
        if (($transition['stage'] ?? null) === 'order_book') {
            $state['resync_by_symbol'][$symbol]['book_snapshot'] = null;
            if ($this->checkpoint->streamingQueueRef === null) {
                $state['resync_by_symbol'][$symbol]['queued_public_frames'] =
                    $this->publicQueue->frames();
                $state['resync_by_symbol'][$symbol]['queued_business_frames'] =
                    $this->businessQueue->frames();
            }
        }
        $this->checkpoint = $this->checkpointStore->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($state),
            'reconnecting',
            $transition,
        );
    }

    /**
     * @param array<string, mixed> $transition
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function reconnectFrontierEvents(
        string $symbol,
        string $stream,
        array $transition,
    ): array {
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)
            || $this->clock->now() >= new \DateTimeImmutable($resync['deadline_at'])
        ) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $instrumentId = $this->instruments->nativeInstrumentId($symbol);
        $this->requiresOverlap[$stream] = true;
        if (($transition['stage'] ?? null) === 'history_candles') {
            if (preg_match('/candle_(1m|5m|15m|1H)\z/D', $stream, $matches) !== 1) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;
            if (!\is_array($pagination)
                || !\is_array($pagination['retained_rows'] ?? null)
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $rows = $this->expandedRetainedCandleRows(
                $pagination['retained_rows'],
            );
            unset($this->observedFrontiers[$stream]);
            try {
                return $this->acceptedRecoveryCandleEvents(
                    $stream,
                    $instrumentId,
                    $matches[1],
                    $rows,
                );
            } catch (OkxPaperLiveIntegrityException $exception) {
                if ($exception->getMessage() !== 'market_data_gap_unresolved' || $this->stopped) {
                    throw $exception;
                }
            }

            return $this->recoverCandlesThroughHistory(
                $symbol,
                $stream,
                $instrumentId,
                $matches[1],
                $rows,
            );
        }
        if (($transition['stage'] ?? null) === 'history_trades') {
            $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;
            if (!\is_array($pagination)
                || !\is_array($pagination['retained_rows'] ?? null)
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            unset($this->observedFrontiers[$stream]);
            $recoveryRows = $this->boundedRetainedTradeRecoveryRows(
                $stream,
                $pagination['retained_rows'],
            );
            if (\is_array($recoveryRows)) {
                try {
                    $events = $this->acceptedRecoveryTradeEvents(
                        $stream,
                        $recoveryRows,
                    );
                    // A forward recovery goes on with its next page until the
                    // frontier reaches forward_through.
                    if ($events !== [] || !$this->forwardTradeRecoveryContinues($stream)) {
                        return $events;
                    }
                } catch (OkxPaperLiveIntegrityException $exception) {
                    if ($exception->getMessage() !== 'market_data_gap_unresolved' || $this->stopped) {
                        throw $exception;
                    }
                }
            }

            return $this->recoverTradesThroughHistory(
                $symbol,
                $stream,
                $instrumentId,
                [],
            );
        }
        if (($transition['stage'] ?? null) === 'current_candles') {
            if (preg_match('/candle_(1m|5m|15m|1H)\z/D', $stream, $matches) !== 1) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $bar = $matches[1];
            $rows = $this->restClient->currentCandles($instrumentId, $bar, null, null, 300);
            if ($this->reconnectRecoveryDeadlineExpired($symbol)) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $this->sortCandleRows($rows);

            try {
                $events = $this->acceptedRecoveryCandleEvents(
                    $stream,
                    $instrumentId,
                    $bar,
                    $rows,
                );
                $this->persistCurrentCandleSuffix(
                    $symbol,
                    $stream,
                    $instrumentId,
                    $bar,
                    $rows,
                    $events,
                );

                return $events;
            } catch (OkxPaperLiveIntegrityException $exception) {
                if ($exception->getMessage() !== 'market_data_gap_unresolved' || $this->stopped) {
                    throw $exception;
                }
            }

            return $this->recoverCandlesThroughHistory(
                $symbol,
                $stream,
                $instrumentId,
                $bar,
                $rows,
            );
        }
        if (($transition['stage'] ?? null) !== 'recent_trades') {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $rows = self::withoutPartialOldestMillisecond(
            $this->restClient->recentTrades($instrumentId, 500),
            500,
        );
        if ($this->reconnectRecoveryDeadlineExpired($symbol)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $this->sortTradeRows($rows);

        try {
            $events = $this->acceptedRecoveryTradeEvents($stream, $rows);
            $this->persistRecentTradeSuffix($symbol, $stream, $rows, $events);

            return $events;
        } catch (OkxPaperLiveIntegrityException $exception) {
            if ($exception->getMessage() !== 'market_data_gap_unresolved' || $this->stopped) {
                throw $exception;
            }
        }

        return $this->recoverTradesThroughHistory(
            $symbol,
            $stream,
            $instrumentId,
            $rows,
        );
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function acceptedRecoveryTradeEvents(string $stream, array $rows): array
    {
        return $this->acceptedEvents(
            $stream,
            $rows,
            static fn (
                array $row,
                OkxPaperMarketEventNormalizer $normalizer,
            ): PaperMarketEvent => $normalizer->recoveryTrade($row),
            fn (array $row): OkxPaperStreamFrontier => $this->tradeFrontier($row),
            true,
            'rest',
        );
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function acceptedRecoveryCandleEvents(
        string $stream,
        string $instrumentId,
        string $bar,
        array $rows,
    ): array {
        return $this->acceptedEvents(
            $stream,
            $rows,
            fn (array $row, OkxPaperMarketEventNormalizer $normalizer): ?PaperMarketEvent => $normalizer
                ->warmupCandle($instrumentId, $bar, $row),
            fn (array $row): ?OkxPaperStreamFrontier => $this->candleFrontier(
                $instrumentId,
                $bar,
                $row,
            ),
            true,
            'rest',
        );
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @param list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }> $events
     */
    private function persistCurrentCandleSuffix(
        string $symbol,
        string $stream,
        string $instrumentId,
        string $bar,
        array $rows,
        array $events,
    ): void {
        if (\count($events) < 2) {
            return;
        }
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $historyTransition = $this->restTransition(
            $symbol,
            $stream,
            'history_candles',
        );
        $state = $this->checkpoint->toArray();
        $state['overlap_pagination_by_stream'][$stream] = [
            'endpoint' => 'history_candles',
            'pagination_type' => null,
            'next_cursor' => $this->validatedOldestCandleTimestamp(
                $rows,
                $instrumentId,
                $bar,
                null,
            ),
            'pages_consumed' => 0,
            'pages_remaining' => OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
            'target_frontier' => $this->requiredRecoveryFrontier($stream)->toArray(),
            'deadline_at' => $resync['deadline_at'],
            'retained_rows' => $this->compactRetainedCandleRows($rows),
        ];
        $state['pending_transition'] = $historyTransition;
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->recoveryCheckpointWithinBudget($state),
            'reconnecting',
            $historyTransition,
        );
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     * @param list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }> $events
     */
    private function persistRecentTradeSuffix(
        string $symbol,
        string $stream,
        array $rows,
        array $events,
    ): void {
        if (\count($events) < 2) {
            return;
        }
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $oldestTimestamp = null;
        $retainedRows = [];
        $acceptedIndex = 0;
        // The history stage proves the sibling overlap again, and its cursor
        // starts below this snapshot: rows up to the sibling frontier are never
        // fetched again, so retain the snapshot from that frontier on.
        $siblingFrontier = $this->siblingFrontier($stream);
        $retainFromSibling = false;
        foreach ($rows as $row) {
            $rowFrontier = $this->tradeFrontier($row);
            $timestamp = $row['ts'] ?? null;
            if (!\is_string($timestamp)) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            if ($oldestTimestamp === null
                || self::compareUnsigned($timestamp, $oldestTimestamp) < 0
            ) {
                $oldestTimestamp = $timestamp;
            }
            if ($siblingFrontier instanceof OkxPaperStreamFrontier
                && hash_equals(
                    $siblingFrontier->naturalIdentity,
                    $rowFrontier->naturalIdentity,
                )
            ) {
                $retainFromSibling = true;
            }
            $acceptedFrontier = $events[$acceptedIndex]['frontier'] ?? null;
            if (!$acceptedFrontier instanceof OkxPaperStreamFrontier
                || !hash_equals(
                    $acceptedFrontier->naturalIdentity,
                    $rowFrontier->naturalIdentity,
                )
            ) {
                if ($retainFromSibling) {
                    $retainedRows[] = $row;
                }

                continue;
            }
            if (!hash_equals(
                $acceptedFrontier->canonicalDigest,
                $rowFrontier->canonicalDigest,
            )) {
                throw new OkxPaperLiveIntegrityException(
                    'market_event_identity_conflict',
                );
            }
            $retainedRows[] = $row;
            ++$acceptedIndex;
        }
        if (!\is_string($oldestTimestamp) || $acceptedIndex !== \count($events)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $historyTransition = $this->restTransition(
            $symbol,
            $stream,
            'history_trades',
        );
        $state = $this->checkpoint->toArray();
        $state['overlap_pagination_by_stream'][$stream] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => self::inclusiveHistoryTradeCursor($oldestTimestamp),
            'pages_consumed' => 0,
            'pages_remaining' => OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
            'target_frontier' => $this->requiredRecoveryFrontier($stream)->toArray(),
            'deadline_at' => $resync['deadline_at'],
            'retained_rows' => $this->compactRetainedTradeRows($retainedRows),
        ];
        $state['pending_transition'] = $historyTransition;
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->recoveryCheckpointWithinBudget($state),
            'reconnecting',
            $historyTransition,
        );
    }

    /**
     * @param list<array<array-key, mixed>> $newerRows
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function recoverCandlesThroughHistory(
        string $symbol,
        string $stream,
        string $instrumentId,
        string $bar,
        array $newerRows,
    ): array {
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $historyTransition = $this->restTransition(
            $symbol,
            $stream,
            'history_candles',
        );
        if (($this->checkpoint->overlapPaginationByStream[$stream] ?? null) === null) {
            $oldestTimestamp = $this->validatedOldestCandleTimestamp(
                $newerRows,
                $instrumentId,
                $bar,
                null,
            );
            $state = $this->checkpoint->toArray();
            $state['overlap_pagination_by_stream'][$stream] = [
                'endpoint' => 'history_candles',
                'pagination_type' => null,
                'next_cursor' => $oldestTimestamp,
                'pages_consumed' => 0,
                'pages_remaining' => OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
                'target_frontier' => $this->requiredRecoveryFrontier($stream)->toArray(),
                'deadline_at' => $resync['deadline_at'],
                'retained_rows' => $this->compactRetainedCandleRows($newerRows),
            ];
            $state['pending_transition'] = $historyTransition;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->recoveryCheckpointWithinBudget($state),
                'reconnecting',
                $historyTransition,
            );
        }
        unset($this->observedFrontiers[$stream]);

        while (true) {
            $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;
            if (!\is_array($pagination)
                || $pagination['pages_remaining'] <= 0
                || $this->clock->now() >= new \DateTimeImmutable($pagination['deadline_at'])
                || !\is_string($pagination['next_cursor'])
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $rows = $this->restClient->historyCandles(
                $instrumentId,
                $bar,
                $pagination['next_cursor'],
                300,
            );
            if ($this->clock->now() >= new \DateTimeImmutable($pagination['deadline_at'])) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            if ($rows === [] || \count($rows) > 300) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $oldestTimestamp = $this->validatedOldestCandleTimestamp(
                $rows,
                $instrumentId,
                $bar,
                $pagination['next_cursor'],
            );
            $newerRows = [
                ...$rows,
                ...$this->expandedRetainedCandleRows($pagination['retained_rows']),
            ];
            $this->sortCandleRows($newerRows);
            $state = $this->checkpoint->toArray();
            $next = $state['overlap_pagination_by_stream'][$stream];
            ++$next['pages_consumed'];
            --$next['pages_remaining'];
            $next['next_cursor'] = $oldestTimestamp;
            $next['retained_rows'] = $this->compactRetainedCandleRows($newerRows);
            $state['overlap_pagination_by_stream'][$stream] = $next;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->recoveryCheckpointWithinBudget($state),
                'reconnecting',
                $historyTransition,
            );
            unset($this->observedFrontiers[$stream]);
            try {
                return $this->acceptedRecoveryCandleEvents(
                    $stream,
                    $instrumentId,
                    $bar,
                    $newerRows,
                );
            } catch (OkxPaperLiveIntegrityException $exception) {
                if ($exception->getMessage() !== 'market_data_gap_unresolved' || $this->stopped) {
                    throw $exception;
                }
            }
        }
    }

    /** @param list<array<array-key, mixed>> $rows */
    private function validatedOldestCandleTimestamp(
        array $rows,
        string $instrumentId,
        string $bar,
        ?string $cursor,
    ): string {
        if ($rows === []) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $oldest = null;
        $previous = null;
        foreach ($rows as $row) {
            $this->candleFrontier($instrumentId, $bar, $row);
            $timestamp = $row[0] ?? null;
            if (!\is_string($timestamp)
                || ($cursor !== null
                    && (self::compareUnsigned($timestamp, $cursor) >= 0
                        || (\is_string($previous)
                            && self::compareUnsigned($timestamp, $previous) > 0)))
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $previous = $timestamp;
            if ($oldest === null || self::compareUnsigned($timestamp, $oldest) < 0) {
                $oldest = $timestamp;
            }
        }
        if (!\is_string($oldest)
            || ($cursor !== null && self::compareUnsigned($oldest, $cursor) >= 0)
        ) {
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $oldest;
    }

    /**
     * @param list<array<array-key, mixed>> $newerRows
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function recoverTradesThroughHistory(
        string $symbol,
        string $stream,
        string $instrumentId,
        array $newerRows,
    ): array {
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $historyTransition = $this->restTransition(
            $symbol,
            $stream,
            'history_trades',
        );
        if (($this->checkpoint->overlapPaginationByStream[$stream] ?? null) === null) {
            if ($newerRows === []) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $oldestTimestamp = null;
            foreach ($newerRows as $row) {
                $this->tradeFrontier($row);
                $timestamp = $row['ts'] ?? null;
                if (!\is_string($timestamp)) {
                    $this->failTerminal('market_data_gap_unresolved');
                }
                if ($oldestTimestamp === null
                    || self::compareUnsigned($timestamp, $oldestTimestamp) < 0
                ) {
                    $oldestTimestamp = $timestamp;
                }
            }
            if (!\is_string($oldestTimestamp)) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $state = $this->checkpoint->toArray();
            $anchor = $this->forwardRecoveryAnchorId($stream);
            $newest = null;
            foreach ($newerRows as $row) {
                $tradeId = $this->tradeFrontier($row)->sourceIdentity;
                if ($newest === null || self::compareUnsigned($tradeId, $newest) > 0) {
                    $newest = $tradeId;
                }
            }
            if (\is_string($newest)
                && BigInteger::of($newest)->minus($anchor)
                    ->isGreaterThan(OkxPaperLivePolicy::FORWARD_RECOVERY_MIN_TRADES)
            ) {
                // Forward from the frontier through the newest recent trade, one
                // emitted page at a time (see FORWARD_RECOVERY_MIN_TRADES).
                $state['overlap_pagination_by_stream'][$stream] = [
                    'endpoint' => 'history_trades',
                    'pagination_type' => 1,
                    'next_cursor' => (string) BigInteger::of($anchor)->plus(100),
                    'pages_consumed' => 0,
                    'pages_remaining' => OkxPaperLivePolicy::MAX_FORWARD_RECOVERY_PAGES,
                    'target_frontier' => $this->requiredRecoveryFrontier($stream)->toArray(),
                    'deadline_at' => $resync['deadline_at'],
                    'retained_rows' => [],
                    'forward_through' => $newest,
                ];
            } else {
                $state['overlap_pagination_by_stream'][$stream] = [
                    'endpoint' => 'history_trades',
                    'pagination_type' => 2,
                    'next_cursor' => self::inclusiveHistoryTradeCursor($oldestTimestamp),
                    'pages_consumed' => 0,
                    'pages_remaining' => OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
                    'target_frontier' => $this->requiredRecoveryFrontier($stream)->toArray(),
                    'deadline_at' => $resync['deadline_at'],
                    'retained_rows' => $this->compactRetainedTradeRows($newerRows),
                ];
            }
            $state['pending_transition'] = $historyTransition;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->recoveryCheckpointWithinBudget($state),
                'reconnecting',
                $historyTransition,
            );
        } elseif ($newerRows !== []
            && \is_string($this->checkpoint->overlapPaginationByStream[$stream]['forward_through'] ?? null)
        ) {
            // A new connection attempt (OKX closed a socket paused during a long
            // recovery) resumes the forward recovery from the advanced frontier:
            // its target moves up to the newest recent trade of this attempt.
            $through = $this->checkpoint->overlapPaginationByStream[$stream]['forward_through'];
            foreach ($newerRows as $row) {
                $tradeId = $this->tradeFrontier($row)->sourceIdentity;
                if (self::compareUnsigned($tradeId, $through) > 0) {
                    $through = $tradeId;
                }
            }
            $state = $this->checkpoint->toArray();
            $state['overlap_pagination_by_stream'][$stream]['forward_through'] = $through;
            $state['pending_transition'] = $historyTransition;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->recoveryCheckpointWithinBudget($state),
                'reconnecting',
                $historyTransition,
            );
        }
        unset($this->observedFrontiers[$stream]);

        while (true) {
            $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;
            if (!\is_array($pagination)
                || $pagination['pages_remaining'] <= 0
                || $this->clock->now() >= new \DateTimeImmutable($pagination['deadline_at'])
                || !\is_string($pagination['next_cursor'])
                || !\is_int($pagination['pagination_type'])
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            if (\array_key_exists('forward_through', $pagination)) {
                return $this->forwardTradeRecoveryPage($stream, $instrumentId, $historyTransition);
            }
            $rows = $this->restClient->historyTrades(
                $instrumentId,
                $pagination['pagination_type'],
                $pagination['next_cursor'],
                100,
            );
            if ($this->clock->now() >= new \DateTimeImmutable($pagination['deadline_at'])) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            if ($rows === [] || \count($rows) > 100) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $oldestTradeId = $this->validatedOldestHistoryTradeId(
                $rows,
                $instrumentId,
                $pagination['pagination_type'],
                $pagination['next_cursor'],
            );
            $retainedRows = $this->mergeRetainedTradePage(
                $rows,
                $pagination['retained_rows'],
            );
            $state = $this->checkpoint->toArray();
            $next = $state['overlap_pagination_by_stream'][$stream];
            ++$next['pages_consumed'];
            --$next['pages_remaining'];
            $next['pagination_type'] = 1;
            // A timestamp page can hold only part of its oldest millisecond (see
            // withoutPartialOldestMillisecond()): continue by trade id from above
            // its newest trade, which re-covers the page exactly (rows are merged).
            $next['next_cursor'] = $pagination['pagination_type'] === 2
                ? (string) BigInteger::of($this->newestTradeId($rows))->plus(1)
                : $oldestTradeId;
            $next['retained_rows'] = $retainedRows;
            $state['overlap_pagination_by_stream'][$stream] = $next;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->recoveryCheckpointWithinBudget($state),
                'reconnecting',
                $historyTransition,
            );
            unset($this->observedFrontiers[$stream]);
            $recoveryRows = $this->boundedRetainedTradeRecoveryRows(
                $stream,
                $retainedRows,
            );
            if (\is_array($recoveryRows)) {
                try {
                    return $this->acceptedRecoveryTradeEvents(
                        $stream,
                        $recoveryRows,
                    );
                } catch (OkxPaperLiveIntegrityException $exception) {
                    if ($exception->getMessage() !== 'market_data_gap_unresolved' || $this->stopped) {
                        throw $exception;
                    }
                }
            }
        }
    }

    /**
     * One page of a forward trade recovery: the acknowledged frontier and the 99
     * next ids (history-trades by id, `after` = frontier + 100), replacing the
     * emitted page in the checkpoint, then its trades after the frontier. The
     * cursor always derives from the acknowledged frontier, so a restart in the
     * middle of a page fetches it again from there: no hole, no duplicate.
     *
     * @param array<string, mixed> $historyTransition
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function forwardTradeRecoveryPage(
        string $stream,
        string $instrumentId,
        array $historyTransition,
    ): array {
        $this->extendForwardTargetToQueuedTrades($stream);
        $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;
        if (!\is_array($pagination) || !\is_string($pagination['forward_through'] ?? null)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        // The last page stops at the target, like the backward pagination.
        $cursor = (string) BigInteger::min(
            BigInteger::of($this->forwardRecoveryAnchorId($stream))->plus(100),
            BigInteger::of($pagination['forward_through'])->plus(1),
        );
        $rows = $this->restClient->historyTrades($instrumentId, 1, $cursor, 100);
        if (!\is_array($pagination)
            || $this->clock->now() >= new \DateTimeImmutable($pagination['deadline_at'])
            || $rows === []
            || \count($rows) > 100
        ) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $this->validatedOldestHistoryTradeId($rows, $instrumentId, 1, $cursor);
        $this->sortTradeRows($rows);
        $state = $this->checkpoint->toArray();
        $next = $state['overlap_pagination_by_stream'][$stream];
        ++$next['pages_consumed'];
        --$next['pages_remaining'];
        $next['next_cursor'] = (string) BigInteger::of($this->newestTradeId($rows))->plus(100);
        $next['retained_rows'] = $this->compactRetainedTradeRows($rows);
        $state['overlap_pagination_by_stream'][$stream] = $next;
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->recoveryCheckpointWithinBudget($state),
            'reconnecting',
            $historyTransition,
        );
        unset($this->observedFrontiers[$stream]);
        $recoveryRows = $this->boundedRetainedTradeRecoveryRows($stream, $next['retained_rows']);
        if (!\is_array($recoveryRows)) {
            // history-trades serves every id below its cursor: the frontier is there.
            $this->failTerminal('market_data_gap_unresolved');
        }
        $events = $this->acceptedRecoveryTradeEvents($stream, $recoveryRows);
        if ($events === [] && $this->forwardTradeRecoveryContinues($stream)) {
            // None of the 99 ids after the frontier exists: a hole OKX never showed.
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $events;
    }

    /**
     * A forward recovery ends where the websocket takes over. After a new connection
     * attempt (OKX closes a socket paused during a long recovery), the first queued
     * trade of this connection can lie beyond the target set by an earlier attempt:
     * the target moves up to just before it, so the junction left to the websocket
     * stays small whatever the number of attempts.
     */
    private function extendForwardTargetToQueuedTrades(string $stream): void
    {
        $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;
        if (!\is_array($pagination) || !\is_string($pagination['forward_through'] ?? null)) {
            return;
        }
        $symbol = strstr($stream, '/', true);
        $through = null;
        foreach ($this->publicQueue->frames() as $frame) {
            try {
                $message = $this->decoder->decodePublic($frame);
                $instrumentId = $message['arg']['instId'] ?? null;
                if (($message['arg']['channel'] ?? null) !== 'trades'
                    || !\is_string($instrumentId)
                    || $this->instruments->normalizedSymbol($instrumentId) !== $symbol
                    || !\is_array($message['data'] ?? null)
                ) {
                    continue;
                }
                foreach ($message['data'] as $row) {
                    if (\is_array($row)) {
                        $first = $this->tradeRowRange($row)[0];
                        $through = (string) BigInteger::of($first)->minus(1);
                        break 2;
                    }
                }
            } catch (\Throwable) {
                // The queued frame path reports invalid frames.
                return;
            }
        }
        if (!\is_string($through) || self::compareUnsigned($through, $pagination['forward_through']) <= 0) {
            return;
        }
        $transition = $this->checkpoint->pendingTransition;
        if (!\is_array($transition) || ($transition['stage'] ?? null) !== 'history_trades') {
            return;
        }
        $state = $this->checkpoint->toArray();
        $state['overlap_pagination_by_stream'][$stream]['forward_through'] = $through;
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $this->recoveryCheckpointWithinBudget($state),
            'reconnecting',
            $transition,
        );
    }

    /** The newest trade id every recovery overlap must reach (own or sibling frontier). */
    private function forwardRecoveryAnchorId(string $stream): string
    {
        $anchor = null;
        foreach ($this->requiredRecoveryOverlaps($stream, $this->requiredRecoveryFrontier($stream)) as $overlap) {
            $tradeId = $overlap['frontier']->sourceIdentity;
            if ($anchor === null || self::compareUnsigned($tradeId, $anchor) > 0) {
                $anchor = $tradeId;
            }
        }
        if (!\is_string($anchor) || preg_match('/\A[1-9][0-9]*\z/D', $anchor) !== 1) {
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $anchor;
    }

    private function forwardTradeRecoveryContinues(string $stream): bool
    {
        $this->extendForwardTargetToQueuedTrades($stream);
        $pagination = $this->checkpoint->overlapPaginationByStream[$stream] ?? null;

        return \is_array($pagination)
            && \is_string($pagination['forward_through'] ?? null)
            && self::compareUnsigned(
                $this->forwardRecoveryAnchorId($stream),
                $pagination['forward_through'],
            ) < 0;
    }

    /** @param list<array<array-key, mixed>> $rows */
    private function validatedOldestHistoryTradeId(
        array $rows,
        string $instrumentId,
        int $paginationType,
        string $cursor,
    ): string {
        $oldestId = null;
        $previousTimestamp = null;
        $previousId = null;
        foreach ($rows as $row) {
            if (!\is_array($row)
                || array_is_list($row)
                || ($row['instId'] ?? null) !== $instrumentId
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $frontier = $this->tradeFrontier($row);
            $tradeId = $frontier->sourceIdentity;
            $timestamp = $row['ts'] ?? null;
            if (!\is_string($timestamp)
                || ($paginationType === 2
                    && self::compareUnsigned($timestamp, $cursor) >= 0)
                || ($paginationType === 1
                    && self::compareUnsigned($tradeId, $cursor) > 0)
            ) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            if (\is_string($previousTimestamp)) {
                $timestampOrder = self::compareUnsigned($timestamp, $previousTimestamp);
                if ($timestampOrder > 0
                    || ($timestampOrder === 0
                        && \is_string($previousId)
                        && self::compareUnsigned($tradeId, $previousId) > 0)
                ) {
                    $this->failTerminal('market_data_gap_unresolved');
                }
            }
            $previousTimestamp = $timestamp;
            $previousId = $tradeId;
            if ($oldestId === null || self::compareUnsigned($tradeId, $oldestId) < 0) {
                $oldestId = $tradeId;
            }
        }
        if (!\is_string($oldestId)
            || ($paginationType === 1 && self::compareUnsigned($oldestId, $cursor) >= 0)
        ) {
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $oldestId;
    }

    private function completeReconnectFrontierOverlap(string $symbol, string $stream): void
    {
        if (str_contains($stream, '/ws/')) {
            $this->requiresOverlap[$stream] = true;
        }
        $state = $this->checkpoint->toArray();
        $state['overlap_pagination_by_stream'][$stream] = null;
        if (($state['resync_by_symbol'][$symbol]['policy'] ?? null)
            !== 'book_seq_overlap_v1'
        ) {
            $state['resync_by_symbol'][$symbol] = null;
        }
        $next = null;
        $transitions = $this->reconnectRecoveryTransitions($symbol);
        foreach ($transitions as $index => $transition) {
            if ($transition['stream'] === $stream) {
                $next = $transitions[$index + 1] ?? null;
                break;
            }
        }
        if ($next === null) {
            $boundary = $this->checkpoint->remainingBoundaries[0] ?? null;
            if ($boundary !== ['symbol' => $symbol, 'reason' => 'reconnect']) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            $next = [
                'kind' => 'emit_boundary',
                'symbol' => $symbol,
                'stream' => $symbol . '/control/snapshot_boundary',
                'stage' => 'reconnect',
            ];
        }
        $state['pending_transition'] = $next;
        $this->checkpoint = $this->checkpointStore->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($state),
            'reconnecting',
            $next,
        );
    }

    /**
     * @param array<string, mixed> $transition
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function reconnectBookEvents(string $symbol, array $transition): array
    {
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)
            || $this->clock->now() >= new \DateTimeImmutable($resync['deadline_at'])
        ) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $instrumentId = $this->instruments->nativeInstrumentId($symbol);
        $rows = $this->restClient->orderBook($instrumentId, 400);
        if ($this->reconnectRecoveryDeadlineExpired($symbol)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        if (\count($rows) !== 1 || !\is_array($rows[0])) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $replacement = new OkxPaperOrderBookMaterializer();
        $replacementState = $replacement->replaceSnapshot($rows[0]);
        if (($this->checkpoint->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null)
            instanceof OkxPaperStreamFrontier
        ) {
            if (!$this->requireQueuedReconnectBookOverlap(
                $symbol,
                $instrumentId,
                $replacementState->sourceSequence,
            )) {
                return [];
            }
        }
        unset($this->discardedBookSnapshots[$symbol], $this->reservedBookSnapshots[$symbol]);
        $this->requiresOverlap[$symbol . '/ws/top_of_book'] = false;
        $this->persistDurableBookRecovery($symbol, $rows[0], $transition);
        $state = $this->books[$instrumentId]->replaceSnapshot($rows[0]);
        $this->requiresOverlap[$symbol . '/rest/top_of_book'] = false;
        $events = $this->acceptedBookEvents(
            $symbol . '/rest/top_of_book',
            $instrumentId,
            $state,
            'rest_resync_snapshot',
            $this->checkpoint->sourceEpochs[$symbol],
            !(($this->checkpoint->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null)
                instanceof OkxPaperStreamFrontier),
        );
        if (\count($events) !== 1) {
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $events;
    }

    private function reconnectRecoveryDeadlineExpired(string $symbol): bool
    {
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;

        return !\is_array($resync)
            || $this->clock->now() >= new \DateTimeImmutable($resync['deadline_at']);
    }

    private function requiredRecoveryFrontier(string $stream): OkxPaperStreamFrontier
    {
        $frontier = $this->checkpoint->streamFrontiers[$stream] ?? null;
        if (!$frontier instanceof OkxPaperStreamFrontier) {
            $this->failTerminal('market_data_gap_unresolved');
        }

        return $frontier;
    }

    /** @param array<string, mixed> $transition */
    private function emitReconnectBoundary(array $transition): \Generator
    {
        $symbol = $transition['symbol'] ?? null;
        if (!\is_string($symbol)
            || ($transition['stage'] ?? null) !== 'reconnect'
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }
        $bookFrontier = $this->checkpoint->streamFrontiers[
            $symbol . '/rest/top_of_book'
        ] ?? null;
        if (!$bookFrontier instanceof OkxPaperStreamFrontier) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $event = $this->normalizer->snapshotBoundary(
            $this->instruments->nativeInstrumentId($symbol),
            'reconnect',
            $this->checkpoint->sourceEpochs[$symbol],
            $bookFrontier->sourceIdentity,
        );
        $this->checkpoint = $this->checkpointStore->savePending(
            $this->checkpoint,
            $event,
            $this->ordinals->snapshot(),
            null,
        );
        yield $this->checkpoint->pendingEvent
            ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        $this->assertPendingWasAcknowledged();
    }

    /**
     * The book authority of a reconnect is a queued websocket snapshot (sent on
     * subscription) or a queued delta chaining exactly from the REST snapshot.
     * OKX samples its books pushes, so a REST sequence rarely lies on that chain,
     * and a pending symbol's websocket snapshot can be dropped while an earlier
     * symbol recovers (see discardRecoverablePublicFramesBeforeBookAuthority()):
     * waiting for the chain could freeze the whole capture until the resync
     * deadline. The wait is logged every 10 s and bounded: the books channel of
     * the instrument is resubscribed (OKX pushes a full snapshot on subscription)
     * at once when its snapshot was dropped, otherwise after 15 s; without any
     * authority after 60 s the recovery fails closed.
     */
    private function requireQueuedReconnectBookOverlap(
        string $symbol,
        string $instrumentId,
        string $snapshotSequence,
    ): bool {
        $generation = $this->connectionGeneration;
        if ($this->hasQueuedReconnectBookAuthority($instrumentId, $snapshotSequence)) {
            return true;
        }

        // The REST response and the websocket frame linking to it race each
        // other during reconnect. Give the socket one bounded, non-blocking
        // tick so an already-arrived frame can enter the durable queue before
        // declaring the overlap missing.
        $this->discardRecoverablePublicFramesBeforeBookAuthority($instrumentId);
        $this->resumePublicAdmissionsForReconnectOverlap();
        $this->pumpNetworkLoop();
        if ($generation !== $this->connectionGeneration) {
            return false;
        }
        if ($this->hasQueuedReconnectBookAuthority($instrumentId, $snapshotSequence)) {
            return true;
        }

        $startedAt = $this->clock->now();
        $lastLoggedAt = null;
        $resubscribed = false;
        while (!$this->reconnectRecoveryDeadlineExpired($symbol)) {
            $now = $this->clock->now();
            $waited = (float) $now->format('U.u') - (float) $startedAt->format('U.u');
            if ($lastLoggedAt === null
                || (float) $now->format('U.u') - (float) $lastLoggedAt->format('U.u')
                    >= self::BOOK_AUTHORITY_LOG_SECONDS
            ) {
                $this->logBookAuthorityWait($symbol, $instrumentId, $snapshotSequence, $waited, $resubscribed);
                $lastLoggedAt = $now;
            }
            if (!$resubscribed
                && (isset($this->discardedBookSnapshots[$symbol])
                    || $waited >= self::BOOK_AUTHORITY_RESUBSCRIBE_SECONDS)
            ) {
                $resubscribed = true;
                $this->resubscribeBooks($symbol, $instrumentId);
                if ($generation !== $this->connectionGeneration) {
                    return false;
                }
                if ($this->hasQueuedReconnectBookAuthority($instrumentId, $snapshotSequence)) {
                    return true;
                }
            }
            if ($waited >= self::BOOK_AUTHORITY_MAX_WAIT_SECONDS) {
                break;
            }
            $this->discardRecoverablePublicFramesBeforeBookAuthority($instrumentId);
            $beforeProgress = [
                $this->connectionGeneration,
                $this->checkpoint->phase,
                $this->checkpoint->pendingTransition,
                $this->publicQueue->count(),
                $this->publicQueue->bytes(),
                $this->businessQueue->count(),
                $this->businessQueue->bytes(),
                $this->clock->now()->format('U.u'),
            ];
            $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
            if (!\is_array($resync)) {
                $this->failTerminal('market_data_gap_unresolved');
            }
            $deadline = new \DateTimeImmutable($resync['deadline_at']);
            $remaining = max(
                0.0,
                min(
                    self::BOOK_AUTHORITY_LOG_SECONDS,
                    (float) $deadline->format('U.u')
                        - (float) $this->clock->now()->format('U.u'),
                ),
            );
            $deadlineTimer = $this->loop->addTimer(
                $remaining,
                fn () => $this->loop->stop(),
            );
            try {
                $this->resumePublicAdmissionsForReconnectOverlap();
                // Every admitted websocket frame stops the loop; so does the
                // periodic timer, which bounds and logs the wait.
                $this->runNetworkLoop();
            } finally {
                $this->loop->cancelTimer($deadlineTimer);
            }
            if ($generation !== $this->connectionGeneration) {
                return false;
            }
            if ($this->hasQueuedReconnectBookAuthority($instrumentId, $snapshotSequence)) {
                return true;
            }
            $afterProgress = [
                $this->connectionGeneration,
                $this->checkpoint->phase,
                $this->checkpoint->pendingTransition,
                $this->publicQueue->count(),
                $this->publicQueue->bytes(),
                $this->businessQueue->count(),
                $this->businessQueue->bytes(),
                $this->clock->now()->format('U.u'),
            ];
            // Neither a frame nor time: a deterministic loop that cannot progress.
            if ($beforeProgress === $afterProgress) {
                break;
            }
        }
        $this->logBookAuthorityWait(
            $symbol,
            $instrumentId,
            $snapshotSequence,
            (float) $this->clock->now()->format('U.u') - (float) $startedAt->format('U.u'),
            $resubscribed,
            'okx_paper_public_book_authority_unavailable',
        );

        $this->failTerminal('market_data_gap_unresolved');
    }

    /**
     * OKX pushes a full books snapshot on subscription: unsubscribe then subscribe
     * the instrument's books channel. Both acknowledgements are consumed on
     * admission (consumesBookResubscriptionAcknowledgement()).
     */
    private function resubscribeBooks(string $symbol, string $instrumentId): void
    {
        $argument = ['channel' => 'books', 'instId' => $instrumentId];
        $this->bookResubscriptions[$instrumentId] = ['unsubscribe' => true, 'subscribe' => true];
        $this->warn('okx_paper_public_book_resubscribe', [
            'symbol' => $symbol,
            'snapshot_discarded' => isset($this->discardedBookSnapshots[$symbol]),
        ]);
        unset($this->discardedBookSnapshots[$symbol]);
        $this->publicTransport->send(['op' => 'unsubscribe', 'args' => [$argument]]);
        $this->publicTransport->send(['op' => 'subscribe', 'args' => [$argument]]);
        $this->resumePublicAdmissionsForReconnectOverlap();
        $this->pumpNetworkLoop();
    }

    /** @param array<string, mixed> $message */
    private function consumesBookResubscriptionAcknowledgement(array $message): bool
    {
        $event = $message['event'] ?? null;
        $argument = $message['arg'] ?? null;
        if (($event !== 'subscribe' && $event !== 'unsubscribe')
            || !\is_array($argument)
            || ($argument['channel'] ?? null) !== 'books'
            || !\is_string($argument['instId'] ?? null)
            || !isset($this->bookResubscriptions[$argument['instId']][$event])
        ) {
            return false;
        }
        unset($this->bookResubscriptions[$argument['instId']][$event]);
        if ($this->bookResubscriptions[$argument['instId']] === []) {
            unset($this->bookResubscriptions[$argument['instId']]);
        }

        return true;
    }

    private function logBookAuthorityWait(
        string $symbol,
        string $instrumentId,
        string $snapshotSequence,
        float $waited,
        bool $resubscribed,
        string $message = 'okx_paper_public_book_authority_wait',
    ): void {
        try {
            $frames = 0;
            $snapshots = 0;
            $sequences = [];
            $previousSequences = [];
            foreach ($this->publicQueue->frames() as $frame) {
                $decoded = $this->decoder->decodePublic($frame);
                if (($decoded['arg']['channel'] ?? null) !== 'books'
                    || ($decoded['arg']['instId'] ?? null) !== $instrumentId
                ) {
                    continue;
                }
                ++$frames;
                if (($decoded['action'] ?? null) === 'snapshot') {
                    ++$snapshots;
                }
                foreach (\is_array($decoded['data'] ?? null) ? $decoded['data'] : [] as $row) {
                    $sequence = \is_array($row) ? self::queuedBookSequence($row['seqId'] ?? null) : null;
                    $previous = \is_array($row) ? self::queuedBookSequence($row['prevSeqId'] ?? null) : null;
                    if ($sequence !== null) {
                        $sequences[] = $sequence;
                    }
                    if ($previous !== null) {
                        $previousSequences[] = $previous;
                    }
                }
            }
            $this->warn($message, [
                'symbol' => $symbol,
                'rest_snapshot_sequence' => $snapshotSequence,
                'queued_book_frames' => $frames,
                'queued_book_snapshots' => $snapshots,
                'queued_seq_first' => $sequences[0] ?? null,
                'queued_seq_last' => $sequences === [] ? null : $sequences[\count($sequences) - 1],
                'queued_prev_seq_first' => $previousSequences[0] ?? null,
                'public_queue_frames' => $this->publicQueue->count(),
                'waited_s' => round($waited, 3),
                'snapshot_discarded' => isset($this->discardedBookSnapshots[$symbol]),
                'resubscribed' => $resubscribed,
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    /**
     * While reconnect recovery is waiting for the exact book authority, queued
     * trades are covered by the subsequent REST overlap pass, and the current
     * symbol's book traffic has already failed to authorize its REST snapshot.
     * Frames for a symbol whose boundary is already durable must remain queued
     * for normal streaming emission. A still-pending symbol keeps its last
     * websocket snapshot and the deltas after it in reserve: its own recovery
     * then has its authority at once, even after a long trade recovery of the
     * current symbol (a sub-second wait used to drop it, and the resubscription
     * snapshot then stayed behind minutes of TCP backlog). Reserves that would
     * bring the queue to its pause threshold are dropped instead: those symbols
     * resubscribe at their turn. Persist the reduced queue before reopening
     * admissions so repeated high-watermark pauses cannot consume the hard
     * queue budget.
     */
    private function discardRecoverablePublicFramesBeforeBookAuthority(
        string $instrumentId,
    ): void
    {
        if ($this->publicQueue->count() === 0) {
            return;
        }
        /** @var array<int, array{string, string, bool}> $books offset => frame, symbol, pending */
        $books = [];
        $reserveFrom = [];
        foreach ($this->publicQueue->frames() as $offset => $frame) {
            $message = $this->decoder->decodePublic($frame);
            if (($message['arg']['channel'] ?? null) !== 'books') {
                continue;
            }
            $frameInstrumentId = $message['arg']['instId'] ?? null;
            if (!\is_string($frameInstrumentId)) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            try {
                $frameSymbol = $this->instruments->normalizedSymbol($frameInstrumentId);
            } catch (\InvalidArgumentException $exception) {
                throw new OkxPaperLiveIntegrityException(
                    'market_data_gap_unresolved',
                    0,
                    $exception,
                );
            }
            if ($frameInstrumentId === $instrumentId) {
                continue;
            }
            $pending = \in_array($frameSymbol, $this->checkpoint->remainingSymbols, true);
            $books[$offset] = [$frame, $frameSymbol, $pending];
            if ($pending && ($message['action'] ?? null) === 'snapshot') {
                $reserveFrom[$frameSymbol] = $offset;
            }
        }
        /** @var array<int, array{string, string|null}> $kept offset => frame, reserve symbol */
        $kept = [];
        $bytes = 0;
        foreach ($books as $offset => [$frame, $frameSymbol, $pending]) {
            if ($pending && $offset < ($reserveFrom[$frameSymbol] ?? \PHP_INT_MAX)) {
                continue;
            }
            $kept[$offset] = [$frame, $pending ? $frameSymbol : null];
            $bytes += \strlen($frame);
        }
        $recovering = $this->instruments->normalizedSymbol($instrumentId);
        if (\count($kept) >= OkxPaperLivePolicy::PAUSE_QUEUED_FRAMES
            || $bytes >= OkxPaperLivePolicy::PAUSE_QUEUED_BYTES
        ) {
            foreach ($kept as $offset => [, $reserveSymbol]) {
                if ($reserveSymbol === null) {
                    continue;
                }
                unset($kept[$offset], $this->reservedBookSnapshots[$reserveSymbol]);
                if (!isset($this->discardedBookSnapshots[$reserveSymbol])) {
                    // Its recovery will resubscribe at once instead of waiting.
                    $this->discardedBookSnapshots[$reserveSymbol] = true;
                    $this->warn('okx_paper_public_book_snapshot_discarded', [
                        'symbol' => $reserveSymbol,
                        'while_recovering' => $recovering,
                        'queued_book_frames' => \count($books),
                    ]);
                }
            }
        } else {
            foreach (array_keys($reserveFrom) as $reserveSymbol) {
                if (!isset($this->reservedBookSnapshots[$reserveSymbol])) {
                    $this->reservedBookSnapshots[$reserveSymbol] = true;
                    $this->warn('okx_paper_public_book_snapshot_reserved', [
                        'symbol' => $reserveSymbol,
                        'while_recovering' => $recovering,
                    ]);
                }
            }
        }
        $retained = array_column($kept, 0);
        if (\count($retained) === $this->publicQueue->count()) {
            return;
        }
        $this->publicQueue->replace($retained);
        $this->persistStreamingQueues();
    }

    private function resumePublicAdmissionsForReconnectOverlap(): void
    {
        if (!$this->socketAdmissionsPaused['public']
            || !$this->socketReady(false)
            || !$this->publicTransport instanceof OkxPaperPausableWebSocketTransportInterface
        ) {
            return;
        }
        $this->socketAdmissionsPaused['public'] = false;
        $this->markReadActive('public');
        $this->publicTransport->resume();
    }

    private function hasQueuedReconnectBookAuthority(
        string $instrumentId,
        string $snapshotSequence,
    ): bool {
        return $this->filterQueuedReconnectBookSnapshot($instrumentId)
            || $this->filterQueuedBookOverlap($instrumentId, $snapshotSequence);
    }

    private function filterQueuedReconnectBookSnapshot(string $instrumentId): bool
    {
        $frames = $this->publicQueue->frames();
        $snapshotOffset = null;
        foreach ($frames as $offset => $frame) {
            $message = $this->decoder->decodePublic($frame);
            if (($message['arg']['channel'] ?? null) === 'books'
                && ($message['arg']['instId'] ?? null) === $instrumentId
                && ($message['action'] ?? null) === 'snapshot'
            ) {
                $snapshotOffset = $offset;
                break;
            }
        }
        if (!\is_int($snapshotOffset)) {
            return false;
        }

        $retainedFrames = [];
        $replacement = new OkxPaperOrderBookMaterializer();
        foreach ($frames as $offset => $frame) {
            $message = $this->decoder->decodePublic($frame);
            $isTargetBook = ($message['arg']['channel'] ?? null) === 'books'
                && ($message['arg']['instId'] ?? null) === $instrumentId;
            if (!$isTargetBook) {
                $retainedFrames[] = $frame;

                continue;
            }
            $rows = $message['data'] ?? null;
            if (!\is_array($rows) || !array_is_list($rows) || $rows === []) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            if ($offset < $snapshotOffset) {
                if (!$this->isBookUpdate($message)) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                foreach ($rows as $row) {
                    if (!\is_array($row)
                        || self::queuedBookSequence($row['seqId'] ?? null) === null
                        || self::queuedBookSequence($row['prevSeqId'] ?? null) === null
                    ) {
                        throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                    }
                }

                continue;
            }
            if ($offset === $snapshotOffset) {
                if (($message['action'] ?? null) !== 'snapshot' || \count($rows) !== 1) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                $row = $rows[0];
                if (!\is_array($row)) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                $replacement->replaceSnapshot($row);
                $retainedFrames[] = $frame;

                continue;
            }
            if (!$this->isBookUpdate($message)) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                $replacement->applyDelta($row);
            }
            $retainedFrames[] = $frame;
        }
        $this->publicQueue->replace($retainedFrames);

        return true;
    }

    private function scheduleNextReconnectAttempt(): void
    {
        $attempt = $this->checkpoint->reconnect['attempt'];
        if ($attempt >= \count(OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS)) {
            $this->failTerminal('okx_paper_public_reconnect_exhausted');
        }
        ++$this->connectionGeneration;
        $this->discardQueuedBooksFromPreviousConnection();
        $this->subscriptions->reset();
        $this->publicAcknowledgements = [];
        $this->businessAcknowledgements = [];
        $publicClose = [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'public',
            'stage' => 'close',
        ];
        $this->ensureTransition('reconnecting', $publicClose);
        $this->publicTransport->close();
        $businessClose = [
            'kind' => 'transport_close',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'close',
        ];
        $this->ensureTransition('reconnecting', $businessClose);
        $this->businessTransport->close();

        $nextAttempt = $attempt + 1;
        $delay = OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS[$nextAttempt - 1];
        $state = $this->checkpoint->toArray();
        ++$state['connection_epoch'];
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['remaining_boundaries'] = [
            ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
            ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
        ];
        $state['reconnect'] = [
            'attempt' => $nextAttempt,
            'deadline_at' => $this->clock->now()->modify(sprintf(
                '+%d seconds',
                (int) $delay,
            ))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'stable_since' => null,
            'accepted_events' => 0,
        ];
        $transition = [
            'kind' => 'timer_schedule',
            'symbol' => null,
            'stream' => null,
            'stage' => 'reconnect_delay',
        ];
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $candidate,
            'reconnecting',
            $transition,
        );
        $this->connectionGeneration = $this->checkpoint->connectionEpoch;
        $this->scheduleReconnectTimer($delay);
    }

    private function discardQueuedBooksFromPreviousConnection(): void
    {
        // So do its pending books resubscriptions and dropped snapshots: the new
        // connection's own books acknowledgements must reach the subscription set.
        $this->bookResubscriptions = [];
        $this->discardedBookSnapshots = [];
        $this->reservedBookSnapshots = [];
        // Buffered frames of the dead connection are recovered from REST (and books
        // from a new authority), like those left in its socket buffer.
        $this->discardInboundBuffers();
        $retained = [];
        foreach ($this->publicQueue->frames() as $frame) {
            $message = $this->decoder->decodePublic($frame);
            if (($message['arg']['channel'] ?? null) !== 'books') {
                $retained[] = $frame;
            }
        }
        if (\count($retained) === $this->publicQueue->count()) {
            return;
        }
        $this->publicQueue->replace($retained);
        $this->persistStreamingQueues();
    }

    private function startHeartbeatTimers(): void
    {
        foreach (['public', 'business'] as $socket) {
            if (!$this->lastInboundAt[$socket] instanceof \DateTimeImmutable) {
                $this->lastInboundAt[$socket] = $this->clock->now();
            }
            $this->armHeartbeatTimer($socket);
        }
    }

    private function scheduleStabilityResetTimer(): void
    {
        if ($this->stabilityTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->stabilityTimer);
            $this->stabilityTimer = null;
        }
        $stableSince = $this->checkpoint->reconnect['stable_since'];
        if ($this->checkpoint->phase !== 'streaming'
            || $this->checkpoint->reconnect['attempt'] === 0
            || !\is_string($stableSince)
        ) {
            return;
        }
        $deadline = (new \DateTimeImmutable($stableSince))->modify(sprintf(
            '+%d seconds',
            (int) OkxPaperLivePolicy::RECONNECT_STABLE_SECONDS,
        ));
        $delay = max(
            0.0,
            (float) $deadline->format('U.u')
                - (float) $this->clock->now()->format('U.u'),
        );
        $generation = $this->connectionGeneration;
        $this->stabilityTimer = $this->loop->addTimer(
            $delay,
            function () use ($generation, $deadline): void {
                $this->stabilityTimer = null;
                if ($generation !== $this->connectionGeneration
                    || $this->stopped
                    || $this->checkpoint->phase !== 'streaming'
                    || $this->checkpoint->reconnect['attempt'] === 0
                ) {
                    return;
                }
                if ($this->clock->now() < $deadline) {
                    $this->scheduleStabilityResetTimer();

                    return;
                }
                if ($this->checkpoint->reconnect['accepted_events']
                    === OkxPaperLivePolicy::RECONNECT_STABLE_ACCEPTED_EVENTS
                ) {
                    $this->checkpoint = $this->checkpointStore->saveTransition(
                        $this->checkpoint,
                        'streaming',
                        null,
                    );
                }
            },
        );
    }

    private function refreshInboundFreshness(string $socket, string $frame): void
    {
        if ($socket !== 'public' && $socket !== 'business') {
            return;
        }
        $this->lastInboundAt[$socket] = $this->clock->now();
        $this->heartbeatDeferredForBacklog[$socket] = false;
        if (isset($this->pingProbes[$socket])) {
            ++$this->pingProbes[$socket]['frames'];
        }
        if ($frame === 'pong') {
            $this->lastPongAt[$socket] = $this->lastInboundAt[$socket];
            unset($this->pingProbes[$socket]);
        }
        if ($frame === 'pong' && isset($this->pongTimers[$socket])) {
            $this->loop->cancelTimer($this->pongTimers[$socket]);
            unset($this->pongTimers[$socket]);
            ++$this->pongGenerations[$socket];
        }
        if ($this->checkpoint->phase === 'streaming') {
            $this->armHeartbeatTimer($socket);
        }
    }

    private function armHeartbeatTimer(
        string $socket,
        float $delay = OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS,
    ): void {
        if (isset($this->heartbeatTimers[$socket])) {
            $this->loop->cancelTimer($this->heartbeatTimers[$socket]);
        }
        $generation = ++$this->heartbeatGenerations[$socket];
        $this->heartbeatDueAt[$socket] = (float) $this->clock->now()->format('U.u') + $delay;
        $this->heartbeatTimers[$socket] = $this->loop->addTimer(
            $delay,
            function () use ($socket, $generation): void {
                if ($generation !== $this->heartbeatGenerations[$socket]
                    || $this->checkpoint->phase !== 'streaming'
                ) {
                    return;
                }
                unset($this->heartbeatTimers[$socket]);
                $queue = $socket === 'public'
                    ? $this->publicQueue
                    : $this->businessQueue;
                if ($queue->count() !== 0) {
                    $this->heartbeatDeferredForBacklog[$socket] = true;
                    $this->armHeartbeatTimer($socket);

                    return;
                }
                $lastInbound = $this->lastInboundAt[$socket];
                if (!$lastInbound instanceof \DateTimeImmutable) {
                    $this->logLivenessReconnect($socket, 'no_inbound', 'reconnect');
                    $this->beginPairedReconnect();

                    return;
                }
                $deadline = $lastInbound->modify(sprintf(
                    '+%d seconds',
                    (int) OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS,
                ));
                if ($this->clock->now() < $deadline) {
                    $remaining = (float) $deadline->format('U.u')
                        - (float) $this->clock->now()->format('U.u');
                    $this->armHeartbeatTimer($socket, $remaining);

                    return;
                }
                $transport = $socket === 'public'
                    ? $this->publicTransport
                    : $this->businessTransport;
                $transport->send(['op' => 'ping']);
                $now = (float) $this->clock->now()->format('U.u');
                $this->pingProbes[$socket] = [
                    'sent_at' => $now,
                    // How late the heartbeat ran: the time the event loop was blocked.
                    'heartbeat_late_s' => round(max(0.0, $now - ($this->heartbeatDueAt[$socket] ?? $now)), 3),
                    'frames' => 0,
                    'backlog' => false,
                    'logged_at' => null,
                    'polls' => 0,
                    'max_poll_gap_s' => 0.0,
                ];
                $this->armPongTimer($socket, ++$this->pongGenerations[$socket]);
            },
        );
    }

    private function armPongTimer(string $socket, int $pongGeneration): void
    {
        $this->pongTimers[$socket] = $this->loop->addTimer(
            OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS,
            function () use ($socket, $pongGeneration): void {
                if ($pongGeneration !== $this->pongGenerations[$socket]
                    || $this->checkpoint->phase !== 'streaming'
                ) {
                    return;
                }
                unset($this->pongTimers[$socket]);
                $decision = $this->livenessDecision($socket);
                $this->logLivenessReconnect($socket, 'pong_timeout', $decision);
                if ($decision === 'deferred_alive' || $decision === 'deferred_paused') {
                    // Still waiting for the pong: the healthy stop keeps requiring it.
                    $this->armPongTimer($socket, $pongGeneration);

                    return;
                }
                $this->beginPairedReconnect();
            },
        );
    }

    /**
     * The pong proves a round trip, but while it is missing, frames read from the
     * socket still prove the connection alive: production (run7) had a pong 180 s
     * late behind the backlog of a ~200 events/s burst, 256 frames read meanwhile,
     * and the reconnect then triggered exceeded the recovery budget. The connection
     * is dead only when nothing at all (data included) has been read for
     * PONG_TIMEOUT_SECONDS. A broken upstream cannot keep frames coming: without
     * our TCP acknowledgements OKX stops sending within one window, and the kernel
     * fails the socket after its retransmissions; an OKX-side close arrives as a
     * websocket close (1006 when abrupt). The remaining case, OKX ignoring our
     * requests while streaming, is capped: without any backlog (no admission pause
     * since the ping, queues below the resume threshold) a pong missing for
     * LIVENESS_PONG_CAP_SECONDS reconnects. A backlog has no cap: the pong is
     * legitimately behind it, and a reconnect could only lose it.
     *
     * Silence is ours, and proves nothing, while we do not read the socket: while
     * it is paused (only a full inbound buffer pauses a streaming socket), and until
     * it has been read again for PONG_TIMEOUT_SECONDS (readActiveSince: resumed,
     * reopened, or polled again after an event-loop gap).
     */
    private function livenessDecision(string $socket): string
    {
        $probe = $this->pingProbes[$socket] ?? null;
        if ($probe !== null && $this->socketAdmissionsPaused[$socket]) {
            return 'deferred_paused';
        }
        $lastInbound = $this->lastInboundAt[$socket] ?? null;
        $now = (float) $this->clock->now()->format('U.u');
        $quietSince = max(
            $lastInbound instanceof \DateTimeImmutable ? (float) $lastInbound->format('U.u') : 0.0,
            $this->readActiveSince[$socket] ?? 0.0,
        );
        if ($probe === null || $now - $quietSince >= OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS) {
            return 'reconnect';
        }
        if ($probe['frames'] === 0) {
            // Nothing read since the ping, but the socket has been read for less
            // than PONG_TIMEOUT_SECONDS: wait for a full window of active reading.
            return 'deferred_paused';
        }
        $backlog = $probe['backlog']
            || $this->inboundBufferedFrames() !== 0
            || $this->socketAdmissionsPaused['public']
            || $this->socketAdmissionsPaused['business']
            || $this->publicQueue->count() >= OkxPaperLivePolicy::RESUME_QUEUED_FRAMES
            || $this->businessQueue->count() >= OkxPaperLivePolicy::RESUME_QUEUED_FRAMES;
        if (!$backlog && $now - $probe['sent_at'] >= OkxPaperLivePolicy::LIVENESS_PONG_CAP_SECONDS) {
            return 'pong_cap';
        }

        return 'deferred_alive';
    }

    private function cancelHeartbeatTimers(): void
    {
        foreach ([...$this->heartbeatTimers, ...$this->pongTimers] as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->heartbeatTimers = [];
        $this->pongTimers = [];
        if ($this->stabilityTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->stabilityTimer);
            $this->stabilityTimer = null;
        }
        ++$this->heartbeatGenerations['public'];
        ++$this->heartbeatGenerations['business'];
        $this->heartbeatDeferredForBacklog = ['public' => false, 'business' => false];
        ++$this->pongGenerations['public'];
        ++$this->pongGenerations['business'];
        $this->pingProbes = [];
    }

    private function awaitReadiness(): void
    {
        $this->awaitSocketReadiness($this->publicQueue, false);
        $this->awaitSocketReadiness($this->businessQueue, true);
    }

    private function awaitSocketReadiness(
        OkxPaperPublicFrameQueue $queue,
        bool $business,
    ): void {
        $unobservableWakeRemaining = 1;
        while (!$this->socketReady($business)) {
            $beforeAsyncProgress = [
                $this->checkpoint->pendingTransition,
                $this->socketOpen,
                $this->publicQueue->count(),
                $this->publicQueue->bytes(),
                $this->businessQueue->count(),
                $this->businessQueue->bytes(),
            ];
            if ($queue->count() === 0) {
                $this->runNetworkLoop();
            }
            $framesToInspect = $queue->count();
            if ($framesToInspect === 0) {
                if ($beforeAsyncProgress !== [
                        $this->checkpoint->pendingTransition,
                        $this->socketOpen,
                        $this->publicQueue->count(),
                        $this->publicQueue->bytes(),
                        $this->businessQueue->count(),
                        $this->businessQueue->bytes(),
                    ]
                ) {
                    $unobservableWakeRemaining = 1;
                    continue;
                }
                if ($unobservableWakeRemaining > 0) {
                    --$unobservableWakeRemaining;
                    continue;
                }
                throw new OkxPaperLiveIntegrityException('okx_paper_public_reconnect_exhausted');
            }
            $acknowledged = false;
            $retained = [];
            $frames = $queue->frames();
            foreach ($frames as $index => $frame) {
                $message = $business
                    ? $this->decoder->decodeBusiness($frame)
                    : $this->decoder->decodePublic($frame);
                if (!isset($message['event'])) {
                    $retained[] = $frame;
                    continue;
                }
                if ($message['event'] === 'pong') {
                    continue;
                }
                $acknowledged = true;
                if ($this->acknowledgeReadinessMessage($message, $business)) {
                    $retained = [
                        ...$retained,
                        ...array_slice($frames, $index + 1),
                    ];
                    break;
                }
            }
            $queue->replace($retained);
            $this->persistStreamingQueues();
            $socket = $business ? 'business' : 'public';
            $this->pauseSocketAdmissionsAtHighWatermark($socket, $queue);
            $this->resumeSocketAdmissionsAfterDrain($socket, $queue);
            if (!$acknowledged) {
                $this->runNetworkLoop();
            }
        }
    }

    /** @param array<string, mixed> $message */
    private function acknowledgeReadinessMessage(array $message, bool $business): bool
    {
        if ($message['event'] !== 'subscribe' || !\is_array($message['arg'] ?? null)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_subscription_invalid');
        }
        $channel = $message['arg']['channel'] ?? null;
        $instrumentId = $message['arg']['instId'] ?? null;
        if (!\is_string($channel) || !\is_string($instrumentId)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_subscription_invalid');
        }
        $key = $channel . "\0" . $instrumentId;
        $acknowledgements = $business
            ? $this->businessAcknowledgements
            : $this->publicAcknowledgements;
        if (isset($acknowledgements[$key])) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_subscription_invalid');
        }
        if ($business) {
            $this->businessAcknowledgements[$key] = true;
            $this->subscriptions->acknowledgeBusiness($message['arg']);
        } else {
            $this->publicAcknowledgements[$key] = true;
            $this->subscriptions->acknowledgePublic($message['arg']);
        }

        return $this->socketReady($business);
    }

    private function socketReady(bool $business): bool
    {
        return $business
            ? $this->subscriptions->isBusinessReady()
            : $this->subscriptions->isPublicReady();
    }

    /**
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function nextStreamingEvents(): array
    {
        $this->refillFromInboundBuffers();
        if ($this->publicQueue->count() !== 0
            || $this->businessQueue->count() !== 0
        ) {
            return $this->nextQueuedEvents();
        }
        $this->runNetworkLoop();

        return [];
    }

    /**
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function nextQueuedEvents(): array
    {
        if ($this->deferredQueuedFailure instanceof \Throwable) {
            $failure = $this->deferredQueuedFailure;
            $this->deferredQueuedFailure = null;
            $this->throwQueuedFrameFailure($failure);
        }
        if ($this->loopPump !== null
            && $this->lastCompletedQueuedSocket === 'public'
            && $this->businessQueue->count() > 0
        ) {
            return $this->eventsFromQueuedFrame($this->businessQueue, true);
        }
        if ($this->loopPump !== null
            && $this->lastCompletedQueuedSocket === 'business'
            && $this->publicQueue->count() > 0
        ) {
            return $this->eventsFromQueuedFrame($this->publicQueue, false);
        }
        if ($this->publicQueue->count() > 0) {
            return $this->eventsFromQueuedFrame($this->publicQueue, false);
        }
        if ($this->businessQueue->count() > 0) {
            return $this->eventsFromQueuedFrame($this->businessQueue, true);
        }

        return [];
    }

    /**
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function eventsFromQueuedFrame(
        OkxPaperPublicFrameQueue $queue,
        bool $business,
    ): array {
        $this->preparingQueuedFrameBatch = true;
        $this->preparedBookFrontiers = [];
        try {
            return $this->prepareEventsFromQueuedFrames($queue, $business);
        } finally {
            $this->preparingQueuedFrameBatch = false;
            $this->preparedBookFrontiers = [];
        }
    }

    /**
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function prepareEventsFromQueuedFrames(
        OkxPaperPublicFrameQueue $queue,
        bool $business,
    ): array {
        $frames = array_slice(
            $queue->frames(),
            0,
            $this->durableFrameBatchingEnabled ? self::MAX_DURABLE_FRAME_BATCH : 1,
        );
        if ($frames === []) {
            return [];
        }
        $events = [];
        $framesConsumed = 0;
        $advertisedRows = 0;
        foreach ($frames as $frame) {
            $message = null;
            try {
                $message = $business
                    ? $this->decoder->decodeBusiness($frame)
                    : $this->decoder->decodePublic($frame);
                if ($framesConsumed > 0
                    && ($this->anchorsTradeJunction($message) || $this->anchorsCandleJunction($message))
                ) {
                    break;
                }
                $messageRows = $message['data'] ?? null;
                if ($framesConsumed > 0
                    && \is_array($messageRows)
                    && $advertisedRows + \count($messageRows)
                        > self::MAX_DURABLE_EVENT_BATCH
                ) {
                    break;
                }
                if (\is_array($messageRows)) {
                    $advertisedRows += \count($messageRows);
                }
                $frameEvents = $this->eventsFromMessage($message, $business);
            } catch (\Throwable $exception) {
                $this->logRejectedFrame($business ? 'business' : 'public', $frame, $exception);
                $bookGap = !$business
                    && $exception->getMessage() === 'okx_paper_book_sequence_gap'
                    && \is_array($message)
                    && $this->isBookUpdate($message);
                if ($framesConsumed > 0) {
                    if (!$bookGap) {
                        $this->deferredQueuedFailure = $exception;
                    }

                    break;
                }
                if ($bookGap) {
                    return $this->startBookResync($message);
                }

                $this->throwQueuedFrameFailure($exception);
            }
            ++$framesConsumed;
            array_push($events, ...$frameEvents);
        }
        if ($events === []) {
            $socket = $business ? 'business' : 'public';
            for ($index = 0; $index < $framesConsumed; ++$index) {
                $queue->dequeue();
            }
            $this->lastCompletedQueuedSocket = $socket;
            $this->persistStreamingQueues();
            $this->resumeSocketAdmissionsAfterDrain($socket, $queue);
            $this->rescheduleHeartbeatAfterQueueDrain(
                $socket,
                $queue,
            );
            $this->pumpNetworkLoop();
        } else {
            $this->activeQueuedSocket = $business ? 'business' : 'public';
            $this->activeQueuedEventsRemaining = \count($events);
            $this->activeQueuedFramesRemaining = $framesConsumed;
        }

        return $events;
    }

    private function throwQueuedFrameFailure(\Throwable $exception): never
    {
        if (\in_array($exception->getMessage(), [
            'market_event_identity_conflict',
            'market_data_gap_unresolved',
        ], true)) {
            $this->failTerminal($exception->getMessage(), $exception);
        }

        throw $exception;
    }

    private function completeActiveQueuedFrame(): void
    {
        if ($this->activeQueuedSocket === null) {
            return;
        }
        $socket = $this->activeQueuedSocket;
        $queue = $socket === 'public'
            ? $this->publicQueue
            : $this->businessQueue;
        for ($index = 0; $index < $this->activeQueuedFramesRemaining; ++$index) {
            $queue->dequeue();
        }
        $this->activeQueuedSocket = null;
        $this->lastCompletedQueuedSocket = $socket;
        $this->activeQueuedEventsRemaining = 0;
        $this->activeQueuedFramesRemaining = 0;
        $this->persistStreamingQueues();
        $this->resumeSocketAdmissionsAfterDrain($socket, $queue);
        $this->rescheduleHeartbeatAfterQueueDrain($socket, $queue);
        $this->pumpNetworkLoop();
    }

    private function pauseSocketAdmissionsAtHighWatermark(
        string $socket,
        OkxPaperPublicFrameQueue $queue,
    ): void {
        if (!$this->socketReady($socket === 'business')
            || $this->socketAdmissionsPaused[$socket]
            || !$queue->shouldPauseAdmissions()
            // While streaming, a full durable queue sends frames to the inbound buffer.
            || $this->inboundBufferingActive()
        ) {
            return;
        }
        $transport = $socket === 'public'
            ? $this->publicTransport
            : $this->businessTransport;
        if (!$transport instanceof OkxPaperPausableWebSocketTransportInterface) {
            return;
        }

        $transport->pause();
        $this->socketAdmissionsPaused[$socket] = true;
        foreach (array_keys($this->pingProbes) as $probedSocket) {
            // A pong waits behind this backlog: see livenessDecision().
            $this->pingProbes[$probedSocket]['backlog'] = true;
        }
    }

    private function resumeSocketAdmissionsAfterDrain(
        string $socket,
        OkxPaperPublicFrameQueue $queue,
    ): void {
        $this->refillFromInboundBuffers();
        if ($this->inboundBufferPaused[$socket]) {
            // Paused by the inbound buffer bound: read again once it is half empty.
            if ($this->inboundBufferedBytes() > intdiv($this->inboundBufferMaxBytes, 2)) {
                return;
            }
            $this->inboundBufferPaused[$socket] = false;
            $transport = $socket === 'public' ? $this->publicTransport : $this->businessTransport;
            if ($this->socketAdmissionsPaused[$socket]
                && $this->inboundBufferingActive()
                && $transport instanceof OkxPaperPausableWebSocketTransportInterface
            ) {
                $this->socketAdmissionsPaused[$socket] = false;
                $this->markReadActive($socket);
                $transport->resume();

                return;
            }
        }
        if ($this->healthyStopRequested
            || !$this->socketAdmissionsPaused[$socket]
            || !$queue->canResumeAdmissions()
        ) {
            return;
        }
        $transport = $socket === 'public'
            ? $this->publicTransport
            : $this->businessTransport;
        if (!$transport instanceof OkxPaperPausableWebSocketTransportInterface) {
            $this->socketAdmissionsPaused[$socket] = false;

            return;
        }

        $this->socketAdmissionsPaused[$socket] = false;
        $this->markReadActive($socket);
        $transport->resume();
    }

    /**
     * While streaming, only a full inbound buffer pauses a socket, and each socket
     * alone: one still paused from before (its durable queue at the high watermark
     * during a recovery, whose drain may not resume it) is read again at once,
     * whatever its queue and the other socket's. Called before every poll.
     */
    private function resumeSocketsForInboundBuffering(): void
    {
        if (!$this->inboundBufferingActive()) {
            return;
        }
        foreach (['public' => $this->publicTransport, 'business' => $this->businessTransport] as $socket => $transport) {
            if (!$this->socketAdmissionsPaused[$socket]
                || $this->inboundBufferPaused[$socket]
                || !$transport instanceof OkxPaperPausableWebSocketTransportInterface
            ) {
                continue;
            }
            $this->socketAdmissionsPaused[$socket] = false;
            $this->markReadActive($socket);
            $transport->resume();
        }
    }

    private function markReadActive(string $socket): void
    {
        if ($socket === 'public' || $socket === 'business') {
            $this->readActiveSince[$socket] = (float) $this->clock->now()->format('U.u');
        }
    }

    /**
     * Before each event-loop poll: the pings' poll statistics, and after a gap
     * longer than LIVENESS_POLL_GAP_SECONDS (the sockets were not read meanwhile)
     * a new window of active reading for both sockets.
     */
    private function noteNetworkPoll(): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        $gap = $this->lastNetworkPollAt === null ? 0.0 : max(0.0, $now - $this->lastNetworkPollAt);
        foreach ($this->pingProbes as $socket => $probe) {
            $this->pingProbes[$socket]['polls'] = $probe['polls'] + 1;
            $this->pingProbes[$socket]['max_poll_gap_s'] = max($probe['max_poll_gap_s'], round($gap, 3));
        }
        if ($gap > OkxPaperLivePolicy::LIVENESS_POLL_GAP_SECONDS) {
            foreach ($this->readActiveSince as $socket => $since) {
                if ($since !== null) {
                    $this->readActiveSince[$socket] = $now;
                }
            }
        }
    }

    private function rescheduleHeartbeatAfterQueueDrain(
        string $socket,
        OkxPaperPublicFrameQueue $queue,
    ): void {
        if ($queue->count() !== 0
            || $this->checkpoint->phase !== 'streaming'
            || !$this->heartbeatDeferredForBacklog[$socket]
            || !isset($this->heartbeatTimers[$socket])
            || isset($this->pongTimers[$socket])
        ) {
            return;
        }
        $this->heartbeatDeferredForBacklog[$socket] = false;
        $lastInbound = $this->lastInboundAt[$socket];
        $delay = 0.0;
        if ($lastInbound instanceof \DateTimeImmutable) {
            $deadline = $lastInbound->modify(sprintf(
                '+%d seconds',
                (int) OkxPaperLivePolicy::HEARTBEAT_IDLE_SECONDS,
            ));
            $delay = max(
                0.0,
                (float) $deadline->format('U.u')
                    - (float) $this->clock->now()->format('U.u'),
            );
        }
        $this->armHeartbeatTimer($socket, $delay);
    }

    private function persistStreamingQueues(): void
    {
        if (\in_array($this->checkpoint->phase, ['complete', 'failed', 'stopping'], true)) {
            $this->streamingQueuesDirty = false;

            return;
        }
        try {
            $this->checkpoint = $this->checkpointStore->saveStreamingQueues(
                $this->checkpoint,
                $this->publicQueue->frames(),
                $this->businessQueue->frames(),
            );
        } catch (\Throwable $failure) {
            $this->reconcileAfterCheckpointWriteFailure($failure);
        }
        $this->streamingQueuesDirty = false;
        $this->lastStreamingQueueSaveAt = (float) $this->clock->now()->format('U.u');
    }

    /**
     * Plain streaming keeps reading the sockets during a backlog: frames that find
     * the durable queue full wait in memory (OkxPaperInboundFrameBuffer) instead of
     * pausing the socket, so OKX never sees a slow consumer (production: closed
     * with 1006 after minutes of pause at ~400 s of lag), and pongs are read at
     * once. The buffer refills the durable queue as it drains; above its bound the
     * socket pauses as before. Recovery and healthy-stop phases keep the durable
     * queue and its pause only.
     */
    private function inboundBufferingActive(): bool
    {
        return $this->checkpoint->phase === 'streaming'
            && !$this->stopped
            && !$this->healthyStopRequested
            && !$this->checkpoint->healthyStop['requested'];
    }

    private function bufferInboundFrame(string $socket, string $frame): void
    {
        $this->inboundBuffers[$socket]->append($frame, (float) $this->clock->now()->format('U.u'));
        foreach (array_keys($this->pingProbes) as $probedSocket) {
            // A pong may wait behind this backlog: see livenessDecision().
            $this->pingProbes[$probedSocket]['backlog'] = true;
        }
        if ($this->inboundBufferedBytes() > 2 * $this->inboundBufferMaxBytes) {
            // Frames keep coming although the socket was paused at the bound: fail
            // closed, like the durable queue at its hard limit.
            throw new OkxPaperLiveIntegrityException('market_data_backpressure_exhausted');
        }
        if ($this->inboundBufferedBytes() >= $this->inboundBufferMaxBytes
            && !$this->socketAdmissionsPaused[$socket]
        ) {
            $transport = $socket === 'public' ? $this->publicTransport : $this->businessTransport;
            if ($transport instanceof OkxPaperPausableWebSocketTransportInterface) {
                $transport->pause();
                $this->socketAdmissionsPaused[$socket] = true;
                $this->inboundBufferPaused[$socket] = true;
                foreach (array_keys($this->pingProbes) as $probedSocket) {
                    $this->pingProbes[$probedSocket]['backlog'] = true;
                }
                $this->logInboundBuffer('okx_paper_public_inbound_buffer_full');
            }
        }
        $this->logInboundBuffer();
    }

    private function refillFromInboundBuffers(): void
    {
        foreach (['public' => $this->publicQueue, 'business' => $this->businessQueue] as $socket => $queue) {
            $buffer = $this->inboundBuffers[$socket];
            while ($buffer->count() !== 0 && !$queue->shouldPauseAdmissions()) {
                $frame = $buffer->shift();
                if ($frame === null) {
                    break;
                }
                $queue->enqueue($frame);
                $this->streamingQueuesDirty = true;
            }
        }
        $this->logInboundBuffer();
    }

    private function discardInboundBuffers(): void
    {
        if ($this->inboundBufferedFrames() !== 0) {
            $this->logInboundBuffer('okx_paper_public_inbound_buffer_discarded');
        }
        foreach ($this->inboundBuffers as $buffer) {
            $buffer->clear();
        }
        $this->inboundBufferPaused = ['public' => false, 'business' => false];
    }

    private function inboundBufferedFrames(): int
    {
        return $this->inboundBuffers['public']->count() + $this->inboundBuffers['business']->count();
    }

    private function inboundBufferedBytes(): int
    {
        return $this->inboundBuffers['public']->bytes() + $this->inboundBuffers['business']->bytes();
    }

    /** Occupancy of the inbound buffer, every 10 s while it is not empty. */
    private function logInboundBuffer(string $message = 'okx_paper_public_inbound_buffer'): void
    {
        try {
            $now = (float) $this->clock->now()->format('U.u');
            if ($message === 'okx_paper_public_inbound_buffer') {
                if ($this->inboundBufferedFrames() === 0) {
                    if ($this->inboundBufferLoggedAt !== null) {
                        $this->inboundBufferLoggedAt = null;
                        $this->warn('okx_paper_public_inbound_buffer_drained', []);
                    }

                    return;
                }
                if ($this->inboundBufferLoggedAt !== null
                    && $now - $this->inboundBufferLoggedAt < self::INBOUND_BUFFER_LOG_SECONDS
                ) {
                    return;
                }
                $oldestAt = min(array_filter([
                    $this->inboundBuffers['public']->oldestReceivedAt(),
                    $this->inboundBuffers['business']->oldestReceivedAt(),
                ], static fn (?float $at): bool => $at !== null) ?: [$now]);
                if ($this->inboundBufferLoggedAt === null
                    && $now - $oldestAt < self::INBOUND_BUFFER_LOG_SECONDS
                    && $this->inboundBufferedBytes() < 1_048_576
                ) {
                    // A brief overflow of the durable queue: not a backlog worth a line.
                    return;
                }
                $this->inboundBufferLoggedAt = $now;
            }
            $oldest = array_filter([
                $this->inboundBuffers['public']->oldestReceivedAt(),
                $this->inboundBuffers['business']->oldestReceivedAt(),
            ], static fn (?float $at): bool => $at !== null);
            $this->warn($message, [
                'public_frames' => $this->inboundBuffers['public']->count(),
                'business_frames' => $this->inboundBuffers['business']->count(),
                'bytes' => $this->inboundBufferedBytes(),
                'max_bytes' => $this->inboundBufferMaxBytes,
                'oldest_age_s' => $oldest === [] ? null : round($now - min($oldest), 3),
                'paused' => $this->inboundBufferPaused,
            ]);
        } catch (\Throwable) {
            // Diagnostics never fail the capture.
        }
    }

    /**
     * While streaming, an admitted frame not yet persisted is in the position of a
     * frame still in the socket buffer: a restart reconnects and recovers it (REST
     * overlap for trades and candles, a new authority for books; see
     * beginPairedReconnect() in eventFlow()). Its persistence is therefore batched:
     * at most every STREAMING_QUEUE_SAVE_INTERVAL_SECONDS, and at once whenever a
     * batch leaves the queue (completeActiveQueuedFrame()), so a persisted queue
     * never holds frames of an acknowledged batch longer than before. Anything but
     * plain streaming (recovery, a healthy stop, which resumes without reconnect)
     * persists every admission, as before.
     */
    private function streamingQueueSaveDeferrable(): bool
    {
        return $this->checkpoint->phase === 'streaming'
            && $this->checkpoint->pendingTransition === null
            && !$this->healthyStopRequested
            && !$this->checkpoint->healthyStop['requested']
            && $this->lastStreamingQueueSaveAt !== null
            && (float) $this->clock->now()->format('U.u') - $this->lastStreamingQueueSaveAt
                < OkxPaperLivePolicy::STREAMING_QUEUE_SAVE_INTERVAL_SECONDS;
    }

    private function pumpNetworkLoop(): void
    {
        $this->networkTickActive = true;
        try {
            $this->noteNetworkPoll();
            $this->resumeSocketsForInboundBuffering();
            $this->loopPump?->pump();
        } finally {
            $this->networkTickActive = false;
            $this->lastNetworkPollAt = (float) $this->clock->now()->format('U.u');
        }
        $this->persistDirtyStreamingQueues();
    }

    private function runNetworkLoop(): void
    {
        $this->networkTickActive = true;
        try {
            $this->noteNetworkPoll();
            $this->loop->run();
        } finally {
            $this->networkTickActive = false;
            $this->lastNetworkPollAt = (float) $this->clock->now()->format('U.u');
        }
        $this->persistDirtyStreamingQueues();
    }

    /**
     * @param bool $force leaving plain streaming (a book resync, a reconnect, a
     *                    healthy stop): the durable state holds every admitted frame again
     */
    private function persistDirtyStreamingQueues(bool $force = false): void
    {
        if (!$this->streamingQueuesDirty || (!$force && $this->streamingQueueSaveDeferrable())) {
            return;
        }
        try {
            $this->persistStreamingQueues();
        } catch (OkxPaperLiveIntegrityException $exception) {
            if ($exception->getMessage() === 'market_data_backpressure_exhausted') {
                $this->failTerminal('market_data_backpressure_exhausted');
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $message */
    private function isBookUpdate(array $message): bool
    {
        return ($message['arg']['channel'] ?? null) === 'books'
            && ($message['action'] ?? null) === 'update';
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function startBookResync(array $message): array
    {
        $instrumentId = $message['arg']['instId'] ?? null;
        if (!\is_string($instrumentId)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
        }
        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        $stream = $symbol . '/ws/top_of_book';
        if ($this->checkpoint->resyncBySymbol[$symbol] !== null) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $frontier = $this->checkpoint->streamFrontiers[$stream] ?? null;
        $sourceSequence = $this->books[$instrumentId]->sourceSequence();
        if (!$frontier instanceof OkxPaperStreamFrontier
            || $sourceSequence === null
            || !hash_equals($frontier->sourceIdentity, $sourceSequence)
        ) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }

        if ($this->inboundBufferedFrames() !== 0) {
            // The resync would continue from the durable queue while newer frames
            // wait in memory: recover everything through a paired reconnect.
            $this->warn('okx_paper_public_book_gap_reconnect', [
                'symbol' => $symbol,
                'inbound_buffered_frames' => $this->inboundBufferedFrames(),
            ]);
            $this->beginPairedReconnect();

            return [];
        }
        $this->persistDirtyStreamingQueues(true);
        $deadline = $this->clock->now()->modify(sprintf(
            '+%d seconds',
            (int) OkxPaperLivePolicy::RESYNC_ATTEMPT_TIMEOUT_SECONDS,
        ))->setTimezone(new \DateTimeZone('UTC'));
        $this->ordinals->reserveGap('okx/' . $symbol . '/top_of_book');
        $state = $this->checkpoint->toArray();
        $state['ordinal_state'] = $this->ordinals->snapshot();
        $state['remaining_symbols'] = [$symbol];
        $state['remaining_boundaries'] = [[
            'symbol' => $symbol,
            'reason' => 'sequence_gap',
        ]];
        ++$state['source_epochs'][$symbol];
        $state['resync_by_symbol'][$symbol] = [
            'attempt' => 1,
            'frontier' => $frontier->toArray(),
            'source_sequence' => $sourceSequence,
            'deadline_at' => $deadline->format('Y-m-d\TH:i:s.u\Z'),
            'policy' => 'book_seq_overlap_v1',
            'book_snapshot' => null,
        ];
        if ($this->checkpoint->streamingQueueRef === null) {
            $state['resync_by_symbol'][$symbol]['queued_public_frames'] =
                $this->publicQueue->frames();
            $state['resync_by_symbol'][$symbol]['queued_business_frames'] =
                $this->businessQueue->frames();
        }
        $timerTransition = [
            'kind' => 'timer_schedule',
            'symbol' => $symbol,
            'stream' => $stream,
            'stage' => 'resync_timeout',
        ];
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $candidate,
            'resyncing',
            $timerTransition,
        );
        $this->scheduleResyncTimer($symbol);

        $restTransition = $this->restTransition(
            $symbol,
            $symbol . '/rest/top_of_book',
            'order_book',
        );
        $this->ensureTransition('resyncing', $restTransition);

        return $this->runBookResyncAttempts($symbol, $instrumentId, $restTransition);
    }

    /**
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function resumePersistedBookResync(): array
    {
        $symbol = null;
        foreach ($this->checkpoint->resyncBySymbol as $candidate => $resync) {
            if (\is_array($resync) && $resync['policy'] === 'book_seq_overlap_v1') {
                $symbol = $candidate;
                break;
            }
        }
        if (!\is_string($symbol)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }
        $this->requiresOverlap[$symbol . '/rest/top_of_book'] = false;
        $resync = $this->checkpoint->resyncBySymbol[$symbol];
        $deadline = new \DateTimeImmutable($resync['deadline_at']);
        $remaining = max(
            0.0,
            (float) $deadline->format('U.u') - (float) $this->clock->now()->format('U.u'),
        );
        if ($remaining > 0.0) {
            $this->scheduleResyncTimer($symbol, $remaining);
        }
        $transition = $this->restTransition(
            $symbol,
            $symbol . '/rest/top_of_book',
            'order_book',
        );
        if (($this->checkpoint->pendingTransition['stage'] ?? null) === 'resync_timeout') {
            $this->ensureTransition('resyncing', $transition);
        } elseif ($this->checkpoint->pendingTransition !== $transition) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }

        return $this->runBookResyncAttempts(
            $symbol,
            $this->instruments->nativeInstrumentId($symbol),
            $transition,
        );
    }

    /**
     * @param array<string, mixed> $restTransition
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function runBookResyncAttempts(
        string $symbol,
        string $instrumentId,
        array $restTransition,
    ): array {
        while (true) {
            try {
                return $this->bookResyncAttempt(
                    $symbol,
                    $instrumentId,
                    $restTransition,
                );
            } catch (\Throwable $exception) {
                if ($exception->getMessage()
                    === 'okx_paper_live_checkpoint_write_failed'
                ) {
                    throw $exception;
                }
                if ($this->isIdentityConflict($exception)) {
                    $this->failTerminal('market_event_identity_conflict');
                }
                $attempt = $this->checkpoint->resyncBySymbol[$symbol]['attempt'] ?? null;
                if (!\is_int($attempt) || $attempt >= OkxPaperLivePolicy::MAX_RESYNC_ATTEMPTS) {
                    $this->failTerminal('market_data_gap_unresolved');
                }
                $this->prepareNextBookResyncAttempt($symbol, $restTransition);
            }
        }
    }

    /**
     * @param array<string, mixed> $restTransition
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function bookResyncAttempt(
        string $symbol,
        string $instrumentId,
        array $restTransition,
    ): array {
        $resync = $this->checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $deadline = new \DateTimeImmutable($resync['deadline_at']);
        $generation = $this->resyncGenerations[$symbol];
        if ($this->resyncAttemptExpired($symbol, $generation, $deadline)) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $rows = $this->restClient->orderBook($instrumentId, 400);
        if (\count($rows) !== 1 || !\is_array($rows[0])) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        // @phpstan-ignore if.alwaysFalse (the synchronous REST call can cross the deadline)
        if ($this->resyncAttemptExpired($symbol, $generation, $deadline)) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $replacement = new OkxPaperOrderBookMaterializer();
        $replacementState = $replacement->replaceSnapshot($rows[0]);
        $this->requireQueuedBookOverlap(
            $instrumentId,
            $replacementState->sourceSequence,
        );
        $this->persistDurableBookRecovery($symbol, $rows[0], $restTransition);
        $state = $this->books[$instrumentId]->replaceSnapshot($rows[0]);
        $events = $this->acceptedBookEvents(
            $symbol . '/rest/top_of_book',
            $instrumentId,
            $state,
            'rest_resync_snapshot',
            $this->checkpoint->sourceEpochs[$symbol],
        );
        if (\count($events) !== 1) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $this->nextEventStream = $symbol . '/rest/top_of_book';
        $this->nextEventTransition = $restTransition;

        return $events;
    }

    private function resyncAttemptExpired(
        string $symbol,
        int $generation,
        \DateTimeImmutable $deadline,
    ): bool {
        return ($this->expiredResyncGenerations[$symbol] ?? null) === $generation
            || $this->clock->now() >= $deadline;
    }

    private function scheduleResyncTimer(
        string $symbol,
        float $delay = OkxPaperLivePolicy::RESYNC_ATTEMPT_TIMEOUT_SECONDS,
    ): void
    {
        $generation = ++$this->resyncGenerations[$symbol];
        unset($this->expiredResyncGenerations[$symbol]);
        $this->resyncTimers[$symbol] = $this->loop->addTimer(
            $delay,
            function () use ($symbol, $generation): void {
                if ($generation !== $this->resyncGenerations[$symbol]) {
                    return;
                }
                $this->expiredResyncGenerations[$symbol] = $generation;
                $this->loop->stop();
            },
        );
    }

    /** @param array<string, mixed> $restTransition */
    private function prepareNextBookResyncAttempt(
        string $symbol,
        array $restTransition,
    ): void {
        $this->cancelResyncTimer($symbol);
        $state = $this->checkpoint->toArray();
        $resync = $state['resync_by_symbol'][$symbol] ?? null;
        if (!\is_array($resync)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        ++$resync['attempt'];
        $resync['deadline_at'] = $this->clock->now()->modify(sprintf(
            '+%d seconds',
            (int) OkxPaperLivePolicy::RESYNC_ATTEMPT_TIMEOUT_SECONDS,
        ))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        $state['resync_by_symbol'][$symbol] = $resync;
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $candidate,
            'resyncing',
            $restTransition,
        );
        $this->scheduleResyncTimer($symbol);
    }

    private function cancelResyncTimer(string $symbol): void
    {
        if (isset($this->resyncTimers[$symbol])) {
            $this->loop->cancelTimer($this->resyncTimers[$symbol]);
            unset($this->resyncTimers[$symbol]);
        }
        ++$this->resyncGenerations[$symbol];
        unset($this->expiredResyncGenerations[$symbol]);
    }

    private function failTerminal(string $reason, ?\Throwable $previous = null): never
    {
        $this->logTerminalFailure($reason, $previous);
        ++$this->connectionGeneration;
        $this->stopped = true;
        $this->checkpoint = $this->checkpointStore->fail($this->checkpoint, $reason);
        $this->publicQueue->clear();
        $this->businessQueue->clear();
        foreach ($this->inboundBuffers as $buffer) {
            $buffer->clear();
        }
        $this->cancelHeartbeatTimers();
        while ($this->checkpoint->pendingTransition !== null) {
            $transition = $this->checkpoint->pendingTransition;
            $this->executeCleanupTransition($transition);
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->checkpoint,
                'failed',
                $this->nextCleanupTransition($transition),
            );
        }
        throw new OkxPaperLiveIntegrityException($reason, 0, $previous);
    }

    private function isIdentityConflict(\Throwable $exception): bool
    {
        return \in_array($exception->getMessage(), [
            'market_event_identity_conflict',
            'okx_paper_natural_identity_conflict',
            'okx_paper_source_ordinal_transaction_invalid',
        ], true);
    }

    private function terminalPublicFailureReason(\Throwable $exception): ?string
    {
        if ($this->isIdentityConflict($exception)) {
            return 'market_event_identity_conflict';
        }

        return \in_array($exception->getMessage(), [
            'market_data_backpressure_exhausted',
            'market_data_gap_unresolved',
            'okx_paper_book_sequence_invalid',
            'okx_paper_book_snapshot_required',
            'okx_paper_materialized_order_book_invalid',
            'okx_paper_public_acquisition_disabled',
            'okx_paper_public_healthy_stop_invalid',
            'okx_paper_public_message_invalid',
            'okx_paper_public_protocol_error',
            'okx_paper_public_reconnect_exhausted',
            'okx_paper_public_response_invalid',
            'okx_paper_public_subscription_invalid',
            'okx_paper_public_ws_frame_too_large',
        ], true)
            ? $exception->getMessage()
            : null;
    }

    /**
     * @param array<array-key, mixed> $snapshot
     * @param array<string, mixed>     $transition
     */
    private function persistDurableBookRecovery(
        string $symbol,
        array $snapshot,
        array $transition,
    ): void {
        foreach (['seqId', 'prevSeqId'] as $sequenceField) {
            if (\is_int($snapshot[$sequenceField] ?? null)) {
                $snapshot[$sequenceField] = (string) $snapshot[$sequenceField];
            }
        }
        try {
            $this->checkpoint =
                $this->checkpointStore->saveBookRecoverySnapshotAndStreamingQueues(
                    $this->checkpoint,
                    $symbol,
                    $snapshot,
                    $transition,
                    $this->publicQueue->frames(),
                    $this->businessQueue->frames(),
                );
        } catch (\Throwable $failure) {
            $this->reconcileAfterCheckpointWriteFailure($failure);
        }
    }

    private function reconcileAfterCheckpointWriteFailure(
        \Throwable $failure,
    ): never {
        if ($failure->getMessage() !== 'okx_paper_live_checkpoint_write_failed') {
            throw $failure;
        }
        try {
            $visible = $this->checkpointStore->loadOrCreate(
                $this->checkpoint->datasetId,
                $this->checkpoint->configurationSha256,
            );
            $queues = $this->checkpointStore->streamingQueues($visible);
            $this->publicQueue->replace($queues['public']);
            $this->businessQueue->replace($queues['business']);
            foreach ($visible->resyncBySymbol as $symbol => $resync) {
                if (!\is_array($resync)
                    || $resync['policy'] !== 'book_seq_overlap_v1'
                    || !\is_array($resync['book_snapshot'] ?? null)
                ) {
                    continue;
                }
                $this->books[$this->instruments->nativeInstrumentId($symbol)]
                    ->replaceSnapshot($resync['book_snapshot']);
            }
            $this->checkpoint = $visible;
        } catch (\Throwable $reconciliationFailure) {
            throw new OkxPaperLiveIntegrityException(
                'okx_paper_live_checkpoint_write_failed',
                0,
                $reconciliationFailure,
            );
        }

        throw $failure;
    }

    private function connectResumedBookRecoverySockets(): void
    {
        $continuation = $this->persistedBookResyncContinuation();
        $actions = [
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe'],
        ];
        $start = array_search($this->checkpoint->pendingTransition, $actions, true);
        if (!\is_int($start) || $start > 0) {
            $this->ensureTransition('resyncing', $actions[0]);
        }
        foreach ($actions as $action) {
            $this->ensureTransition('resyncing', $action);
            if ($action['kind'] === 'transport_connect') {
                $public = $action['stream'] === 'public';
                if (!$this->connectSocket(
                    $action['stream'],
                    $public
                        ? $this->config->webSocketUri
                        : $this->config->businessWebSocketUri,
                    $public ? $this->publicTransport : $this->businessTransport,
                    $public ? $this->publicQueue : $this->businessQueue,
                    false,
                )) {
                    return;
                }

                continue;
            }
            $public = $action['stream'] === 'public';
            ($public ? $this->publicTransport : $this->businessTransport)->send([
                'op' => 'subscribe',
                'args' => $public
                    ? $this->subscriptions->publicArguments()
                    : $this->subscriptions->businessArguments(),
            ]);
        }
        $this->awaitReadiness();
        if ($continuation === null) {
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->checkpoint,
                'resyncing',
                null,
            );

            return;
        }
        $this->ensureTransition('resyncing', $continuation);
    }

    /** @return array{kind: string, symbol: string, stream: string, stage: string}|null */
    private function persistedBookResyncContinuation(): ?array
    {
        foreach ($this->checkpoint->resyncBySymbol as $symbol => $resync) {
            if (!\is_array($resync) || $resync['policy'] !== 'book_seq_overlap_v1') {
                continue;
            }
            $frontier = $this->checkpoint->streamFrontiers[
                $symbol . '/rest/top_of_book'
            ] ?? null;
            if ($frontier instanceof OkxPaperStreamFrontier
                && \is_array($resync['book_snapshot'] ?? null)
                && !hash_equals($frontier->sourceIdentity, $resync['source_sequence'])
            ) {
                return null;
            }

            return $this->restTransition(
                $symbol,
                $symbol . '/rest/top_of_book',
                'order_book',
            );
        }

        throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
    }

    /** @param array<string, mixed> $transition */
    private function executeCleanupTransition(array $transition): void
    {
        $kind = $transition['kind'] ?? null;
        $stream = $transition['stream'] ?? null;
        $symbol = $transition['symbol'] ?? null;
        if ($kind === 'transport_close' && $stream === 'public') {
            $this->socketOpen['public'] = false;
            $this->publicTransport->close();

            return;
        }
        if ($kind === 'transport_close' && $stream === 'business') {
            $this->socketOpen['business'] = false;
            $this->businessTransport->close();

            return;
        }
        if ($kind === 'timer_cancel' && \is_string($symbol)) {
            $this->cancelResyncTimer($symbol);

            return;
        }
        if ($kind === 'timer_cancel' && $symbol === null) {
            if ($this->reconnectTimer instanceof TimerInterface) {
                $this->loop->cancelTimer($this->reconnectTimer);
                $this->reconnectTimer = null;
            }
            $this->cancelHeartbeatTimers();

            return;
        }
        if ($kind === 'loop_stop') {
            $this->loop->stop();
        }
    }

    /**
     * @param array<string, mixed> $transition
     * @return array<string, mixed>|null
     */
    private function nextCleanupTransition(array $transition): ?array
    {
        $actions = [
            ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'public', 'stage' => 'close'],
            ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'business', 'stage' => 'close'],
            ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'cancel_reconnect_timer'],
            ['kind' => 'timer_cancel', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
            ['kind' => 'timer_cancel', 'symbol' => 'ETHUSDT', 'stream' => 'ETHUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
            ['kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'stop_loop'],
        ];
        $position = array_search($transition, $actions, true);
        if (!\is_int($position)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        }

        return $actions[$position + 1] ?? null;
    }

    private function requireQueuedBookOverlap(
        string $instrumentId,
        string $snapshotSequence,
    ): void {
        if (!$this->filterQueuedBookOverlap($instrumentId, $snapshotSequence)) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
    }

    private function filterQueuedBookOverlap(
        string $instrumentId,
        string $snapshotSequence,
    ): bool {
        $retainedFrames = [];
        $found = false;
        $expectedPreviousSequence = $snapshotSequence;
        foreach ($this->publicQueue->frames() as $frame) {
            $message = $this->decoder->decodePublic($frame);
            $isTargetBook = ($message['arg']['channel'] ?? null) === 'books'
                && ($message['arg']['instId'] ?? null) === $instrumentId;
            if ($isTargetBook && !$this->isBookUpdate($message)) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            if (!$isTargetBook) {
                $retainedFrames[] = $frame;

                continue;
            }
            $rows = $message['data'] ?? null;
            if (!\is_array($rows) || !array_is_list($rows) || $rows === []) {
                throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
            }
            $retainedRows = [];
            foreach ($rows as $row) {
                $sequence = \is_array($row) ? self::queuedBookSequence($row['seqId'] ?? null) : null;
                $previousSequence = \is_array($row)
                    ? self::queuedBookSequence($row['prevSeqId'] ?? null)
                    : null;
                if ($sequence === null || $previousSequence === null) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                if (self::compareUnsigned($sequence, $snapshotSequence) <= 0) {
                    continue;
                }
                if (!hash_equals($expectedPreviousSequence, $previousSequence)) {
                    return false;
                }
                $found = true;
                $expectedPreviousSequence = $sequence;
                $retainedRows[] = $row;
            }
            if ($retainedRows !== []) {
                $message['data'] = $retainedRows;
                $retainedFrames[] = json_encode($message, \JSON_THROW_ON_ERROR);
            }
        }
        $this->publicQueue->replace($retainedFrames);

        return $found;
    }

    /**
     * OKX pushes websocket book sequences as JSON integers (the REST snapshot and
     * the test fixtures carry strings): both are accepted, as the materializer does.
     */
    private static function queuedBookSequence(mixed $value): ?string
    {
        if (\is_int($value) && $value >= 0) {
            return (string) $value;
        }

        return \is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1
            ? $value
            : null;
    }

    private function hasAcknowledgedResyncSnapshot(): bool
    {
        if ($this->checkpoint->phase !== 'resyncing'
            || $this->checkpoint->pendingEvent !== null
            || $this->checkpoint->pendingTransition !== null
        ) {
            return false;
        }
        foreach ($this->checkpoint->resyncBySymbol as $symbol => $resync) {
            if (!\is_array($resync) || $resync['policy'] !== 'book_seq_overlap_v1') {
                continue;
            }
            $frontier = $this->checkpoint->streamFrontiers[
                $symbol . '/rest/top_of_book'
            ] ?? null;
            if ($frontier instanceof OkxPaperStreamFrontier
                && !hash_equals($frontier->sourceIdentity, $resync['source_sequence'])
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function emitResyncBoundary(): \Generator
    {
        $boundary = $this->checkpoint->remainingBoundaries[0] ?? null;
        if ($boundary === null || $boundary['reason'] !== 'sequence_gap') {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $symbol = $boundary['symbol'];
        $stream = $symbol . '/ws/top_of_book';
        $cancelTransition = [
            'kind' => 'timer_cancel',
            'symbol' => $symbol,
            'stream' => $stream,
            'stage' => 'cancel_resync_timer',
        ];
        $this->ensureTransition('resyncing', $cancelTransition);
        $this->cancelResyncTimer($symbol);

        $boundaryTransition = [
            'kind' => 'emit_boundary',
            'symbol' => $symbol,
            'stream' => $symbol . '/control/snapshot_boundary',
            'stage' => 'sequence_gap',
        ];
        $this->ensureTransition('resyncing', $boundaryTransition);
        $bookFrontier = $this->checkpoint->streamFrontiers[
            $symbol . '/rest/top_of_book'
        ] ?? null;
        if (!$bookFrontier instanceof OkxPaperStreamFrontier) {
            throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
        }
        $event = $this->normalizer->snapshotBoundary(
            $this->instruments->nativeInstrumentId($symbol),
            'sequence_gap',
            $this->checkpoint->sourceEpochs[$symbol],
            $bookFrontier->sourceIdentity,
        );
        $this->checkpoint = $this->checkpointStore->savePending(
            $this->checkpoint,
            $event,
            $this->ordinals->snapshot(),
            null,
        );
        yield $this->checkpoint->pendingEvent
            ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
        $this->assertPendingWasAcknowledged();
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array{
     *     event: PaperMarketEvent,
     *     frontier: OkxPaperStreamFrontier,
     *     ordinal_state: array<string, mixed>
     * }>
     */
    private function eventsFromMessage(array $message, bool $business): array
    {
        if (isset($message['event'])) {
            if ($message['event'] === 'pong') {
                return [];
            }
            if ($message['event'] === 'notice') {
                $this->warn('okx_paper_public_service_notice', [
                    'socket' => $business ? 'business' : 'public',
                    'notice_code' => $message['code'] ?? null,
                    'notice_msg' => OkxPaperLiveDiagnostics::text(
                        \is_string($message['msg'] ?? null) ? $message['msg'] : null,
                    ),
                ]);
                // OKX closes this connection about 60 s later for a service
                // upgrade: reconnect first, through the usual gap-free recovery.
                $this->serviceNoticeReconnectRequested = true;

                return [];
            }

            throw new OkxPaperLiveIntegrityException('okx_paper_public_subscription_invalid');
        }
        $argument = $message['arg'] ?? null;
        $rows = $message['data'] ?? null;
        if (!\is_array($argument)
            || !\is_string($argument['channel'] ?? null)
            || !\is_string($argument['instId'] ?? null)
            || !\is_array($rows)
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
        }
        $channel = $argument['channel'];
        $instrumentId = $argument['instId'];
        $symbol = $this->instruments->normalizedSymbol($instrumentId);
        if ($channel === 'trades') {
            foreach ($rows as $row) {
                if (!\is_array($row) || ($row['instId'] ?? null) !== $instrumentId) {
                    throw new OkxPaperLiveIntegrityException(
                        'okx_paper_public_message_invalid',
                    );
                }
            }
            $stream = $symbol . '/ws/public_trade';
            if (isset($this->tradeJunctions[$symbol])) {
                $rows = $this->rowsAfterTradeJunction($symbol, $rows);
                if ($rows === []) {
                    return [];
                }
            }

            return $this->acceptedEvents(
                $stream,
                $rows,
                static fn (
                    array $row,
                    OkxPaperMarketEventNormalizer $normalizer,
                ): PaperMarketEvent => $normalizer->webSocketTrade($row),
                fn (array $row): OkxPaperStreamFrontier => $this->tradeFrontier($row),
            );
        }
        if (str_starts_with($channel, 'candle')) {
            $stream = $symbol . '/ws/' . str_replace('candle', 'candle_', $channel);
            $bar = substr($channel, \strlen('candle'));
            if (isset($this->candleJunctions[$symbol . '/' . $bar])) {
                $rows = $this->rowsAfterCandleJunction($symbol, $bar, $instrumentId, $channel, $rows);
                if ($rows === []) {
                    return [];
                }
            }

            return $this->acceptedEvents(
                $stream,
                $rows,
                fn (array $row, OkxPaperMarketEventNormalizer $normalizer): ?PaperMarketEvent => $normalizer
                    ->webSocketCandle($instrumentId, $channel, $row),
                fn (array $row): ?OkxPaperStreamFrontier => $this->candleFrontier(
                    $instrumentId,
                    $channel,
                    $row,
                ),
            );
        }
        if ($channel !== 'books' || !\is_string($message['action'] ?? null)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
        }

        $events = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_message_invalid');
            }
            if ($message['action'] === 'snapshot') {
                $state = $this->books[$instrumentId]->replaceSnapshot($row);
            } else {
                $result = $this->books[$instrumentId]->applyDelta($row);
                if ($result->status() === OkxPaperBookDeltaStatus::REPLAYED) {
                    continue;
                }
                $state = $result->materializedState();
            }
            $events = [
                ...$events,
                ...$this->acceptedBookEvents(
                    $symbol . '/ws/top_of_book',
                    $instrumentId,
                    $state,
                    'ws_books',
                    $this->checkpoint->sourceEpochs[$symbol],
                ),
            ];
        }

        return $events;
    }

    private function streamForEvent(PaperMarketEvent $event): string
    {
        $channel = $event->channel->value === 'candle_1h'
            ? 'candle_1H'
            : $event->channel->value;

        return $event->symbol . '/ws/' . $channel;
    }

    /** @param array<array-key, mixed> $row */
    private function candleFrontier(
        string $instrumentId,
        string $barOrChannel,
        array $row,
    ): ?OkxPaperStreamFrontier {
        if (!array_is_list($row) || \count($row) !== 9) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $normalizedBar = match ($barOrChannel) {
            '1m', 'candle1m' => '1m',
            '5m', 'candle5m' => '5m',
            '15m', 'candle15m' => '15m',
            '1H', 'candle1H' => '1h',
            default => throw new OkxPaperLiveIntegrityException(
                'okx_paper_public_response_invalid',
            ),
        };
        $channel = 'candle_' . $normalizedBar;
        $timestamp = $this->requiredTimestamp($row[0] ?? null);
        $open = $this->requiredDecimal($row[1] ?? null);
        $high = $this->requiredDecimal($row[2] ?? null);
        $low = $this->requiredDecimal($row[3] ?? null);
        $close = $this->requiredDecimal($row[4] ?? null);
        $volumeContracts = $this->requiredDecimal($row[5] ?? null);
        $volumeBase = $this->requiredDecimal($row[6] ?? null);
        $volumeQuote = $this->requiredDecimal($row[7] ?? null);
        $confirmed = $row[8] ?? null;
        if ($confirmed !== '0' && $confirmed !== '1') {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        if ($confirmed === '0') {
            return null;
        }
        $sourceIdentity = $normalizedBar . '|' . $timestamp;
        $sourceFields = [
            'bar' => $normalizedBar,
            'close' => $close,
            'confirmed' => true,
            'high' => $high,
            'low' => $low,
            'open' => $open,
            'opening_timestamp_ms' => $timestamp,
            'volume_base' => $volumeBase,
            'volume_contracts' => $volumeContracts,
            'volume_quote' => $volumeQuote,
        ];

        return $this->frontierFromCanonical(
            $instrumentId,
            $channel,
            $sourceIdentity,
            $sourceFields,
            $timestamp,
            OkxPaperStreamFrontier::canonicalCandleOverlapFields($sourceFields),
        );
    }

    /** @param array<array-key, mixed> $row */
    private function tradeFrontier(array $row): OkxPaperStreamFrontier
    {
        $instrumentId = $row['instId'] ?? null;
        if (!\is_string($instrumentId)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $this->instruments->normalizedSymbol($instrumentId);
        $tradeId = $this->requiredUnsigned($row['tradeId'] ?? null);
        $price = $this->requiredDecimal($row['px'] ?? null);
        $size = $this->requiredDecimal($row['sz'] ?? null);
        $side = $row['side'] ?? null;
        if ($side !== 'buy' && $side !== 'sell') {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $source = $this->requiredUnsigned($row['source'] ?? null);
        $timestamp = $this->requiredTimestamp($row['ts'] ?? null);

        return $this->frontierFromCanonical(
            $instrumentId,
            'public_trade',
            $tradeId,
            [
                'exchange_timestamp_ms' => $timestamp,
                'price' => $price,
                'size_contracts' => $size,
                'source' => $source,
                'taker_side' => $side,
                'trade_id' => $tradeId,
            ],
            $timestamp,
            // Cross-origin identity (see OkxPaperStreamFrontier::fromEvent()): neither
            // the size (aggregates) nor `source` (OKX endpoints can disagree on it).
            [
                'exchange_timestamp_ms' => $timestamp,
                'price' => $price,
                'taker_side' => $side,
                'trade_id' => $tradeId,
            ],
        );
    }

    private function bookFrontier(
        string $instrumentId,
        OkxMaterializedBookState $state,
    ): OkxPaperStreamFrontier {
        $bid = $state->bestBid();
        $ask = $state->bestAsk();

        return $this->frontierFromCanonical(
            $instrumentId,
            'top_of_book',
            $state->sourceSequence,
            [
                'ask_order_count' => $ask['order_count'],
                'ask_price' => $ask['price'],
                'ask_size_contracts' => $ask['size'],
                'bid_order_count' => $bid['order_count'],
                'bid_price' => $bid['price'],
                'bid_size_contracts' => $bid['size'],
                'source_seq_id' => $state->sourceSequence,
            ],
            $state->exchangeTimestamp->format('Uv'),
        );
    }

    /**
     * @param array<string, mixed>      $sourceFields
     * @param array<string, mixed>|null $overlapSourceFields
     */
    private function frontierFromCanonical(
        string $instrumentId,
        string $channel,
        string $sourceIdentity,
        array $sourceFields,
        string $exchangeTimestamp,
        ?array $overlapSourceFields = null,
    ): OkxPaperStreamFrontier {
        $canonical = [
            'channel' => $channel,
            'native_symbol' => $instrumentId,
            'source_fields' => $sourceFields,
            'venue' => PaperMarketDataVenue::OKX->value,
            'exchange_timestamp' => $this->formattedTimestamp($exchangeTimestamp),
        ];
        $overlapCanonical = $canonical;
        $overlapCanonical['source_fields'] = $overlapSourceFields ?? $sourceFields;

        return OkxPaperStreamFrontier::fromArray([
            'source_identity' => $sourceIdentity,
            'natural_identity' => implode('|', [
                PaperMarketDataVenue::OKX->value,
                $instrumentId,
                $channel,
                $sourceIdentity,
            ]),
            'canonical_digest' => hash('sha256', CanonicalJson::encode($canonical)),
            'overlap_digest' => hash('sha256', CanonicalJson::encode($overlapCanonical)),
        ]);
    }

    private function requiredTimestamp(mixed $value): string
    {
        $timestamp = $this->requiredUnsigned($value);
        if (\strlen($timestamp) !== 13) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }
        $this->formattedTimestamp($timestamp);

        return $timestamp;
    }

    private function formattedTimestamp(string $milliseconds): string
    {
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!U.u',
            substr($milliseconds, 0, 10) . '.' . substr($milliseconds, 10) . '000',
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function requiredDecimal(mixed $value): string
    {
        if (!\is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]+)?\z/D', $value) !== 1
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        return $value;
    }

    private function requiredUnsigned(mixed $value): string
    {
        if (!\is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        return $value;
    }

}
