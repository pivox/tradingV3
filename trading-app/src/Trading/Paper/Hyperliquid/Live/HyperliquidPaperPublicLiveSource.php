<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperFundingRateClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\PaperDurableBatchSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\ClockInterface;

final class HyperliquidPaperPublicLiveSource implements PaperDurableBatchSourceInterface
{
    private const MAX_STANDBY_ITEMS = 2048;

    private HyperliquidPaperPublicSubscriptionSet $subscriptions;
    private HyperliquidPaperPublicFrameDecoder $decoder;
    private HyperliquidPaperPublicFrameQueue $queue;
    private readonly HyperliquidPaperSourceOrdinal $ordinals;
    private readonly HyperliquidPaperMarketEventNormalizer $normalizer;
    private readonly LoggerInterface $logger;
    private readonly HyperliquidTradeCountAudit $tradeCountAudit;

    private ?\DateTimeImmutable $connectRequestedAt = null;
    private ?\DateTimeImmutable $connectedAt = null;
    private ?\DateTimeImmutable $lastFrameAt = null;
    private int $connectionFrames = 0;

    private ?\Throwable $transportFailure = null;
    private bool $stopped = false;
    private int $generationSequence = 0;
    private int $activeGeneration = 0;
    private bool $transportReadingPaused = false;

    private bool $healthyStopRequested = false;
    private ?TimerInterface $heartbeatTimer = null;
    private ?TimerInterface $pongTimer = null;
    private ?TimerInterface $fundingTimer = null;
    private ?\DateTimeImmutable $fundingDueAt = null;
    private bool $fundingRefreshDue = false;

    private ?HyperliquidPaperLiveStandbyConnection $standby = null;
    private ?TimerInterface $rotationTimer = null;
    private bool $rotationDue = false;
    private bool $rotationAttention = false;
    private int $rotationAttempts = 0;

    /**
     * The active connection was lost while streaming and a replacement races the trades
     * snapshot: trigger, loss time and the attempts made so far.
     *
     * @var array{trigger: string, lost_at: \DateTimeImmutable, attempts: int, adopted: bool}|null
     */
    private ?array $recovery = null;
    private ?TimerInterface $recoveryTimer = null;
    private ?TimerInterface $recoveryRetryTimer = null;
    private bool $recoveryDeadlineReached = false;

    private ?TimerInterface $hotStandbyReopenTimer = null;
    private int $hotStandbyFailures = 0;
    private int $hotStandbySequence = 0;

    /** @var array<string, int> coin => time of the last recorded l2Book snapshot */
    private array $lastBookTimes = [];

    /** @var array<string, int> coin => time of the last trade row the active connection delivered */
    private array $lastTradeTimes = [];

    /**
     * Top-of-book events already built from processed frames, not yet handed to the capture:
     * they leave together as one durable batch (see flushHeldBooks()).
     *
     * @var list<PaperMarketEvent>
     */
    private array $heldBooks = [];
    private ?TimerInterface $bookFlushTimer = null;
    private bool $bookFlushDue = false;

    /** @var list<array{decoded: array{kind: string, data?: mixed}, frame: string}> */
    private array $deferredDecodedFrames = [];

    /** @var list<string> */
    private array $preReadyFrames = [];
    private int $preReadyFrameBytes = 0;

    /**
     * @param HyperliquidPaperPublicWebSocketTransportFactoryInterface|null $rotationTransports
     *     opens the standby connection of a make-before-break rotation; null disables rotation
     * @param float $connectionRotationSeconds connection age that starts a rotation; only
     *     tests shorten the policy default
     * @param bool $hotStandby keep a second subscribed connection at all times, so a loss of
     *     the active one switches with a proof already buffered; tests of the other paths
     *     disable it
     * @param bool $tradeCountHoleFailsClosed a minute with fewer trades than its candle counts
     *     stops the capture instead of only being logged (policy default)
     * @param float $bookBatchSeconds age at which held books are flushed; only tests shorten the
     *     policy default, to fire this timer apart from the 1 s recovery timers
     */
    public function __construct(
        private HyperliquidPaperPublicWebSocketTransportInterface $transport,
        private readonly HyperliquidPaperPublicConfig $config,
        private readonly ClockInterface $clock,
        private readonly HyperliquidPaperLiveCheckpointStore $checkpointStore,
        private HyperliquidPaperLiveCheckpoint $checkpoint,
        private readonly LoopInterface $loop,
        ?HyperliquidPaperPublicSubscriptionSet $subscriptions = null,
        ?HyperliquidPaperPublicFrameDecoder $decoder = null,
        ?HyperliquidPaperPublicFrameQueue $queue = null,
        private readonly ?HyperliquidPaperInstrumentMetadataClientInterface $metadataClient = null,
        private readonly ?HyperliquidPaperFundingRateClientInterface $fundingClient = null,
        private readonly ?HyperliquidPaperPublicRestClientInterface $restClient = null,
        ?LoggerInterface $logger = null,
        private readonly ?HyperliquidPaperPublicWebSocketTransportFactoryInterface $rotationTransports = null,
        private readonly float $connectionRotationSeconds = HyperliquidPaperLivePolicy::CONNECTION_ROTATION_SECONDS,
        private readonly bool $hotStandby = true,
        private readonly bool $tradeCountHoleFailsClosed = HyperliquidPaperLivePolicy::TRADE_COUNT_BELOW_CANDLE_FAILS_CLOSED,
        private readonly float $bookBatchSeconds = HyperliquidPaperLivePolicy::BOOK_BATCH_SECONDS,
    ) {
        $this->logger = $logger ?? new NullLogger();
        if (!is_finite($connectionRotationSeconds) || $connectionRotationSeconds <= 0.0) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_rotation_age_invalid');
        }
        if (!is_finite($bookBatchSeconds)
            || $bookBatchSeconds <= 0.0
            || $bookBatchSeconds > HyperliquidPaperLivePolicy::BOOK_BATCH_SECONDS
        ) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_book_batch_age_invalid');
        }
        if ($config->network !== $checkpoint->network) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_checkpoint_mismatch');
        }
        if ($this->restClient instanceof HyperliquidPaperPublicRestClientInterface
            && $this->restClient->network() !== $config->network
        ) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_live_rest_client_network_mismatch',
            );
        }
        $this->subscriptions = $subscriptions ?? new HyperliquidPaperPublicSubscriptionSet();
        $this->decoder = $decoder ?? new HyperliquidPaperPublicFrameDecoder(
            $this->subscriptions,
        );
        $this->queue = $queue ?? new HyperliquidPaperPublicFrameQueue();
        $this->ordinals = HyperliquidPaperSourceOrdinal::restore(
            $checkpoint->ordinalState,
        );
        $this->normalizer = new HyperliquidPaperMarketEventNormalizer(
            $config->network,
            $this->ordinals,
            $clock,
        );
        $this->tradeCountAudit = new HyperliquidTradeCountAudit();
    }

    public function venue(): PaperMarketDataVenue
    {
        return PaperMarketDataVenue::HYPERLIQUID;
    }

    public function events(): iterable
    {
        try {
            yield from $this->eventFlow();
        } catch (\Throwable $exception) {
            $this->shutdownAfterFailure();
            $reason = $this->publicReason($exception);
            $this->warn('hyperliquid_paper_public_source_failed', [
                'public_reason' => $reason,
                ...HyperliquidPaperLiveDiagnostics::exception($exception),
            ]);
            if ($exception->getMessage() !== 'hyperliquid_paper_public_acquisition_disabled'
                && !\in_array($this->checkpoint->phase, ['complete', 'failed'], true)
            ) {
                try {
                    $this->checkpoint = $this->checkpointStore->save(
                        $this->checkpoint->fail($reason),
                    );
                } catch (\Throwable) {
                    // The original stable public failure remains authoritative.
                }
            }

            if ($reason !== $exception->getMessage()) {
                throw new HyperliquidPaperLiveIntegrityException(
                    $reason,
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function eventFlow(): \Generator
    {
        if (!$this->config->acquisitionEnabled) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_acquisition_disabled',
            );
        }

        yield from $this->resumePendingEvents();
        if ($this->checkpoint->phase === 'failed') {
            throw new HyperliquidPaperLiveIntegrityException(
                $this->checkpoint->failureReason
                    ?? 'hyperliquid_paper_public_protocol_error',
            );
        }
        if ($this->checkpoint->phase === 'complete') {
            return;
        }
        if ($this->checkpoint->phase === 'stopping') {
            $this->cancelTimers();
            $this->transport->close();
            $this->stopped = true;
            $this->checkpoint = $this->checkpointStore->save(
                $this->checkpoint->completeHealthyStop(),
            );

            return;
        }
        if (\in_array(
            $this->checkpoint->phase,
            ['fresh', 'connecting', 'subscribing', 'warming', 'catching_up'],
            true,
        )) {
            if ($this->checkpoint->phase !== 'fresh') {
                $this->checkpoint = $this->checkpointStore->save(
                    $this->checkpoint->withPhase('fresh'),
                );
            }
            if ($this->restClient instanceof HyperliquidPaperPublicRestClientInterface) {
                if ($this->checkpoint->initialCandleWindowEnds === [
                    'BTC' => null,
                    'ETH' => null,
                ]) {
                    $upperBound = $this->nowMilliseconds();
                    $this->checkpoint = $this->checkpointStore->save(
                        $this->checkpoint->withInitialCandleWindowEnds([
                            'BTC' => (string) $upperBound,
                            'ETH' => (string) $upperBound,
                        ])->withPhase('warming'),
                    );
                } else {
                    $this->checkpoint = $this->checkpointStore->save(
                        $this->checkpoint->withPhase('warming'),
                    );
                }
                $warmup = new HyperliquidPaperLiveCandleWarmup($this->restClient);
                foreach ($warmup->candles(
                    $this->checkpoint->initialCandleWindowEnds,
                    $this->nowMilliseconds(),
                ) as $candle) {
                    yield from $this->yieldWarmupCandle($candle);
                }
                $this->connectAndSubscribe();
                $this->awaitSubscriptions();
                $this->throwPendingTransportFailure();
                $this->checkpoint = $this->checkpointStore->save(
                    $this->checkpoint->withPhase('catching_up'),
                );
                $this->scheduleHeartbeat();
                foreach ($warmup->catchupCandles(
                    $this->checkpoint->finalizedCandleFrontiers,
                    $this->nowMilliseconds(),
                    function (): void {
                        $this->assertCatchupConnectionActive();
                        $this->pumpNetworkLoop();
                        $this->assertCatchupConnectionActive();
                    },
                ) as $candle) {
                    yield from $this->yieldWarmupCandle($candle);
                    $this->assertCatchupConnectionActive();
                }
                $this->assertCatchupConnectionActive();
                $this->checkpoint = $this->checkpointStore->save(
                    $this->checkpoint->withPhase('streaming'),
                );
                yield from $this->yieldCandidates([
                    ...($this->metadataClient === null ? [] : $this->metadataEvents()),
                    ...($this->fundingClient === null ? [] : $this->fundingEvents()),
                    $this->normalizer->snapshotBoundary('BTC', 'initial', $this->checkpoint->sourceEpoch),
                    $this->normalizer->snapshotBoundary('ETH', 'initial', $this->checkpoint->sourceEpoch),
                ]);
                $this->scheduleHeartbeat();
                $this->scheduleFundingRefresh();
                $this->scheduleRotation();
                $this->openHotStandby();
            } else {
                $this->connectAndSubscribe();
                $this->awaitSubscriptions();
                $this->throwPendingTransportFailure();
                $this->checkpoint = $this->checkpointStore->save(
                    $this->checkpoint->withPhase('streaming'),
                );
                $this->scheduleHeartbeat();
                $this->scheduleFundingRefresh();
                $this->scheduleRotation();
                $this->openHotStandby();
                yield from $this->yieldCandidates([
                    ...($this->metadataClient === null ? [] : $this->metadataEvents()),
                    ...($this->fundingClient === null ? [] : $this->fundingEvents()),
                    $this->normalizer->snapshotBoundary(
                        'BTC',
                        'initial',
                        $this->checkpoint->sourceEpoch,
                    ),
                    $this->normalizer->snapshotBoundary(
                        'ETH',
                        'initial',
                        $this->checkpoint->sourceEpoch,
                    ),
                ]);
            }
        } elseif (
            $this->checkpoint->phase === 'streaming'
            || $this->checkpoint->phase === 'reconnecting'
        ) {
            $this->failUnrecoverableContinuity(match ($this->checkpoint->rotation['cause'] ?? null) {
                null => 'restart_in_streaming',
                HyperliquidPaperLiveCheckpoint::ROTATION_CAUSE_RECOVERY => 'restart_during_recovery',
                default => 'restart_during_rotation',
            });
        }

        while (!$this->stopped) {
            if ($this->heldBooksDue()) {
                yield from $this->flushHeldBooks();

                continue;
            }
            $this->persistHealthyStopWhenDrained();
            if ($this->checkpoint->phase === 'stopping'
                && $this->queue->count() === 0
                && $this->preReadyFrames === []
                && $this->deferredDecodedFrames === []
            ) {
                if ($this->checkpoint->pendingEvent !== null) {
                    throw new HyperliquidPaperLiveIntegrityException(
                        'hyperliquid_acquisition_pending_event_not_acknowledged',
                    );
                }
                $this->cancelTimers();
                $this->transport->close();
                $this->stopped = true;
                $this->checkpoint = $this->checkpointStore->save(
                    $this->checkpoint->completeHealthyStop(),
                );

                return;
            }

            if ($this->fundingRefreshDue) {
                $this->fundingRefreshDue = false;
                $this->scheduleFundingRefresh();
                // Held books go first: the funding events take their ordinals when created.
                yield from $this->flushHeldBooks();
                yield from $this->yieldCandidates($this->fundingEvents());

                continue;
            }

            $this->rotationAttention = false;
            if ($this->standby !== null || $this->rotationDue) {
                $this->advanceRotation();
                $this->throwPendingTransportFailure();
            }

            if ($this->checkpoint->phase === 'reconnecting'
                && !$this->subscriptions->isReady()
            ) {
                $this->awaitSubscriptions();
                yield from $this->completeReconnectSubscriptions();

                continue;
            }

            $received = $this->nextDecodedFrame();
            if ($received === null) {
                continue;
            }
            $received = $this->coalesceQueuedTradeFrames($received);
            yield from $this->processDecoded(
                $received['decoded'],
                $received['frame'],
            );
        }
    }

    private function nowMilliseconds(): int
    {
        $now = $this->clock->now();
        $seconds = (int) $now->format('U');
        $milliseconds = (int) $now->format('v');
        if ($seconds < 0 || $seconds > intdiv(\PHP_INT_MAX - $milliseconds, 1_000)) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_candle_warmup_invalid',
            );
        }

        return $seconds * 1_000 + $milliseconds;
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function yieldWarmupCandle(HyperliquidCandle $candle): \Generator
    {
        $stream = $candle->coin . '/' . $candle->interval;
        if (($this->checkpoint->finalizedCandleFrontiers[$stream] ?? -1)
            >= $candle->startTime
        ) {
            return;
        }
        $event = $this->normalizer->candle($candle);
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint
                ->withOrdinals($this->ordinals)
                ->withPending($event, [
                    'remaining_events' => [],
                    'after_ack' => [
                        'finalize_candle' => [
                            'stream' => $stream,
                            'start_time' => $candle->startTime,
                        ],
                    ],
                ]),
        );
        yield from $this->resumePendingEvents();
    }

    private function connectAndSubscribe(): void
    {
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->withPhase('connecting'),
        );
        $this->openTransport(reconnecting: false);
    }

    private function openTransport(bool $reconnecting): void
    {
        $generation = $this->activeGeneration = ++$this->generationSequence;
        $this->connectRequestedAt = $this->clock->now();
        $this->connectedAt = null;
        $this->lastFrameAt = null;
        $this->connectionFrames = 0;
        $this->connectTransport($this->transport, $generation, $reconnecting);
    }

    /**
     * The callbacks resolve their role when they fire: a standby connection becomes the
     * active one when a rotation completes, without reconnecting.
     */
    private function connectTransport(
        HyperliquidPaperPublicWebSocketTransportInterface $transport,
        int $generation,
        bool $reconnecting,
    ): void {
        $transport->connect(
            function () use ($generation, $reconnecting): void {
                if ($this->isStandbyGeneration($generation)) {
                    $this->standbyOpened();

                    return;
                }
                if ($generation !== $this->activeGeneration || $this->stopped) {
                    return;
                }
                $this->connectedAt = $this->clock->now();
                if (!$reconnecting) {
                    $this->checkpoint = $this->checkpointStore->save(
                        $this->checkpoint->withPhase('subscribing'),
                    );
                }
                foreach ($this->subscriptions->subscriptions() as $subscription) {
                    $this->transport->send($subscription);
                }
            },
            function (string $frame) use ($generation): void {
                if ($this->isStandbyGeneration($generation)) {
                    $this->standbyFrame($frame);

                    return;
                }
                if ($generation !== $this->activeGeneration || $this->stopped) {
                    return;
                }
                $this->lastFrameAt = $this->clock->now();
                ++$this->connectionFrames;
                try {
                    $this->queue->enqueue($frame);
                    $this->pauseTransportReadingAtHighWater();
                } catch (\Throwable $failure) {
                    $this->failTransport('ws_frame_enqueue', $failure, $failure);
                    $this->transport->close();
                }
                $this->loop->stop();
            },
            function (?int $code = null, ?string $reason = null) use ($generation): void {
                if ($this->isStandbyGeneration($generation)) {
                    $this->standbyLost('standby_closed', [
                        'ws_close_code' => $code,
                        'ws_close_reason' => HyperliquidPaperLiveDiagnostics::text($reason),
                    ]);

                    return;
                }
                $current = $generation === $this->activeGeneration && !$this->stopped;
                $this->warn('hyperliquid_paper_public_ws_closed', [
                    'ws_close_code' => $code,
                    'ws_close_reason' => HyperliquidPaperLiveDiagnostics::text($reason),
                    'current_connection' => $current,
                ]);
                if (!$current) {
                    return;
                }
                if (\in_array($this->checkpoint->phase, ['streaming', 'reconnecting'], true)) {
                    $this->activeLost('ws_close', [
                        'ws_close_code' => $code,
                        'ws_close_reason' => HyperliquidPaperLiveDiagnostics::text($reason),
                    ]);
                } else {
                    $this->failTransport('ws_close', new HyperliquidPaperLiveIntegrityException(
                        'hyperliquid_paper_public_connection_closed',
                    ));
                }
                $this->loop->stop();
            },
            function (\Throwable $failure) use ($generation): void {
                if ($this->isStandbyGeneration($generation)) {
                    $this->standbyLost(
                        'standby_error',
                        HyperliquidPaperLiveDiagnostics::exception($failure),
                    );

                    return;
                }
                $current = $generation === $this->activeGeneration && !$this->stopped;
                $this->warn('hyperliquid_paper_public_ws_error', [
                    ...HyperliquidPaperLiveDiagnostics::exception($failure),
                    'current_connection' => $current,
                ]);
                if (!$current) {
                    return;
                }
                if ($failure instanceof HyperliquidPaperLiveIntegrityException
                    && $failure->getMessage() === 'market_data_backpressure_exhausted'
                ) {
                    $this->failTransport('ws_error', $failure);
                    $this->loop->stop();

                    return;
                }
                if (\in_array($this->checkpoint->phase, ['streaming', 'reconnecting'], true)) {
                    $this->activeLost('ws_error', HyperliquidPaperLiveDiagnostics::exception($failure));
                } else {
                    $this->failTransport('ws_error', new HyperliquidPaperLiveIntegrityException(
                        'hyperliquid_paper_public_protocol_error',
                    ), $failure);
                }
                $this->loop->stop();
            },
        );
    }

    private function awaitSubscriptions(): void
    {
        while (!$this->subscriptions->isReady()) {
            $frame = $this->nextTransportFrame();
            if ($frame === null) {
                continue;
            }
            $decoded = $this->decoder->decode($frame);
            if ($decoded['kind'] === 'subscription') {
                continue;
            }
            if (!\in_array($decoded['kind'], ['trades', 'book', 'candle'], true)) {
                throw new HyperliquidPaperLiveIntegrityException(
                    'hyperliquid_paper_public_message_before_ready',
                );
            }
            $this->deferPreReadyFrame($frame);
        }
    }

    /** @return array{decoded: array{kind: string, data?: mixed}, frame: string}|null */
    private function nextDecodedFrame(): ?array
    {
        $deferred = array_shift($this->deferredDecodedFrames);
        if ($deferred !== null) {
            return $deferred;
        }

        $frame = $this->dequeuePreReadyFrame();
        if ($frame === null) {
            $frame = $this->nextTransportFrame();
        }
        if ($frame === null) {
            return null;
        }

        return [
            'decoded' => $this->decoder->decode($frame),
            'frame' => $frame,
        ];
    }

    /**
     * @param array{decoded: array{kind: string, data?: mixed}, frame: string} $received
     * @return array{decoded: array{kind: string, data?: mixed}, frame: string}
     */
    private function coalesceQueuedTradeFrames(#[\SensitiveParameter] array $received): array
    {
        if ($received['decoded']['kind'] !== 'trades'
            || !\is_array($received['decoded']['data'] ?? null)
        ) {
            return $received;
        }

        $rows = $received['decoded']['data'];
        while (\count($rows) < HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS
            && $this->queue->count() > 0
        ) {
            $next = $this->nextDecodedFrame();
            if ($next === null) {
                break;
            }
            if ($next['decoded']['kind'] === 'pong') {
                $this->acceptPong();

                continue;
            }
            if ($next['decoded']['kind'] !== 'trades'
                || !\is_array($next['decoded']['data'] ?? null)
            ) {
                array_unshift($this->deferredDecodedFrames, $next);

                break;
            }

            $available = HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS - \count($rows);
            $rows = [...$rows, ...array_slice($next['decoded']['data'], 0, $available)];
            $remaining = array_slice($next['decoded']['data'], $available);
            if ($remaining !== []) {
                $next['decoded']['data'] = $remaining;
                array_unshift($this->deferredDecodedFrames, $next);

                break;
            }
        }
        $received['decoded']['data'] = $rows;

        return $received;
    }

    private function nextTransportFrame(): ?string
    {
        while ($this->queue->count() === 0) {
            if ($this->transportFailure !== null) {
                throw $this->transportFailure;
            }
            if ($this->heldBooksDue()) {
                return null;
            }
            $this->loop->run();
            $failure = $this->pendingTransportFailure();
            if ($failure !== null) {
                throw $failure;
            }
            if ($this->checkpoint->phase === 'stopping') {
                return null;
            }
            if ($this->fundingRefreshDue || $this->heldBooksDue()) {
                return null;
            }
            if ($this->rotationAttention) {
                $this->rotationAttention = false;

                return null;
            }
            if ($this->queue->count() === 0) {
                throw new HyperliquidPaperLiveIntegrityException(
                    'hyperliquid_paper_public_no_progress',
                );
            }
        }
        $frame = $this->queue->dequeue();
        if ($frame === null) {
            return null;
        }
        $this->resumeTransportReadingAfterDrain();

        return $frame;
    }

    private function pauseTransportReadingAtHighWater(): void
    {
        if ($this->transportReadingPaused
            || ($this->queue->count() < HyperliquidPaperLivePolicy::NETWORK_PUMP_FRAME_HIGH_WATER
                && $this->queue->bytes() < HyperliquidPaperLivePolicy::NETWORK_PUMP_BYTE_HIGH_WATER)
        ) {
            return;
        }

        $this->transport->pauseReading();
        $this->transportReadingPaused = true;
    }

    private function resumeTransportReadingAfterDrain(): void
    {
        if (!$this->transportReadingPaused
            || $this->queue->count() > HyperliquidPaperLivePolicy::NETWORK_RESUME_FRAME_LOW_WATER
            || $this->queue->bytes() > HyperliquidPaperLivePolicy::NETWORK_RESUME_BYTE_LOW_WATER
        ) {
            return;
        }

        $this->transportReadingPaused = false;
        $this->transport->resumeReading();
    }

    private function deferPreReadyFrame(#[\SensitiveParameter] string $frame): void
    {
        $frameBytes = \strlen($frame);
        if (\count($this->preReadyFrames) + $this->queue->count()
                >= HyperliquidPaperLivePolicy::MAX_QUEUED_FRAMES
            || $frameBytes + $this->preReadyFrameBytes + $this->queue->bytes()
                > HyperliquidPaperLivePolicy::MAX_QUEUED_BYTES
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'market_data_backpressure_exhausted',
            );
        }

        $this->preReadyFrames[] = $frame;
        $this->preReadyFrameBytes += $frameBytes;
    }

    private function dequeuePreReadyFrame(): ?string
    {
        $frame = array_shift($this->preReadyFrames);
        if ($frame === null) {
            return null;
        }

        $this->preReadyFrameBytes -= \strlen($frame);

        return $frame;
    }

    private function clearPreReadyFrames(): void
    {
        $this->preReadyFrames = [];
        $this->preReadyFrameBytes = 0;
        $this->deferredDecodedFrames = [];
    }

    private function pendingTransportFailure(): ?\Throwable
    {
        return $this->transportFailure;
    }

    private function throwPendingTransportFailure(): void
    {
        $failure = $this->pendingTransportFailure();
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @param array{kind: string, data?: mixed} $decoded
     * @return \Generator<int, PaperMarketEvent>
     */
    private function processDecoded(
        array $decoded,
        #[\SensitiveParameter] string $frame,
    ): \Generator
    {
        if ($decoded['kind'] === 'subscription') {
            yield from $this->completeReconnectSubscriptions();

            return;
        }
        if ($decoded['kind'] === 'pong') {
            $this->acceptPong();

            return;
        }
        if ($this->checkpoint->phase === 'reconnecting'
            && !$this->subscriptions->isReady()
        ) {
            if (!\in_array($decoded['kind'], ['trades', 'book', 'candle'], true)) {
                throw new HyperliquidPaperLiveIntegrityException(
                    'hyperliquid_paper_public_message_before_ready',
                );
            }
            $this->deferPreReadyFrame($frame);
            $this->awaitSubscriptions();
            yield from $this->completeReconnectSubscriptions();

            return;
        }
        if ($decoded['kind'] === 'trades') {
            if (!\is_array($decoded['data'] ?? null)) {
                throw new \LogicException();
            }
            $known = [];
            foreach ($this->checkpoint->tradeIdentityHistory as $entry) {
                $known[$entry['identity_hash']] = $entry['assignment_digest'];
            }
            $candidateRows = [];
            foreach ($decoded['data'] as $row) {
                if (!\is_array($row)) {
                    throw new \LogicException();
                }
                $coin = $row['coin'] ?? null;
                $time = $row['time'] ?? null;
                if (\is_string($coin) && \is_int($time)) {
                    $this->lastTradeTimes[$coin] = max($this->lastTradeTimes[$coin] ?? $time, $time);
                }
                $fingerprint = $this->normalizer->liveTradeFingerprint($row);
                $knownDigest = $known[$fingerprint['identity_hash']] ?? null;
                if ($knownDigest !== null) {
                    if (!hash_equals(
                        $knownDigest,
                        $fingerprint['assignment_digest'],
                    )) {
                        throw new \RuntimeException(
                            'hyperliquid_paper_natural_identity_conflict',
                        );
                    }

                    continue;
                }
                $known[$fingerprint['identity_hash']]
                    = $fingerprint['assignment_digest'];
                $candidateRows[] = $row;
                if (\is_string($coin) && \is_int($time)) {
                    $this->tradeCountAudit->trade($coin, $time);
                }
            }
            $this->settleTradeCounts();
            yield from $this->yieldTradeRows($candidateRows);

            return;
        }
        if ($decoded['kind'] === 'book') {
            if (!\is_array($decoded['data'] ?? null)) {
                throw new \LogicException();
            }
            $coin = $decoded['data']['coin'] ?? null;
            $time = $decoded['data']['time'] ?? null;
            if (\is_string($coin) && \is_int($time)) {
                $this->lastBookTimes[$coin] = max($this->lastBookTimes[$coin] ?? $time, $time);
            }
            $this->holdBook(
                \array_key_exists('bbo', $decoded['data'])
                    ? $this->normalizer->liveTopOfBookFromBbo(
                        $decoded['data'],
                        $this->checkpoint->sourceEpoch,
                    )
                    : $this->normalizer->liveTopOfBook(
                        $decoded['data'],
                        $this->checkpoint->sourceEpoch,
                    ),
            );

            return;
        }
        if ($decoded['kind'] !== 'candle' || !\is_array($decoded['data'] ?? null)) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_message_invalid',
            );
        }
        /** @var array<string, mixed> $row */
        $row = $decoded['data'];
        $coin = $row['s'];
        $interval = $row['i'];
        if (!\is_string($coin) || !\is_string($interval)) {
            throw new \LogicException();
        }
        $stream = $coin . '/' . $interval;
        $next = HyperliquidCandle::fromApiRow($row, $coin, $interval);
        $finalizedFrontier = $this->checkpoint->finalizedCandleFrontiers[$stream] ?? null;
        if ($finalizedFrontier !== null && $next->startTime <= $finalizedFrontier) {
            return;
        }
        // An unfinished candle only replaces the in-memory row that its closing event is
        // built from, so it becomes durable with the next saved transition. No restart
        // reads it: a streaming checkpoint is never resumed, and completing a persisted
        // healthy stop clears unfinished candles.
        $currentRow = $this->checkpoint->currentCandles[$stream] ?? null;
        if ($currentRow === null) {
            $this->checkpoint = $this->checkpoint->withCurrentCandle($stream, $row);

            return;
        }
        $current = HyperliquidCandle::fromApiRow($currentRow, $coin, $interval);
        if ($next->startTime <= $current->startTime) {
            $this->checkpoint = $this->checkpoint->withCurrentCandle($stream, $row);

            return;
        }
        if ($interval === '1m') {
            $this->tradeCountAudit->closedCandle($coin, $current->startTime, $current->tradeCount);
            $this->settleTradeCounts();
        }
        yield from $this->flushHeldBooks();
        $event = $this->normalizer->closedLiveCandle($current);
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint
                ->withOrdinals($this->ordinals)
                ->withCurrentCandle($stream, $row)
                ->withPending($event, [
                    'remaining_events' => [],
                    'after_ack' => [
                        'finalize_candle' => [
                            'stream' => $stream,
                            'start_time' => $current->startTime,
                        ],
                    ],
                ]),
        );
        yield from $this->resumePendingEvents();
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function completeReconnectSubscriptions(): \Generator
    {
        if ($this->checkpoint->phase !== 'reconnecting'
            || !$this->subscriptions->isReady()
        ) {
            return;
        }
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->withPhase('streaming'),
        );
        $this->scheduleHeartbeat();
        $this->scheduleFundingRefresh();
        // Held books go first: the events below take their ordinals when created.
        yield from $this->flushHeldBooks();
        yield from $this->yieldCandidates([
            ...($this->metadataClient === null ? [] : $this->metadataEvents()),
            ...($this->fundingClient === null ? [] : $this->fundingEvents()),
            $this->normalizer->snapshotBoundary(
                'BTC',
                'reconnect',
                $this->checkpoint->sourceEpoch,
            ),
            $this->normalizer->snapshotBoundary(
                'ETH',
                'reconnect',
                $this->checkpoint->sourceEpoch,
            ),
        ]);
    }

    /**
     * A fully covered minute recorded fewer trades than its closed candle counts: a hole in
     * the trades stream. Always logged (the watcher key); fatal only when the policy says so.
     */
    private function settleTradeCounts(): void
    {
        foreach ($this->tradeCountAudit->settle() as $hole) {
            $this->warn('hyperliquid_paper_public_trade_count_below_candle', [
                'coin' => $hole['coin'],
                'minute_start_ms' => $hole['minute_start'],
                'minute' => gmdate('Y-m-d\TH:i:s\Z', intdiv($hole['minute_start'], 1_000)),
                'rows' => $hole['rows'],
                'candle_trade_count' => $hole['candle_trade_count'],
                'missing_at_least' => $hole['candle_trade_count'] - $hole['rows'],
                'fails_closed' => $this->tradeCountHoleFailsClosed,
            ]);
            if ($this->tradeCountHoleFailsClosed) {
                throw new HyperliquidPaperLiveIntegrityException('hyperliquid_trade_count_below_candle');
            }
        }
    }

    /** @return list<PaperMarketEvent> */
    private function metadataEvents(): array
    {
        if (!$this->metadataClient instanceof HyperliquidPaperInstrumentMetadataClientInterface) {
            throw new \LogicException('hyperliquid_paper_metadata_client_required');
        }
        $events = [];
        foreach ($this->metadataClient->instrumentMetadata() as $row) {
            $events[] = $this->normalizer->instrumentMetadata(
                $row,
                $this->checkpoint->sourceEpoch,
            );
        }
        if (\count($events) !== 2
            || $events[0]->symbol !== 'BTCUSDT'
            || $events[1]->symbol !== 'ETHUSDT'
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_response_invalid',
            );
        }

        return $events;
    }

    /** @return list<PaperMarketEvent> */
    private function fundingEvents(): array
    {
        if (!$this->fundingClient instanceof HyperliquidPaperFundingRateClientInterface) {
            throw new \LogicException('hyperliquid_paper_funding_client_required');
        }
        $events = [];
        foreach ($this->fundingClient->fundingRates() as $row) {
            $events[] = $this->normalizer->fundingRate(
                $row,
                $this->checkpoint->sourceEpoch,
            );
        }
        if (\count($events) !== 2
            || $events[0]->symbol !== 'BTCUSDT'
            || $events[1]->symbol !== 'ETHUSDT'
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_response_invalid',
            );
        }

        return $events;
    }

    /**
     * Callers flush the held books before creating $events: an event takes its ordinal when
     * created, and the pending save of a book batch must not carry the ordinals of events
     * created after the books but not yet pending.
     *
     * @param list<PaperMarketEvent> $events
     * @param bool $durableBatch the events form one durable batch: acknowledged in memory until
     *     the last one, like the rows of a trade chunk
     * @return \Generator<int, PaperMarketEvent>
     */
    private function yieldCandidates(array $events, bool $durableBatch = false): \Generator
    {
        if (!$durableBatch) {
            yield from $this->flushHeldBooks();
        }
        $known = array_fill_keys(
            $this->checkpoint->acknowledgedIdentities,
            true,
        );
        $unique = [];
        foreach ($events as $event) {
            if (isset($known[$event->eventId])) {
                continue;
            }
            $known[$event->eventId] = true;
            $unique[] = $event;
        }
        $events = $unique;
        if ($events === []) {
            return;
        }
        $first = array_shift($events);
        if (!$first instanceof PaperMarketEvent) {
            throw new \LogicException();
        }
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint
                ->withOrdinals($this->ordinals)
                ->withPending($first, [
                    'remaining_events' => array_map(
                        static fn (PaperMarketEvent $event): array => $event->toArray(),
                        $events,
                    ),
                    'after_ack' => null,
                    ...($durableBatch && $events !== [] ? ['durable_batch' => true] : []),
                ]),
        );
        yield from $this->resumePendingEvents();
    }

    /**
     * Held books leave when their batch is old or full, and once the backlog is processed
     * before a switch completes or a healthy stop is saved (both wait for an empty batch).
     *
     * @phpstan-impure the loop callbacks change the answer (timer, stop request, switch)
     */
    private function heldBooksDue(): bool
    {
        if ($this->heldBooks === []) {
            return false;
        }

        return $this->bookFlushDue
            || \count($this->heldBooks) >= HyperliquidPaperLivePolicy::MAX_BOOK_BATCH_EVENTS
            || (($this->healthyStopRequested || $this->isDraining())
                && $this->queue->count() === 0
                && $this->deferredDecodedFrames === []
                && $this->preReadyFrames === []);
    }

    private function holdBook(PaperMarketEvent $event): void
    {
        $this->heldBooks[] = $event;
        if ($this->bookFlushTimer instanceof TimerInterface) {
            return;
        }
        $this->bookFlushTimer = $this->loop->addTimer(
            $this->bookBatchSeconds,
            function (): void {
                $this->bookFlushTimer = null;
                if ($this->heldBooks === [] || $this->stopped) {
                    return;
                }
                $this->bookFlushDue = true;
                $this->loop->stop();
            },
        );
    }

    /**
     * Hands the held books to the capture as one durable batch, before any other event so
     * that the dataset keeps the arrival order. Their exchange and receipt timestamps were
     * fixed when their frames were processed.
     *
     * @return \Generator<int, PaperMarketEvent>
     */
    private function flushHeldBooks(): \Generator
    {
        if ($this->heldBooks === []) {
            return;
        }
        $books = $this->heldBooks;
        $this->heldBooks = [];
        $this->bookFlushDue = false;
        if ($this->bookFlushTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->bookFlushTimer);
            $this->bookFlushTimer = null;
        }
        yield from $this->yieldCandidates($books, durableBatch: true);
    }

    /**
     * Ends a durable batch. Outside streaming the acknowledgement is saved at once. While
     * streaming it becomes durable with the next save (the next pending event, a heartbeat, a
     * rotation, a switch or the healthy stop): a stream is never resumed after a restart, and
     * the batch the stale checkpoint would replay is answered REPLAYED by the recorder, so the
     * dataset stays identical or incomplete, never duplicated or reordered.
     */
    private function acknowledgeBatchEnd(HyperliquidPaperLiveCheckpoint $acknowledged): void
    {
        $this->checkpoint = $acknowledged->phase === 'streaming'
            ? $acknowledged
            : $this->checkpointStore->save($acknowledged);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return \Generator<int, PaperMarketEvent>
     */
    private function yieldTradeRows(array $rows): \Generator
    {
        if ($rows === []) {
            return;
        }
        yield from $this->flushHeldBooks();
        $chunks = array_chunk(
            $rows,
            HyperliquidPaperLivePolicy::MAX_PENDING_TRADE_ROWS,
        );
        foreach ($chunks as $chunk) {
            yield from $this->yieldTradeChunk($chunk);
        }
    }

    /**
     * @param non-empty-list<array<string, mixed>> $rows
     * @return \Generator<int, PaperMarketEvent>
     */
    private function yieldTradeChunk(array $rows): \Generator
    {
        $first = array_shift($rows);
        if (!\is_array($first)) {
            throw new \LogicException();
        }
        $fingerprint = $this->normalizer->liveTradeFingerprint($first);
        $event = $this->normalizer->liveTrade($first);
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint
                ->withOrdinals($this->ordinals)
                ->withPending($event, [
                    'remaining_trade_rows' => array_map(
                        self::compactTradeRow(...),
                        $rows,
                    ),
                    'after_ack' => [
                        'remember_trade_identity' => [
                            'identity_hash' => $fingerprint['identity_hash'],
                            'assignment_digest' => $fingerprint['assignment_digest'],
                        ],
                    ],
                ]),
        );
        yield from $this->resumePendingEvents();
    }

    /** @return \Generator<int, PaperMarketEvent> */
    private function resumePendingEvents(): \Generator
    {
        while ($this->checkpoint->pendingEvent !== null) {
            $event = $this->checkpoint->pendingEvent;
            yield $event;
            if (!\in_array(
                $event->eventId,
                $this->checkpoint->acknowledgedIdentities,
                true,
            )) {
                throw new HyperliquidPaperLiveIntegrityException(
                    'hyperliquid_acquisition_pending_event_not_acknowledged',
                );
            }
        }
    }

    public function pendingDurableBatchSize(): int
    {
        $continuation = $this->checkpoint->pendingContinuation;
        $remainingTradeRows = $continuation['remaining_trade_rows'] ?? null;
        if ($remainingTradeRows === null) {
            if (($continuation['durable_batch'] ?? false) !== true) {
                return 1;
            }
            $remainingEvents = $continuation['remaining_events'] ?? null;
            if (!\is_array($remainingEvents) || !array_is_list($remainingEvents)) {
                throw new \LogicException();
            }

            return \count($remainingEvents) + 1;
        }
        if (!\is_array($remainingTradeRows) || !array_is_list($remainingTradeRows)) {
            throw new \LogicException();
        }

        return \count($remainingTradeRows) + 1;
    }

    public function acknowledge(string $eventId): void
    {
        $continuation = $this->checkpoint->pendingContinuation;
        if ($continuation === null) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_acquisition_pending_event_not_acknowledged',
            );
        }
        $next = $this->checkpoint->acknowledge($eventId);
        $afterAck = $continuation['after_ack'] ?? null;
        if (\is_array($afterAck)
            && \is_array($afterAck['finalize_candle'] ?? null)
        ) {
            $finalize = $afterAck['finalize_candle'];
            if (!\is_string($finalize['stream'] ?? null)
                || !\is_int($finalize['start_time'] ?? null)
            ) {
                throw new \LogicException();
            }
            $next = $next->finalizeCandle(
                $finalize['stream'],
                $finalize['start_time'],
            );
        }
        if (\is_array($afterAck)
            && \is_array($afterAck['remember_trade_identity'] ?? null)
        ) {
            $identity = $afterAck['remember_trade_identity'];
            if (!\is_string($identity['identity_hash'] ?? null)
                || !\is_string($identity['assignment_digest'] ?? null)
            ) {
                throw new \LogicException();
            }
            $next = $next->rememberTradeIdentity(
                $identity['identity_hash'],
                $identity['assignment_digest'],
            );
        }
        $remainingTradeRows = $continuation['remaining_trade_rows'] ?? null;
        if ($remainingTradeRows !== null) {
            if (!\is_array($remainingTradeRows)
                || !array_is_list($remainingTradeRows)
            ) {
                throw new \LogicException();
            }
            $nextRow = array_shift($remainingTradeRows);
            if ($nextRow !== null) {
                if (!\is_array($nextRow) || !array_is_list($nextRow)) {
                    throw new \LogicException();
                }
                $nextRow = self::expandTradeRow($nextRow);
                $fingerprint = $this->normalizer->liveTradeFingerprint($nextRow);
                $nextEvent = $this->normalizer->liveTrade($nextRow);
                $next = $next
                    ->withOrdinals($this->ordinals)
                    ->withPending($nextEvent, [
                        'remaining_trade_rows' => $remainingTradeRows,
                        'after_ack' => [
                            'remember_trade_identity' => [
                                'identity_hash' => $fingerprint['identity_hash'],
                                'assignment_digest' => $fingerprint['assignment_digest'],
                            ],
                        ],
                    ]);
            }
            if ($nextRow === null) {
                $this->acknowledgeBatchEnd($next);
                $this->pumpNetworkLoop();
            } else {
                $this->checkpoint = $next;
            }

            return;
        }

        $remaining = $continuation['remaining_events'] ?? null;
        if (!\is_array($remaining) || !array_is_list($remaining)) {
            throw new \LogicException();
        }
        $durableBatch = ($continuation['durable_batch'] ?? false) === true;
        $nextEventState = array_shift($remaining);
        if ($nextEventState !== null) {
            if (!\is_array($nextEventState) || array_is_list($nextEventState)) {
                throw new \LogicException();
            }
            $nextAfterAck = null;
            if (isset($nextEventState['event'], $nextEventState['after_ack'])) {
                if (!\is_array($nextEventState['event'])
                    || array_is_list($nextEventState['event'])
                    || !\is_array($nextEventState['after_ack'])
                    || array_is_list($nextEventState['after_ack'])
                ) {
                    throw new \LogicException();
                }
                $nextAfterAck = $nextEventState['after_ack'];
                $nextEventState = $nextEventState['event'];
            }
            $next = $next->withPending(
                PaperMarketEvent::fromArray($nextEventState),
                [
                    'remaining_events' => $remaining,
                    'after_ack' => $nextAfterAck,
                    ...($durableBatch ? ['durable_batch' => true] : []),
                ],
            );
        }
        if ($nextEventState === null) {
            $this->acknowledgeBatchEnd($next);
            $this->pumpNetworkLoop();

            return;
        }
        // Inside a durable batch the next event is already durable with the batch; otherwise
        // this save also makes the next event pending before it is yielded.
        $this->checkpoint = $durableBatch ? $next : $this->checkpointStore->save($next);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private static function compactTradeRow(array $row): array
    {
        return [
            $row['coin'] ?? null,
            $row['side'] ?? null,
            $row['px'] ?? null,
            $row['sz'] ?? null,
            $row['hash'] ?? null,
            $row['time'] ?? null,
            $row['tid'] ?? null,
            $row['users'] ?? null,
        ];
    }

    /**
     * @param list<mixed> $row
     * @return array<string, mixed>
     */
    private static function expandTradeRow(array $row): array
    {
        if (\count($row) !== 8) {
            throw new \LogicException();
        }

        return [
            'coin' => $row[0],
            'side' => $row[1],
            'px' => $row[2],
            'sz' => $row[3],
            'hash' => $row[4],
            'time' => $row[5],
            'tid' => $row[6],
            'users' => $row[7],
        ];
    }

    private function pumpNetworkLoop(): void
    {
        $catchingUp = $this->checkpoint->phase === 'catching_up';
        if (!\in_array($this->checkpoint->phase, ['catching_up', 'streaming'], true)
            || $this->stopped
            || $this->healthyStopRequested
            || $this->transportReadingPaused
            || (!$catchingUp && ($this->queue->count() > 0
                || $this->preReadyFrames !== []
                || $this->deferredDecodedFrames !== []))
        ) {
            return;
        }

        $sliceTimer = $this->loop->addTimer(
            HyperliquidPaperLivePolicy::NETWORK_PUMP_SECONDS,
            function (): void {
                $this->loop->stop();
            },
        );

        try {
            $this->loop->run();
        } finally {
            $this->loop->cancelTimer($sliceTimer);
        }
        if ($catchingUp) {
            $this->acceptQueuedCatchupPong();
        }
    }

    private function acceptQueuedCatchupPong(): void
    {
        $frame = $this->queue->peek();
        if ($frame === null || $this->decoder->decode($frame)['kind'] !== 'pong') {
            return;
        }
        if ($this->queue->dequeue() !== $frame) {
            throw new \LogicException();
        }
        $this->acceptPong();
    }

    private function assertCatchupConnectionActive(): void
    {
        $this->throwPendingTransportFailure();
        if ($this->checkpoint->phase !== 'catching_up') {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_public_trade_gap_unrecoverable',
            );
        }
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        if (\in_array($this->checkpoint->phase, ['streaming', 'stopping'], true)
            && $this->checkpoint->continuity
        ) {
            $this->warn('hyperliquid_paper_public_continuity_lost', [
                'trigger' => 'abnormal_stop',
                'public_reason' => 'hyperliquid_public_trade_gap_unrecoverable',
            ]);
            $this->checkpoint = $this->checkpointStore->save(
                $this->checkpoint->loseContinuity(
                    'hyperliquid_public_trade_gap_unrecoverable',
                ),
            );
        }
        $this->stopped = true;
        $this->activeGeneration = ++$this->generationSequence;
        $this->cancelTimers();
        $this->dropStandby();
        $this->recovery = null;
        $this->heldBooks = [];
        $this->transport->close();
        $this->transportReadingPaused = false;
        $this->queue->clear();
        $this->clearPreReadyFrames();
        $this->loop->stop();
    }

    public function isComplete(): bool
    {
        return $this->checkpoint->phase === 'complete'
            && $this->checkpoint->continuity;
    }

    public function requestHealthyOperatorStop(): void
    {
        if ($this->checkpoint->phase === 'stopping' || $this->healthyStopRequested) {
            return;
        }
        if ($this->checkpoint->phase !== 'streaming'
            || !$this->checkpoint->continuity
            || $this->checkpoint->pendingEvent !== null
            || $this->pendingTransportFailure() !== null
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_healthy_stop_invalid',
            );
        }
        $this->healthyStopRequested = true;
        $this->cancelTimers();
        if ($this->standby !== null) {
            $this->abortRotation('healthy_stop', retry: false);
        }
        $this->transport->stopIngress();
        $this->resumeTransportReadingAfterDrain();
        $this->persistHealthyStopWhenDrained();
        $this->loop->stop();
    }

    private function persistHealthyStopWhenDrained(): void
    {
        if (!$this->healthyStopRequested
            || $this->checkpoint->phase !== 'streaming'
            || $this->checkpoint->pendingEvent !== null
            || $this->transportReadingPaused
            || $this->queue->count() !== 0
            || $this->preReadyFrames !== []
            || $this->deferredDecodedFrames !== []
            || $this->heldBooks !== []
        ) {
            return;
        }
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->requestHealthyStop(),
        );
    }

    public function failureReason(): ?string
    {
        return $this->checkpoint->failureReason;
    }

    private function publicReason(\Throwable $exception): string
    {
        if ($exception->getMessage() === 'hyperliquid_paper_natural_identity_conflict') {
            return 'market_event_identity_conflict';
        }
        if ($exception instanceof HyperliquidPaperLiveIntegrityException
            && preg_match('/\A[a-z][a-z0-9_]{2,127}\z/D', $exception->getMessage()) === 1
        ) {
            return $exception->getMessage();
        }

        return 'hyperliquid_paper_public_protocol_error';
    }

    private function scheduleHeartbeat(): void
    {
        if ($this->heartbeatTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->heartbeatTimer);
        }
        $generation = $this->activeGeneration;
        $this->heartbeatTimer = $this->loop->addTimer(
            HyperliquidPaperLivePolicy::HEARTBEAT_IDLE_SECONDS,
            function () use ($generation): void {
                if ($generation !== $this->activeGeneration
                    || $this->stopped
                    || !\in_array($this->checkpoint->phase, ['catching_up', 'streaming'], true)
                    || $this->isDraining()
                    || $this->recovery !== null
                ) {
                    return;
                }
                try {
                    $this->transport->send(['method' => 'ping']);
                    $now = $this->timestamp($this->clock->now());
                    $deadline = $this->timestamp(
                        $this->clock->now()->modify(
                            '+' . (string) HyperliquidPaperLivePolicy::PONG_TIMEOUT_SECONDS
                                . ' seconds',
                        ),
                    );
                    $this->checkpoint = $this->checkpointStore->save(
                        $this->checkpoint->withHeartbeat(
                            $this->checkpoint->heartbeat['last_received_at'],
                            $now,
                            $deadline,
                        ),
                    );
                    $this->schedulePongTimeout($generation);
                } catch (\Throwable $failure) {
                    $this->failTransport(
                        'heartbeat_ping',
                        new HyperliquidPaperLiveIntegrityException(
                            'hyperliquid_paper_public_protocol_error',
                        ),
                        $failure,
                        keepEarlierFailure: true,
                    );
                    $this->loop->stop();
                }
            },
        );
    }

    private function schedulePongTimeout(int $generation): void
    {
        if ($this->pongTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->pongTimer);
        }
        $this->pongTimer = $this->loop->addTimer(
            HyperliquidPaperLivePolicy::PONG_TIMEOUT_SECONDS,
            function () use ($generation): void {
                if ($generation !== $this->activeGeneration
                    || $this->stopped
                    || !\in_array($this->checkpoint->phase, ['catching_up', 'streaming'], true)
                ) {
                    return;
                }
                $this->pongTimer = null;
                if ($this->checkpoint->phase === 'streaming') {
                    $this->activeLost('pong_timeout');
                } else {
                    $this->failUnrecoverableContinuity('pong_timeout');
                }
                $this->loop->stop();
            },
        );
    }

    private function scheduleFundingRefresh(?float $delay = null): void
    {
        if (!$this->fundingClient instanceof HyperliquidPaperFundingRateClientInterface) {
            return;
        }
        if ($this->fundingTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->fundingTimer);
        }
        $delay ??= HyperliquidPaperLivePolicy::FUNDING_REFRESH_SECONDS;
        $this->fundingDueAt = $this->clock->now()->modify(
            '+' . (string) (int) round($delay * 1_000_000) . ' microseconds',
        );
        $generation = $this->activeGeneration;
        $this->fundingTimer = $this->loop->addTimer(
            $delay,
            function () use ($generation): void {
                if ($generation !== $this->activeGeneration
                    || $this->stopped
                    || $this->checkpoint->phase !== 'streaming'
                ) {
                    return;
                }
                $this->fundingTimer = null;
                $this->fundingRefreshDue = true;
                $this->loop->stop();
            },
        );
    }

    private function acceptPong(): void
    {
        if ($this->checkpoint->heartbeat['pong_deadline_at'] === null
            || (!$this->pongTimer instanceof TimerInterface
                && !$this->healthyStopRequested)
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_message_invalid',
            );
        }
        if ($this->pongTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->pongTimer);
        }
        $this->pongTimer = null;
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->withHeartbeat(
                $this->timestamp($this->clock->now()),
                $this->checkpoint->heartbeat['last_ping_at'],
                null,
            ),
        );
        if (!$this->healthyStopRequested && !$this->isDraining() && $this->recovery === null) {
            $this->scheduleHeartbeat();
        }
    }

    private function isDraining(): bool
    {
        return $this->standby?->state === HyperliquidPaperLiveStandbyConnection::DRAINING;
    }

    private function failUnrecoverableContinuity(string $trigger): void
    {
        $reason = 'hyperliquid_public_trade_gap_unrecoverable';
        $this->warn('hyperliquid_paper_public_continuity_lost', [
            'trigger' => $trigger,
            'public_reason' => $reason,
        ]);
        $this->cancelTimers();
        $this->dropStandby();
        $this->recovery = null;
        $this->heldBooks = [];
        $this->activeGeneration = ++$this->generationSequence;
        $this->transport->close();
        $this->transportReadingPaused = false;
        $this->queue->clear();
        $this->clearPreReadyFrames();
        $this->subscriptions->reset();
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->loseContinuity($reason)->fail($reason),
        );
        $this->transportFailure = new HyperliquidPaperLiveIntegrityException($reason);
    }

    private function cancelTimers(): void
    {
        foreach ([
            'heartbeatTimer', 'pongTimer', 'fundingTimer', 'rotationTimer',
            'recoveryTimer', 'recoveryRetryTimer', 'hotStandbyReopenTimer', 'bookFlushTimer',
        ] as $property) {
            $timer = $this->{$property};
            if ($timer instanceof TimerInterface) {
                $this->loop->cancelTimer($timer);
                $this->{$property} = null;
            }
        }
        $this->fundingRefreshDue = false;
        $this->rotationDue = false;
        $this->recoveryDeadlineReached = false;
        $this->bookFlushDue = false;
    }

    private function shutdownAfterFailure(): void
    {
        $this->stopped = true;
        $this->activeGeneration = ++$this->generationSequence;
        try {
            $this->cancelTimers();
            $this->dropStandby();
            $this->recovery = null;
            $this->heldBooks = [];
            $this->transport->close();
            $this->transportReadingPaused = false;
            $this->queue->clear();
            $this->clearPreReadyFrames();
            $this->loop->stop();
        } catch (\Throwable) {
            // The original stable public failure remains authoritative.
        }
    }

    private function isStandbyGeneration(int $generation): bool
    {
        return !$this->stopped
            && $this->standby !== null
            && $this->standby->generation === $generation;
    }

    private function scheduleRotation(?float $delay = null): void
    {
        if ($this->rotationTransports === null) {
            return;
        }
        if ($this->rotationTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->rotationTimer);
        }
        $delay ??= max(
            0.0,
            $this->connectionRotationSeconds
                - (self::secondsBetween($this->connectedAt, $this->clock->now()) ?? 0.0),
        );
        $generation = $this->activeGeneration;
        $this->rotationTimer = $this->loop->addTimer(
            $delay,
            function () use ($generation): void {
                $this->rotationTimer = null;
                if ($generation !== $this->activeGeneration
                    || $this->stopped
                    || $this->healthyStopRequested
                    || ($this->standby !== null && !$this->standby->hot)
                    || $this->recovery !== null
                    || $this->checkpoint->phase !== 'streaming'
                ) {
                    return;
                }
                $this->rotationDue = true;
                $this->rotationAttention = true;
                $this->loop->stop();
            },
        );
    }

    /**
     * Runs between recorded frames: starts a due rotation, buffers the standby frames, proves
     * the overlap, drains the retiring connection and switches over. During a recovery the
     * retiring connection is already dead, so only its backlog drains and the proof must
     * arrive before the deadline. Never yields events.
     */
    private function advanceRotation(): void
    {
        $standby = $this->standby;
        if ($standby === null) {
            if ($this->recovery !== null) {
                if ($this->recoveryDeadlineReached) {
                    $this->failRecovery('deadline', ['missing_streams' => 'standby_not_connected']);
                }

                return;
            }
            if ($this->rotationDue) {
                $this->rotationDue = false;
                if (!$this->stopped
                    && !$this->healthyStopRequested
                    && $this->checkpoint->phase === 'streaming'
                ) {
                    $this->startRotation();
                }
            }

            return;
        }
        if (!$this->collectStandbyFrames($standby)) {
            return;
        }
        if ($standby->hot) {
            if ($this->rotationDue) {
                $this->rotationDue = false;
                if (!$this->stopped
                    && !$this->healthyStopRequested
                    && $this->checkpoint->phase === 'streaming'
                ) {
                    $this->startRotation();
                }

                return;
            }
            if ($standby->state === HyperliquidPaperLiveStandbyConnection::OPENING) {
                if ($standby->subscriptions->isReady()) {
                    $standby->state = HyperliquidPaperLiveStandbyConnection::OVERLAPPING;
                    $this->hotStandbyFailures = 0;
                    $this->warnRotation('hyperliquid_paper_public_hot_standby_ready', $standby, []);
                } elseif ($standby->timedOut) {
                    $this->abortRotation('standby_subscription_timeout');
                }
            }

            return;
        }
        if ($standby->state === HyperliquidPaperLiveStandbyConnection::OPENING) {
            if ($standby->subscriptions->isReady()) {
                $standby->state = HyperliquidPaperLiveStandbyConnection::OVERLAPPING;
                $this->warnRotation($this->rotationMessage('standby_ready'), $standby, []);
            } elseif ($standby->timedOut) {
                $this->abortRotation('standby_subscription_timeout');

                return;
            } elseif ($this->recovery !== null && $this->recoveryDeadlineReached) {
                $this->failRecovery('deadline', ['missing_streams' => 'standby_not_subscribed']);

                return;
            }
        }
        if ($standby->state === HyperliquidPaperLiveStandbyConnection::OVERLAPPING) {
            $evaluation = $this->evaluateOverlap($standby);
            if ($evaluation['missing'] === []) {
                $this->beginDraining(
                    $standby,
                    $evaluation,
                    $this->recovery === null
                        ? 'overlap_proven'
                        : 'recovered_' . $this->recovery['trigger'],
                );
            } elseif ($this->recovery !== null) {
                if ($this->recoveryDeadlineReached) {
                    $this->failRecovery('overlap_missing', [
                        'missing_streams' => implode(',', $evaluation['missing']),
                    ]);

                    return;
                }
            } elseif ($standby->timedOut) {
                $this->abortRotation('overlap_timeout', [
                    'missing_streams' => implode(',', $evaluation['missing']),
                ]);

                return;
            }
        }
        if ($standby->state !== HyperliquidPaperLiveStandbyConnection::DRAINING) {
            return;
        }
        if ($this->transportReadingPaused) {
            $this->resumeTransportReadingAfterDrain();
        }
        // The retiring connection's books are handed over before the switch (main loop).
        if ($this->queue->count() === 0
            && $this->deferredDecodedFrames === []
            && $this->preReadyFrames === []
            && $this->heldBooks === []
            && !$this->transportReadingPaused
        ) {
            $this->finalizeRotation($standby);
        }
    }

    private function startRotation(): void
    {
        if ($this->recovery !== null) {
            return;
        }
        $factory = $this->rotationTransports
            ?? throw new \LogicException('hyperliquid_paper_live_rotation_disabled');
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->beginRotation($this->timestamp($now)),
        );
        $hot = $this->standby;
        if ($hot !== null) {
            // The permanent standby is promoted: its buffer already holds the overlap.
            $hot->hot = false;
            $hot->promotedAt = $now;
            $this->warnRotation('hyperliquid_paper_public_rotation_started', $hot, [
                'active_connection_age_s' => self::secondsBetween($this->connectedAt, $now),
                'promoted_hot_standby' => true,
            ]);
            $this->armStandbyTimeout($hot, HyperliquidPaperLivePolicy::ROTATION_OVERLAP_TIMEOUT_SECONDS);

            return;
        }
        try {
            $transport = $factory->create($this->loop, $this->config);
        } catch (\Throwable $failure) {
            $this->warn('hyperliquid_paper_public_rotation_aborted', [
                'reason' => 'standby_open_failed',
                'rotation_attempt' => ++$this->rotationAttempts,
                'retry_in_s' => HyperliquidPaperLivePolicy::ROTATION_RETRY_SECONDS,
                ...HyperliquidPaperLiveDiagnostics::exception($failure),
            ]);
            $this->checkpoint = $this->checkpointStore->save($this->checkpoint->abortRotation());
            $this->scheduleRotation(HyperliquidPaperLivePolicy::ROTATION_RETRY_SECONDS);

            return;
        }
        $standby = new HyperliquidPaperLiveStandbyConnection(
            $transport,
            ++$this->generationSequence,
            ++$this->rotationAttempts,
            $now,
        );
        $this->standby = $standby;
        $this->warnRotation('hyperliquid_paper_public_rotation_started', $standby, [
            'active_connection_age_s' => self::secondsBetween($this->connectedAt, $now),
            'promoted_hot_standby' => false,
        ]);
        $this->armStandbyTimeout($standby, HyperliquidPaperLivePolicy::ROTATION_OVERLAP_TIMEOUT_SECONDS);
        try {
            $this->connectTransport($standby->transport, $standby->generation, false);
        } catch (\Throwable $failure) {
            $this->abortRotation(
                'standby_open_failed',
                HyperliquidPaperLiveDiagnostics::exception($failure),
            );
        }
    }

    /**
     * Bounds the wait for a standby: the overlap of a rotation, or the subscriptions of a
     * recovery attempt (a subscribed recovery standby waits for the recovery deadline instead,
     * because a newer connection would get a snapshot that is even further from the loss).
     */
    private function armStandbyTimeout(HyperliquidPaperLiveStandbyConnection $standby, float $seconds): void
    {
        if ($standby->timeoutTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($standby->timeoutTimer);
        }
        $standby->timeoutTimer = $this->loop->addTimer(
            $seconds,
            function () use ($standby): void {
                $standby->timeoutTimer = null;
                if ($this->standby !== $standby
                    || $standby->state === HyperliquidPaperLiveStandbyConnection::DRAINING
                    || ($this->recovery !== null
                        && $standby->state !== HyperliquidPaperLiveStandbyConnection::OPENING)
                ) {
                    return;
                }
                $standby->timedOut = true;
                $this->rotationAttention = true;
                $this->loop->stop();
            },
        );
    }

    private function standbyOpened(): void
    {
        $standby = $this->standby;
        if ($standby === null) {
            return;
        }
        $standby->connectedAt = $this->clock->now();
        try {
            foreach ($standby->subscriptions->subscriptions() as $subscription) {
                $standby->transport->send($subscription);
            }
        } catch (\Throwable $failure) {
            $this->abortRotation(
                'standby_subscribe_failed',
                HyperliquidPaperLiveDiagnostics::exception($failure),
            );
        }
        $this->rotationAttention = true;
        $this->loop->stop();
    }

    private function standbyFrame(#[\SensitiveParameter] string $frame): void
    {
        $standby = $this->standby;
        if ($standby === null) {
            return;
        }
        $standby->lastFrameAt = $this->clock->now();
        ++$standby->frames;
        try {
            $standby->queue->enqueue($frame);
        } catch (\Throwable $failure) {
            $this->standbyLost(
                'standby_backpressure',
                HyperliquidPaperLiveDiagnostics::exception($failure),
            );

            return;
        }
        $this->rotationAttention = true;
        $this->loop->stop();
    }

    /**
     * Decodes the buffered standby frames into the overlap backlog. Returns false when the
     * standby had to be abandoned (or, while switching, when continuity was lost).
     */
    private function collectStandbyFrames(HyperliquidPaperLiveStandbyConnection $standby): bool
    {
        try {
            $at = (float) $this->clock->now()->format('U.u');
            while (($frame = $standby->queue->dequeue()) !== null) {
                $decoded = $standby->decoder->decode($frame);
                if ($decoded['kind'] === 'subscription' || $decoded['kind'] === 'pong') {
                    continue;
                }
                $item = ['decoded' => $decoded, 'frame' => $frame, 'at' => $at];
                if ($decoded['kind'] === 'trades') {
                    $rows = $decoded['data'] ?? null;
                    if (!\is_array($rows)) {
                        throw new \LogicException();
                    }
                    $fingerprints = [];
                    $snapshot = null;
                    foreach ($rows as $row) {
                        if (!\is_array($row)) {
                            throw new \LogicException();
                        }
                        $fingerprints[] = $this->normalizer->liveTradeFingerprint($row);
                        $time = $row['time'] ?? null;
                        if (\is_int($time)) {
                            $snapshot = [
                                'rows' => ($snapshot['rows'] ?? 0) + 1,
                                'first_time' => min($snapshot['first_time'] ?? $time, $time),
                                'last_time' => max($snapshot['last_time'] ?? $time, $time),
                            ];
                        }
                    }
                    $item['fingerprints'] = $fingerprints;
                    $coin = $rows[0]['coin'] ?? null;
                    if ($snapshot !== null && \is_string($coin)) {
                        if (!isset($standby->snapshots[$coin])) {
                            $standby->snapshots[$coin] = $snapshot;
                        } elseif (!isset($standby->firstLiveTradeTimes[$coin])) {
                            $standby->firstLiveTradeTimes[$coin] = $snapshot['first_time'];
                        }
                    }
                }
                $standby->items[] = $item;
                if (\count($standby->items) > self::MAX_STANDBY_ITEMS) {
                    throw new HyperliquidPaperLiveIntegrityException(
                        'market_data_backpressure_exhausted',
                    );
                }
            }
            if ($standby->hot) {
                $this->pruneHotStandby($standby, $at);
            }
        } catch (\Throwable $failure) {
            if ($standby->state === HyperliquidPaperLiveStandbyConnection::DRAINING) {
                $this->warnRotation('hyperliquid_paper_public_rotation_failed', $standby, [
                    'reason' => 'standby_protocol_error',
                    ...HyperliquidPaperLiveDiagnostics::exception($failure),
                ]);
                $this->failUnrecoverableContinuity('standby_lost_during_switch');
            } else {
                $this->abortRotation(
                    'standby_protocol_error',
                    HyperliquidPaperLiveDiagnostics::exception($failure),
                );
            }

            return false;
        }

        return true;
    }

    /**
     * @return array{
     *     missing: list<string>,
     *     trade_cuts: array<string, int>,
     *     anchors: array<string, string>,
     *     trades: array<string, array{rows: int, first_time: int, last_time: int}>,
     *     first_books: array<string, int>,
     *     first_candles: array<string, array{int, int}>
     * }
     */
    private function evaluateOverlap(HyperliquidPaperLiveStandbyConnection $standby): array
    {
        return HyperliquidPaperLiveRotationOverlap::evaluate(
            $standby->items,
            $this->emittedTradeIdentities(),
            $this->lastBookTimes,
            $this->checkpoint->currentCandles,
            $this->checkpoint->finalizedCandleFrontiers,
            tradesOnly: $this->recovery !== null,
        );
    }

    /** @return array<string, string> identity hash => assignment digest of the emitted trades */
    private function emittedTradeIdentities(): array
    {
        $emitted = [];
        foreach ($this->checkpoint->tradeIdentityHistory as $entry) {
            $emitted[$entry['identity_hash']] = $entry['assignment_digest'];
        }

        return $emitted;
    }

    /**
     * Every stream is proven: stop the retiring connection's ingress and record what it
     * already received before switching over.
     *
     * @param array{missing: list<string>, trade_cuts: array<string, int>, anchors: array<string, string>} $evaluation
     */
    private function beginDraining(
        HyperliquidPaperLiveStandbyConnection $standby,
        array $evaluation,
        string $cause,
    ): void {
        $standby->state = HyperliquidPaperLiveStandbyConnection::DRAINING;
        if ($standby->timeoutTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($standby->timeoutTimer);
            $standby->timeoutTimer = null;
        }
        if ($this->heartbeatTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->heartbeatTimer);
            $this->heartbeatTimer = null;
        }
        $this->transport->stopIngress();
        $this->warnRotation($this->rotationMessage('draining'), $standby, [
            'cause' => $cause,
            'trade_anchor_btc' => $evaluation['anchors']['BTC'] ?? null,
            'trade_anchor_eth' => $evaluation['anchors']['ETH'] ?? null,
        ]);
    }

    private function finalizeRotation(HyperliquidPaperLiveStandbyConnection $standby): void
    {
        if (!$this->collectStandbyFrames($standby)) {
            return;
        }
        $evaluation = $this->evaluateOverlap($standby);
        if (isset($evaluation['anchors']['BTC'], $evaluation['anchors']['ETH']) === false) {
            $this->warnRotation($this->rotationMessage('failed'), $standby, [
                'reason' => 'overlap_lost',
                'missing_streams' => implode(',', $evaluation['missing']),
            ]);
            $this->failUnrecoverableContinuity('rotation_overlap_lost');

            return;
        }
        $continuation = HyperliquidPaperLiveRotationOverlap::continuation(
            $standby->items,
            $evaluation['trade_cuts'],
            $this->lastBookTimes,
            $this->checkpoint->currentCandles,
            $this->checkpoint->finalizedCandleFrontiers,
        );
        $now = $this->clock->now();
        $this->warnRotation($this->rotationMessage('completed'), $standby, [
            'overlap_s' => self::secondsBetween($standby->promotedAt ?? $standby->startedAt, $now),
            'continuation_kept' => $continuation['kept'],
            'continuation_dropped' => $continuation['dropped'],
            'trade_anchor_btc' => $evaluation['anchors']['BTC'],
            'trade_anchor_eth' => $evaluation['anchors']['ETH'],
            ...$this->recoveryGapContext($standby, $evaluation),
        ]);

        $retired = $this->transport;
        $this->standby = null;
        $this->clearRecovery();
        foreach (['heartbeatTimer', 'pongTimer', 'rotationTimer'] as $property) {
            $timer = $this->{$property};
            if ($timer instanceof TimerInterface) {
                $this->loop->cancelTimer($timer);
                $this->{$property} = null;
            }
        }
        if ($standby->timeoutTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($standby->timeoutTimer);
            $standby->timeoutTimer = null;
        }
        $this->transport = $standby->transport;
        $this->queue = $standby->queue;
        $this->subscriptions = $standby->subscriptions;
        $this->decoder = $standby->decoder;
        $this->activeGeneration = $standby->generation;
        $this->connectRequestedAt = $standby->startedAt;
        $this->connectedAt = $standby->connectedAt;
        $this->lastFrameAt = $standby->lastFrameAt;
        $this->connectionFrames = $standby->frames;
        $this->transportReadingPaused = false;
        $this->deferredDecodedFrames = [...$continuation['items'], ...$this->deferredDecodedFrames];
        try {
            $retired->close();
        } catch (\Throwable) {
            // The retired connection is no longer read; closing it is best-effort.
        }
        $this->checkpoint = $this->checkpointStore->save($this->checkpoint->completeRotation());
        $this->scheduleHeartbeat();
        if ($this->fundingTimer instanceof TimerInterface) {
            $this->scheduleFundingRefresh(max(
                0.0,
                self::secondsBetween($now, $this->fundingDueAt) ?? 0.0,
            ));
        }
        $this->scheduleRotation();
        $this->openHotStandby();
    }

    /**
     * Opens the permanent standby that will serve the next switch. Failures to open are
     * logged and retried with a growing delay while the active connection is healthy.
     */
    private function openHotStandby(): void
    {
        $factory = $this->rotationTransports;
        if (!$this->hotStandby
            || $factory === null
            || $this->stopped
            || $this->healthyStopRequested
            || $this->standby !== null
            || $this->recovery !== null
            || $this->checkpoint->phase !== 'streaming'
        ) {
            return;
        }
        if ($this->hotStandbyReopenTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->hotStandbyReopenTimer);
            $this->hotStandbyReopenTimer = null;
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            $transport = $factory->create($this->loop, $this->config);
        } catch (\Throwable $failure) {
            $delays = HyperliquidPaperLivePolicy::HOT_STANDBY_REOPEN_DELAYS_SECONDS;
            $delay = $delays[min($this->hotStandbyFailures, \count($delays) - 1)];
            $this->warn('hyperliquid_paper_public_hot_standby_lost', [
                'reason' => 'standby_open_failed',
                'retry_in_s' => $delay,
                ...HyperliquidPaperLiveDiagnostics::exception($failure),
            ]);
            ++$this->hotStandbyFailures;
            $this->scheduleHotStandbyReopen($delay);

            return;
        }
        $standby = new HyperliquidPaperLiveStandbyConnection(
            $transport,
            ++$this->generationSequence,
            ++$this->hotStandbySequence,
            $now,
        );
        $standby->hot = true;
        $this->standby = $standby;
        $this->warnRotation('hyperliquid_paper_public_hot_standby_opened', $standby, [
            'active_connection_age_s' => self::secondsBetween($this->connectedAt, $now),
            'consecutive_failures' => $this->hotStandbyFailures,
        ]);
        $this->armStandbyTimeout($standby, HyperliquidPaperLivePolicy::ROTATION_OVERLAP_TIMEOUT_SECONDS);
        try {
            $this->connectTransport($standby->transport, $standby->generation, false);
        } catch (\Throwable $failure) {
            $this->abortRotation(
                'standby_open_failed',
                HyperliquidPaperLiveDiagnostics::exception($failure),
            );
        }
    }

    private function scheduleHotStandbyReopen(float $delay): void
    {
        if ($this->hotStandbyReopenTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->hotStandbyReopenTimer);
        }
        $this->hotStandbyReopenTimer = $this->loop->addTimer($delay, function (): void {
            $this->hotStandbyReopenTimer = null;
            $this->openHotStandby();
            $this->rotationAttention = true;
            $this->loop->stop();
        });
    }

    /**
     * Keeps the permanent standby's buffer bounded: items older than the window go, except
     * the most recent ones and, per stream, everything up to the newest item the active
     * connection has already caught up with (an emitted trade row, a book not newer than
     * the last recorded one, a candle state not newer than the current one). The proof's
     * anchor therefore never leaves the buffer, and a quiet stream keeps its last state.
     */
    private function pruneHotStandby(HyperliquidPaperLiveStandbyConnection $standby, float $now): void
    {
        $items = $standby->items;
        $count = \count($items);
        $prunable = $count - HyperliquidPaperLivePolicy::HOT_STANDBY_MIN_ITEMS;
        if ($prunable <= 0 || $now - $standby->prunedAt < 1.0) {
            return;
        }
        $crowded = $count > HyperliquidPaperLivePolicy::HOT_STANDBY_SOFT_MAX_ITEMS;
        $horizon = $now - HyperliquidPaperLivePolicy::HOT_STANDBY_WINDOW_SECONDS;
        if (!$crowded && ($items[$prunable - 1]['at'] ?? $now) >= $horizon) {
            return;
        }
        $standby->prunedAt = $now;
        $emitted = null;
        $caughtUp = [];
        $drop = [];
        for ($index = $count - 1; $index >= 0; --$index) {
            $item = $items[$index];
            $key = self::standbyItemKey($item);
            if ($index < $prunable
                && ($crowded || ($item['at'] ?? $now) < $horizon)
                && isset($caughtUp[$key])
            ) {
                $drop[$index] = true;

                continue;
            }
            if (isset($caughtUp[$key])) {
                continue;
            }
            $data = $item['decoded']['data'] ?? null;
            switch ($item['decoded']['kind']) {
                case 'trades':
                    $emitted ??= $this->emittedTradeIdentities();
                    foreach ($item['fingerprints'] ?? [] as $fingerprint) {
                        if (isset($emitted[$fingerprint['identity_hash']])) {
                            $caughtUp[$key] = true;
                            break;
                        }
                    }
                    break;
                case 'book':
                    $coin = \is_array($data) ? ($data['coin'] ?? null) : null;
                    $time = \is_array($data) ? ($data['time'] ?? null) : null;
                    if (\is_string($coin) && \is_int($time) && $time <= ($this->lastBookTimes[$coin] ?? -1)) {
                        $caughtUp[$key] = true;
                    }
                    break;
                case 'candle':
                    $stream = substr($key, 7);
                    $current = $this->checkpoint->currentCandles[$stream] ?? null;
                    $frontier = $this->checkpoint->finalizedCandleFrontiers[$stream] ?? null;
                    $start = \is_array($data) ? ($data['t'] ?? null) : null;
                    $trades = \is_array($data) ? ($data['n'] ?? null) : null;
                    if (\is_int($start) && \is_int($trades) && (
                        ($frontier !== null && $start <= $frontier)
                        || ($current !== null && [$start, $trades] <= [$current['t'] ?? -1, $current['n'] ?? -1])
                    )) {
                        $caughtUp[$key] = true;
                    }
                    break;
                default:
                    $caughtUp[$key] = true;
            }
        }
        if ($drop === []) {
            return;
        }
        $kept = [];
        foreach ($items as $index => $item) {
            if (!isset($drop[$index])) {
                $kept[] = $item;
            }
        }
        $standby->items = $kept;
        $standby->prunedItems += \count($drop);
    }

    /** @param array{decoded: array{kind: string, data?: mixed}} $item */
    private static function standbyItemKey(array $item): string
    {
        $data = $item['decoded']['data'] ?? null;
        $kind = $item['decoded']['kind'];
        if (!\is_array($data)) {
            return $kind;
        }
        if ($kind === 'trades') {
            $first = $data[0] ?? null;

            return 'trades/' . (\is_array($first) && \is_string($first['coin'] ?? null) ? $first['coin'] : '?');
        }
        if ($kind === 'book') {
            return 'book/' . (\is_string($data['coin'] ?? null) ? $data['coin'] : '?');
        }
        if ($kind === 'candle') {
            return 'candle/' . (\is_string($data['s'] ?? null) ? $data['s'] : '?')
                . '/' . (\is_string($data['i'] ?? null) ? $data['i'] : '?');
        }

        return $kind;
    }

    /**
     * Abandons the standby. A rotation retries 30 s later while the active connection is
     * healthy; a recovery attempt retries 1 s later within its bounded attempts and deadline.
     *
     * @param array<string, mixed> $context
     */
    private function abortRotation(string $reason, array $context = [], bool $retry = true): void
    {
        $standby = $this->standby;
        if ($standby === null) {
            return;
        }
        $retry = $retry && !$this->stopped && !$this->healthyStopRequested;
        if ($standby->hot) {
            $delays = HyperliquidPaperLivePolicy::HOT_STANDBY_REOPEN_DELAYS_SECONDS;
            $delay = $delays[min($this->hotStandbyFailures, \count($delays) - 1)];
            $this->warnRotation('hyperliquid_paper_public_hot_standby_lost', $standby, [
                'reason' => $reason,
                'retry_in_s' => $retry ? $delay : null,
                ...$context,
            ]);
            $this->dropStandby();
            if ($retry) {
                ++$this->hotStandbyFailures;
                $this->scheduleHotStandbyReopen($delay);
            }

            return;
        }
        if ($this->recovery !== null) {
            $retry = $retry
                && !$this->recoveryDeadlineReached
                && $this->recovery['attempts'] < HyperliquidPaperLivePolicy::RECOVERY_MAX_ATTEMPTS;
            $this->warnRotation('hyperliquid_paper_public_recovery_attempt_aborted', $standby, [
                'reason' => $reason,
                'retry_in_s' => $retry ? HyperliquidPaperLivePolicy::RECOVERY_RETRY_SECONDS : null,
                ...$context,
            ]);
            $this->dropStandby();
            if ($retry) {
                $this->scheduleRecoveryRetry();
            } elseif ($this->stopped || $this->healthyStopRequested) {
                $this->clearRecovery();
                if ($this->checkpoint->rotation !== null
                    && !\in_array($this->checkpoint->phase, ['failed', 'complete'], true)
                ) {
                    $this->checkpoint = $this->checkpointStore->save($this->checkpoint->abortRotation());
                }
            } else {
                $this->failRecovery(
                    $this->recoveryDeadlineReached ? 'deadline' : 'attempts_exhausted',
                    ['last_attempt_reason' => $reason],
                );
            }

            return;
        }
        $this->warnRotation('hyperliquid_paper_public_rotation_aborted', $standby, [
            'reason' => $reason,
            'retry_in_s' => $retry ? HyperliquidPaperLivePolicy::ROTATION_RETRY_SECONDS : null,
            ...$context,
        ]);
        $this->dropStandby();
        if ($this->checkpoint->rotation !== null
            && !\in_array($this->checkpoint->phase, ['failed', 'complete'], true)
        ) {
            $this->checkpoint = $this->checkpointStore->save($this->checkpoint->abortRotation());
        }
        if ($retry) {
            $this->scheduleRotation(HyperliquidPaperLivePolicy::ROTATION_RETRY_SECONDS);
        }
    }

    /** @param array<string, mixed> $context */
    private function standbyLost(string $reason, array $context): void
    {
        $standby = $this->standby;
        if ($standby === null) {
            return;
        }
        if ($standby->state === HyperliquidPaperLiveStandbyConnection::DRAINING) {
            $this->warnRotation('hyperliquid_paper_public_rotation_failed', $standby, [
                'reason' => $reason,
                ...$context,
            ]);
            $this->failUnrecoverableContinuity('standby_lost_during_switch');
        } else {
            $this->abortRotation($reason, $context);
        }
        $this->rotationAttention = true;
        $this->loop->stop();
    }

    /**
     * The active connection is gone while streaming. A proven standby takes over at once;
     * otherwise a recovery races the trades snapshot of a replacement connection (an
     * unproven rotation standby is adopted as its first attempt).
     *
     * @param array<string, mixed> $context loss diagnostics (close code, exception...)
     */
    private function activeLost(string $trigger, array $context = []): void
    {
        $standby = $this->standby;
        if ($this->recovery !== null
            || $standby?->state === HyperliquidPaperLiveStandbyConnection::DRAINING
        ) {
            return;
        }
        if ($standby !== null
            && $this->collectStandbyFrames($standby)
            && $standby->subscriptions->isReady()
        ) {
            $evaluation = $this->evaluateOverlap($standby);
            if ($evaluation['missing'] === []) {
                if ($this->checkpoint->rotation === null) {
                    // A permanent standby takes over: the switch becomes durable first.
                    $this->checkpoint = $this->checkpointStore->save($this->checkpoint->beginRotation(
                        $this->timestamp(\DateTimeImmutable::createFromInterface($this->clock->now())),
                        HyperliquidPaperLiveCheckpoint::ROTATION_CAUSE_RECOVERY,
                    ));
                }
                if ($standby->hot) {
                    $standby->hot = false;
                    $standby->promotedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
                    $this->warn('hyperliquid_paper_public_hot_standby_takeover', [
                        'trigger' => $trigger,
                        ...$context,
                        'last_trade_age_btc_s' => $this->lastTradeAge('BTC', $standby->promotedAt),
                        'last_trade_age_eth_s' => $this->lastTradeAge('ETH', $standby->promotedAt),
                    ]);
                }
                $this->beginDraining($standby, $evaluation, 'active_lost_' . $trigger);
                $this->rotationAttention = true;

                return;
            }
        }
        if ($this->rotationTransports === null || $this->checkpoint->phase !== 'streaming') {
            // No replacement connection can be opened: continuity is lost, as before.
            $this->failUnrecoverableContinuity($trigger);

            return;
        }
        $this->startRecovery($trigger, $context);
    }

    /** @param array<string, mixed> $context */
    private function startRecovery(string $trigger, array $context): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $adopted = $this->standby;
        if ($adopted !== null) {
            $adopted->hot = false;
            $adopted->promotedAt = $now;
        }
        $this->recovery = [
            'trigger' => $trigger,
            'lost_at' => $now,
            'attempts' => $adopted === null ? 0 : 1,
            'adopted' => $adopted !== null,
        ];
        foreach (['heartbeatTimer', 'pongTimer', 'rotationTimer'] as $property) {
            $timer = $this->{$property};
            if ($timer instanceof TimerInterface) {
                $this->loop->cancelTimer($timer);
                $this->{$property} = null;
            }
        }
        $this->rotationDue = false;
        try {
            $this->transport->stopIngress();
        } catch (\Throwable) {
            // The connection is already gone; nothing more can arrive from it.
        }
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->rotation === null
                ? $this->checkpoint->beginRotation(
                    $this->timestamp($now),
                    HyperliquidPaperLiveCheckpoint::ROTATION_CAUSE_RECOVERY,
                )
                : $this->checkpoint->markRotationRecovery(),
        );
        $this->warn('hyperliquid_paper_public_recovery_started', [
            'trigger' => $trigger,
            ...$context,
            'adopted_standby' => $adopted !== null,
            'adopted_standby_state' => $adopted?->state,
            'deadline_in_s' => HyperliquidPaperLivePolicy::RECOVERY_DEADLINE_SECONDS,
            'last_trade_age_btc_s' => $this->lastTradeAge('BTC', $now),
            'last_trade_age_eth_s' => $this->lastTradeAge('ETH', $now),
        ]);
        $this->recoveryDeadlineReached = false;
        $this->recoveryTimer = $this->loop->addTimer(
            HyperliquidPaperLivePolicy::RECOVERY_DEADLINE_SECONDS,
            function (): void {
                $this->recoveryTimer = null;
                if ($this->recovery === null || $this->stopped) {
                    return;
                }
                $this->recoveryDeadlineReached = true;
                $this->rotationAttention = true;
                $this->loop->stop();
            },
        );
        if ($adopted !== null) {
            $this->armStandbyTimeout($adopted, HyperliquidPaperLivePolicy::RECOVERY_ATTEMPT_TIMEOUT_SECONDS);
        } else {
            $this->startRecoveryAttempt();
        }
        $this->rotationAttention = true;
    }

    private function startRecoveryAttempt(): void
    {
        $recovery = $this->recovery;
        if ($recovery === null || $this->standby !== null || $this->stopped) {
            return;
        }
        $factory = $this->rotationTransports;
        if ($factory === null) {
            $this->failRecovery('recovery_disabled', []);

            return;
        }
        $recovery['attempts'] = $recovery['attempts'] + 1;
        $this->recovery = $recovery;
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            $transport = $factory->create($this->loop, $this->config);
        } catch (\Throwable $failure) {
            $this->warn('hyperliquid_paper_public_recovery_attempt_aborted', [
                'reason' => 'standby_open_failed',
                'rotation_attempt' => $recovery['attempts'],
                'recovery_trigger' => $recovery['trigger'],
                'since_loss_s' => self::secondsBetween($recovery['lost_at'], $now),
                'retry_in_s' => HyperliquidPaperLivePolicy::RECOVERY_RETRY_SECONDS,
                ...HyperliquidPaperLiveDiagnostics::exception($failure),
            ]);
            $this->scheduleRecoveryRetry();

            return;
        }
        $standby = new HyperliquidPaperLiveStandbyConnection(
            $transport,
            ++$this->generationSequence,
            $recovery['attempts'],
            $now,
        );
        $this->standby = $standby;
        $this->warnRotation('hyperliquid_paper_public_recovery_attempt', $standby, []);
        $this->armStandbyTimeout($standby, HyperliquidPaperLivePolicy::RECOVERY_ATTEMPT_TIMEOUT_SECONDS);
        try {
            $this->connectTransport($standby->transport, $standby->generation, false);
        } catch (\Throwable $failure) {
            $this->abortRotation(
                'standby_open_failed',
                HyperliquidPaperLiveDiagnostics::exception($failure),
            );
        }
    }

    private function scheduleRecoveryRetry(): void
    {
        $recovery = $this->recovery;
        if ($recovery === null) {
            return;
        }
        if ($recovery['attempts'] >= HyperliquidPaperLivePolicy::RECOVERY_MAX_ATTEMPTS) {
            $this->failRecovery('attempts_exhausted', []);

            return;
        }
        if ($this->recoveryRetryTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->recoveryRetryTimer);
        }
        $this->recoveryRetryTimer = $this->loop->addTimer(
            HyperliquidPaperLivePolicy::RECOVERY_RETRY_SECONDS,
            function (): void {
                $this->recoveryRetryTimer = null;
                if ($this->recovery === null || $this->standby !== null || $this->stopped) {
                    return;
                }
                if ($this->recoveryDeadlineReached) {
                    $this->failRecovery('deadline', ['missing_streams' => 'standby_not_connected']);
                } else {
                    $this->startRecoveryAttempt();
                }
                $this->rotationAttention = true;
                $this->loop->stop();
            },
        );
    }

    /**
     * The lost connection could not be continued: the reason, the streams that could not be
     * proven and the measured hole are logged before failing closed as before.
     *
     * @param array<string, mixed> $context
     */
    private function failRecovery(string $reason, array $context): void
    {
        $recovery = $this->recovery;
        if ($recovery === null) {
            return;
        }
        $standby = $this->standby;
        $evaluation = null;
        if ($standby !== null && $standby->subscriptions->isReady()) {
            try {
                $evaluation = $this->evaluateOverlap($standby);
            } catch (\Throwable) {
                // The diagnostics stay best-effort; the failure below is what matters.
            }
        }
        $details = [
            'reason' => $reason,
            ...$context,
            ...($standby === null ? [] : $this->recoveryGapContext($standby, $evaluation)),
        ];
        if ($standby !== null) {
            $this->warnRotation('hyperliquid_paper_public_recovery_failed', $standby, $details);
        } else {
            $this->warn('hyperliquid_paper_public_recovery_failed', [
                ...$details,
                'recovery_trigger' => $recovery['trigger'],
                'rotation_attempt' => $recovery['attempts'],
                'since_loss_s' => self::secondsBetween($recovery['lost_at'], $this->clock->now()),
            ]);
        }
        $this->clearRecovery();
        $this->failUnrecoverableContinuity($recovery['trigger'] . '_unrecovered');
    }

    private function clearRecovery(): void
    {
        $this->recovery = null;
        $this->recoveryDeadlineReached = false;
        foreach (['recoveryTimer', 'recoveryRetryTimer'] as $property) {
            $timer = $this->{$property};
            if ($timer instanceof TimerInterface) {
                $this->loop->cancelTimer($timer);
                $this->{$property} = null;
            }
        }
    }

    /**
     * The measured margin of a recovery, per coin: the hole between the last trade the lost
     * connection delivered and the first trade the standby got (negative = overlap), the
     * snapshot size, and the sample gaps of the state streams.
     *
     * @param array{trades: array<string, array{rows: int, first_time: int, last_time: int}>, first_books: array<string, int>, first_candles: array<string, array{int, int}>}|null $evaluation
     * @return array<string, mixed>
     */
    private function recoveryGapContext(
        HyperliquidPaperLiveStandbyConnection $standby,
        ?array $evaluation,
    ): array {
        $context = [];
        $closedInGap = [];
        foreach (['BTC', 'ETH'] as $coin) {
            $key = strtolower($coin);
            $snapshot = $standby->snapshots[$coin] ?? null;
            $lastTrade = $this->lastTradeTimes[$coin] ?? null;
            $context['trade_gap_' . $key . '_s'] = $snapshot === null || $lastTrade === null
                ? null
                : round(($snapshot['first_time'] - $lastTrade) / 1_000, 3);
            $context['snapshot_rows_' . $key] = $snapshot['rows'] ?? 0;
            $context['snapshot_span_' . $key . '_s'] = $snapshot === null
                ? null
                : round(($snapshot['last_time'] - $snapshot['first_time']) / 1_000, 3);
            $firstLive = $standby->firstLiveTradeTimes[$coin] ?? null;
            $context['subscription_crack_' . $key . '_s'] = $snapshot === null || $firstLive === null
                ? null
                : round(($firstLive - $snapshot['last_time']) / 1_000, 3);
            $firstBook = $evaluation['first_books'][$coin] ?? null;
            $lastBook = $this->lastBookTimes[$coin] ?? null;
            $context['book_gap_' . $key . '_s'] = $firstBook === null || $lastBook === null
                ? null
                : round(($firstBook - $lastBook) / 1_000, 3);
            foreach (['1m', '5m', '15m', '1h'] as $interval) {
                $stream = $coin . '/' . $interval;
                $first = $evaluation['first_candles'][$stream] ?? null;
                $current = $this->checkpoint->currentCandles[$stream]['t'] ?? null;
                if ($first !== null && \is_int($current) && $first[0] > $current) {
                    $closedInGap[] = $stream;
                }
            }
        }
        $context['candles_closed_in_gap'] = implode(',', $closedInGap);

        return $context;
    }

    private function lastTradeAge(string $coin, \DateTimeImmutable $now): ?float
    {
        $time = $this->lastTradeTimes[$coin] ?? null;
        if ($time === null) {
            return null;
        }

        return round(((int) $now->format('Uv') - $time) / 1_000, 3);
    }

    private function rotationMessage(string $suffix): string
    {
        return 'hyperliquid_paper_public_'
            . ($this->recovery === null ? 'rotation_' : 'recovery_')
            . $suffix;
    }

    private function dropStandby(): void
    {
        $standby = $this->standby;
        if ($standby === null) {
            return;
        }
        $this->standby = null;
        if ($standby->timeoutTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($standby->timeoutTimer);
            $standby->timeoutTimer = null;
        }
        try {
            $standby->transport->close();
        } catch (\Throwable) {
            // An abandoned standby is never read again.
        }
    }

    /** @param array<string, mixed> $context */
    private function warnRotation(
        string $message,
        HyperliquidPaperLiveStandbyConnection $standby,
        array $context,
    ): void {
        try {
            $now = $this->clock->now();
            $recovery = $this->recovery;
            $this->warn($message, [
                ...$context,
                'rotation_attempt' => $standby->attempt,
                'rotation_target_epoch' => $this->checkpoint->connectionEpoch + 1,
                'recovery_trigger' => $recovery['trigger'] ?? null,
                'since_loss_s' => $recovery === null
                    ? null
                    : self::secondsBetween($recovery['lost_at'], $now),
                'standby_state' => $standby->state,
                'standby_hot' => $standby->hot,
                'standby_connection_age_s' => self::secondsBetween($standby->connectedAt, $now),
                'standby_elapsed_s' => self::secondsBetween($standby->startedAt, $now),
                'standby_frames' => $standby->frames,
                'standby_items' => \count($standby->items),
                'standby_pruned_items' => $standby->prunedItems,
                'standby_buffer_s' => isset($standby->items[0]['at'])
                    ? round((float) $now->format('U.u') - $standby->items[0]['at'], 3)
                    : null,
            ]);
        } catch (\Throwable) {
            // Diagnostics are best-effort and must not control the capture.
        }
    }

    private function failTransport(
        string $trigger,
        \Throwable $failure,
        ?\Throwable $cause = null,
        bool $keepEarlierFailure = false,
    ): void {
        $this->warn('hyperliquid_paper_public_transport_failed', [
            'trigger' => $trigger,
            'public_reason' => $this->publicReason($failure),
            'earlier_failure_kept' => $keepEarlierFailure && $this->transportFailure !== null,
            ...($cause === null ? [] : HyperliquidPaperLiveDiagnostics::exception($cause)),
        ]);
        if (!$keepEarlierFailure || $this->transportFailure === null) {
            $this->transportFailure = $failure;
        }
    }

    /**
     * Best-effort warning with the connection's state. Diagnostics never change the
     * capture outcome, so a failing logger or clock is ignored.
     *
     * @param array<string, mixed> $context
     */
    private function warn(string $message, array $context): void
    {
        try {
            $now = $this->clock->now();
            $heartbeat = $this->checkpoint->heartbeat;
            $pongDeadline = self::heartbeatTime($heartbeat['pong_deadline_at']);
            $this->logger->warning($message, [
                ...$context,
                'dataset_id' => $this->checkpoint->datasetId,
                'phase' => $this->checkpoint->phase,
                'connection_epoch' => $this->checkpoint->connectionEpoch,
                'source_epoch' => $this->checkpoint->sourceEpoch,
                'connection_age_s' => self::secondsBetween($this->connectedAt, $now),
                'connect_pending_s' => $this->connectedAt === null
                    ? self::secondsBetween($this->connectRequestedAt, $now)
                    : null,
                'frames_received' => $this->connectionFrames,
                'last_frame_age_s' => self::secondsBetween($this->lastFrameAt, $now),
                'last_ping_age_s' => self::secondsBetween(
                    self::heartbeatTime($heartbeat['last_ping_at']),
                    $now,
                ),
                'last_pong_age_s' => self::secondsBetween(
                    self::heartbeatTime($heartbeat['last_received_at']),
                    $now,
                ),
                'pong_deadline_in_s' => self::secondsBetween($now, $pongDeadline),
                'queued_frames' => $this->queue->count(),
                'reading_paused' => $this->transportReadingPaused,
            ]);
        } catch (\Throwable) {
            // Diagnostics are best-effort and must not control the capture.
        }
    }

    private static function heartbeatTime(?string $timestamp): ?\DateTimeImmutable
    {
        if ($timestamp === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.u\Z',
            $timestamp,
            new \DateTimeZone('UTC'),
        );

        return $parsed === false ? null : $parsed;
    }

    private static function secondsBetween(
        ?\DateTimeInterface $earlier,
        ?\DateTimeInterface $later,
    ): ?float {
        if ($earlier === null || $later === null) {
            return null;
        }

        return round(
            (float) $later->format('U.u') - (float) $earlier->format('U.u'),
            3,
        );
    }

    private function timestamp(\DateTimeInterface $timestamp): string
    {
        return \DateTimeImmutable::createFromInterface($timestamp)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
