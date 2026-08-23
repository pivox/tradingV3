<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperFundingRateClientInterface;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperMarketEventNormalizer;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\ClockInterface;

final class HyperliquidPaperPublicLiveSource implements PaperLiveMarketDataSourceInterface
{
    private readonly HyperliquidPaperPublicSubscriptionSet $subscriptions;
    private readonly HyperliquidPaperPublicFrameDecoder $decoder;
    private readonly HyperliquidPaperPublicFrameQueue $queue;
    private readonly HyperliquidPaperSourceOrdinal $ordinals;
    private readonly HyperliquidPaperMarketEventNormalizer $normalizer;

    private ?\Throwable $transportFailure = null;
    private bool $stopped = false;
    private int $activeGeneration = 0;
    private ?TimerInterface $heartbeatTimer = null;
    private ?TimerInterface $pongTimer = null;
    private ?TimerInterface $reconnectTimer = null;
    private ?TimerInterface $fundingTimer = null;
    private bool $fundingRefreshDue = false;

    public function __construct(
        private readonly HyperliquidPaperPublicWebSocketTransportInterface $transport,
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
    ) {
        if ($config->network !== $checkpoint->network) {
            throw new \InvalidArgumentException('hyperliquid_paper_live_checkpoint_mismatch');
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
                throw new HyperliquidPaperLiveIntegrityException($reason);
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
            $this->checkpoint = $this->checkpointStore->save(
                $this->checkpoint->loseContinuity(
                    'hyperliquid_public_trade_gap_unrecoverable',
                ),
            );

            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_public_trade_gap_unrecoverable',
            );
        }
        if (\in_array(
            $this->checkpoint->phase,
            ['fresh', 'connecting', 'subscribing'],
            true,
        )) {
            if ($this->checkpoint->phase !== 'fresh') {
                $this->checkpoint = $this->checkpointStore->save(
                    $this->checkpoint->withPhase('fresh'),
                );
            }
            $this->connectAndSubscribe();
            $this->awaitSubscriptions();
            $this->throwPendingTransportFailure();
            $this->checkpoint = $this->checkpointStore->save(
                $this->checkpoint->withPhase('streaming'),
            );
            $this->scheduleHeartbeat();
            $this->scheduleFundingRefresh();
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
        } elseif ($this->checkpoint->phase === 'streaming') {
            $this->beginReconnect('hyperliquid_public_trade_gap_unrecoverable');
        } elseif ($this->checkpoint->phase === 'reconnecting') {
            $this->scheduleReconnect();
        }

        while (!$this->stopped) {
            if ($this->checkpoint->phase === 'stopping' && $this->queue->count() === 0) {
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
                yield from $this->yieldCandidates($this->fundingEvents());

                continue;
            }

            $decoded = $this->nextDecodedFrame();
            if ($decoded === null) {
                continue;
            }
            yield from $this->processDecoded($decoded);
        }
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
        $generation = ++$this->activeGeneration;
        $this->transport->connect(
            function () use ($generation, $reconnecting): void {
                if ($generation !== $this->activeGeneration || $this->stopped) {
                    return;
                }
                if (!$reconnecting) {
                    $this->checkpoint = $this->checkpointStore->save(
                        $this->checkpoint->withPhase('subscribing'),
                    );
                }
                foreach ($this->subscriptions->subscriptions() as $subscription) {
                    $this->transport->send($subscription);
                }
                $this->loop->stop();
            },
            function (string $frame) use ($generation): void {
                if ($generation !== $this->activeGeneration || $this->stopped) {
                    return;
                }
                try {
                    $this->queue->enqueue($frame);
                } catch (\Throwable $failure) {
                    $this->transportFailure = $failure;
                    $this->transport->close();
                }
                $this->loop->stop();
            },
            function (?int $code) use ($generation): void {
                if ($generation !== $this->activeGeneration || $this->stopped) {
                    return;
                }
                if (\in_array($this->checkpoint->phase, ['streaming', 'reconnecting'], true)) {
                    $this->beginReconnect('hyperliquid_public_trade_gap_unrecoverable');
                } else {
                    $this->transportFailure = new HyperliquidPaperLiveIntegrityException(
                        'hyperliquid_paper_public_connection_closed',
                    );
                }
                $this->loop->stop();
            },
            function (\Throwable $failure) use ($generation): void {
                if ($generation !== $this->activeGeneration || $this->stopped) {
                    return;
                }
                if (\in_array($this->checkpoint->phase, ['streaming', 'reconnecting'], true)) {
                    $this->beginReconnect('hyperliquid_public_trade_gap_unrecoverable');
                } else {
                    $this->transportFailure = new HyperliquidPaperLiveIntegrityException(
                        'hyperliquid_paper_public_protocol_error',
                    );
                }
                $this->loop->stop();
            },
        );
    }

    private function awaitSubscriptions(): void
    {
        while (!$this->subscriptions->isReady()) {
            $decoded = $this->nextDecodedFrame();
            if ($decoded === null) {
                continue;
            }
            if ($decoded['kind'] !== 'subscription') {
                throw new HyperliquidPaperLiveIntegrityException(
                    'hyperliquid_paper_public_message_before_ready',
                );
            }
        }
    }

    /** @return array{kind: string, data?: mixed}|null */
    private function nextDecodedFrame(): ?array
    {
        while ($this->queue->count() === 0) {
            if ($this->transportFailure !== null) {
                throw $this->transportFailure;
            }
            $this->loop->run();
            $failure = $this->pendingTransportFailure();
            if ($failure !== null) {
                throw $failure;
            }
            if ($this->checkpoint->phase === 'stopping') {
                return null;
            }
            if ($this->fundingRefreshDue) {
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

        return $this->decoder->decode($frame);
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
    private function processDecoded(array $decoded): \Generator
    {
        if ($decoded['kind'] === 'subscription') {
            if ($this->checkpoint->phase === 'reconnecting'
                && $this->subscriptions->isReady()
            ) {
                $this->checkpoint = $this->checkpointStore->save(
                    $this->checkpoint->withPhase('streaming'),
                );
                $this->scheduleHeartbeat();
                $this->scheduleFundingRefresh();
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

            return;
        }
        if ($decoded['kind'] === 'pong') {
            $this->acceptPong();

            return;
        }
        if ($this->checkpoint->phase === 'reconnecting'
            && !$this->subscriptions->isReady()
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_message_before_ready',
            );
        }
        if ($decoded['kind'] === 'trades') {
            if (!\is_array($decoded['data'] ?? null)) {
                throw new \LogicException();
            }
            $known = [];
            foreach ($this->checkpoint->tradeIdentityHistory as $entry) {
                $known[$entry['identity_hash']] = $entry['assignment_digest'];
            }
            $candidates = [];
            foreach ($decoded['data'] as $row) {
                if (!\is_array($row)) {
                    throw new \LogicException();
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
                $candidates[] = [
                    'event' => $this->normalizer->liveTrade($row),
                    'identity_hash' => $fingerprint['identity_hash'],
                    'assignment_digest' => $fingerprint['assignment_digest'],
                ];
            }
            yield from $this->yieldTradeCandidates($candidates);

            return;
        }
        if ($decoded['kind'] === 'book') {
            if (!\is_array($decoded['data'] ?? null)) {
                throw new \LogicException();
            }
            yield from $this->yieldCandidates([
                $this->normalizer->liveTopOfBook(
                    $decoded['data'],
                    $this->checkpoint->sourceEpoch,
                ),
            ]);

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
        $currentRow = $this->checkpoint->currentCandles[$stream] ?? null;
        if ($currentRow === null) {
            $this->checkpoint = $this->checkpointStore->save(
                $this->checkpoint->withCurrentCandle($stream, $row),
            );

            return;
        }
        $current = HyperliquidCandle::fromApiRow($currentRow, $coin, $interval);
        if ($next->startTime <= $current->startTime) {
            $this->checkpoint = $this->checkpointStore->save(
                $this->checkpoint->withCurrentCandle($stream, $row),
            );

            return;
        }
        $event = $this->normalizer->closedLiveCandle($current);
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint
                ->withOrdinalState($this->ordinals->snapshot())
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
     * @param list<PaperMarketEvent> $events
     * @return \Generator<int, PaperMarketEvent>
     */
    private function yieldCandidates(array $events): \Generator
    {
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
                ->withOrdinalState($this->ordinals->snapshot())
                ->withPending($first, [
                    'remaining_events' => array_map(
                        static fn (PaperMarketEvent $event): array => $event->toArray(),
                        $events,
                    ),
                    'after_ack' => null,
                ]),
        );
        yield from $this->resumePendingEvents();
    }

    /**
     * @param list<array{
     *     event: PaperMarketEvent,
     *     identity_hash: string,
     *     assignment_digest: string
     * }> $candidates
     * @return \Generator<int, PaperMarketEvent>
     */
    private function yieldTradeCandidates(array $candidates): \Generator
    {
        if ($candidates === []) {
            return;
        }
        $first = array_shift($candidates);
        if (!\is_array($first)) {
            throw new \LogicException();
        }
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint
                ->withOrdinalState($this->ordinals->snapshot())
                ->withPending($first['event'], [
                    'remaining_events' => array_map(
                        static fn (array $candidate): array => [
                            'event' => $candidate['event']->toArray(),
                            'after_ack' => [
                                'remember_trade_identity' => [
                                    'identity_hash' => $candidate['identity_hash'],
                                    'assignment_digest' => $candidate['assignment_digest'],
                                ],
                            ],
                        ],
                        $candidates,
                    ),
                    'after_ack' => [
                        'remember_trade_identity' => [
                            'identity_hash' => $first['identity_hash'],
                            'assignment_digest' => $first['assignment_digest'],
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
        $remaining = $continuation['remaining_events'] ?? null;
        if (!\is_array($remaining) || !array_is_list($remaining)) {
            throw new \LogicException();
        }
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
                ],
            );
        }
        $this->checkpoint = $this->checkpointStore->save($next);
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        if (\in_array($this->checkpoint->phase, ['streaming', 'stopping'], true)
            && $this->checkpoint->continuity
        ) {
            $this->checkpoint = $this->checkpointStore->save(
                $this->checkpoint->loseContinuity(
                    'hyperliquid_public_trade_gap_unrecoverable',
                ),
            );
        }
        $this->stopped = true;
        ++$this->activeGeneration;
        $this->cancelTimers();
        $this->transport->close();
        $this->queue->clear();
        $this->loop->stop();
    }

    public function isComplete(): bool
    {
        return $this->checkpoint->phase === 'complete'
            && $this->checkpoint->continuity;
    }

    public function requestHealthyOperatorStop(): void
    {
        if ($this->checkpoint->phase === 'stopping') {
            return;
        }
        if ($this->checkpoint->phase !== 'streaming'
            || !$this->checkpoint->continuity
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_healthy_stop_invalid',
            );
        }
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->requestHealthyStop(),
        );
        $this->cancelTimers();
        $this->loop->stop();
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
                    || $this->checkpoint->phase !== 'streaming'
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
                    $this->transportFailure = new HyperliquidPaperLiveIntegrityException(
                        'hyperliquid_paper_public_protocol_error',
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
                    || $this->checkpoint->phase !== 'streaming'
                ) {
                    return;
                }
                $this->beginReconnect('hyperliquid_public_trade_gap_unrecoverable');
                $this->loop->stop();
            },
        );
    }

    private function scheduleFundingRefresh(): void
    {
        if (!$this->fundingClient instanceof HyperliquidPaperFundingRateClientInterface) {
            return;
        }
        if ($this->fundingTimer instanceof TimerInterface) {
            $this->loop->cancelTimer($this->fundingTimer);
        }
        $generation = $this->activeGeneration;
        $this->fundingTimer = $this->loop->addTimer(
            HyperliquidPaperLivePolicy::FUNDING_REFRESH_SECONDS,
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
        if (!$this->pongTimer instanceof TimerInterface
            || $this->checkpoint->heartbeat['pong_deadline_at'] === null
        ) {
            throw new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_message_invalid',
            );
        }
        $this->loop->cancelTimer($this->pongTimer);
        $this->pongTimer = null;
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->withHeartbeat(
                $this->timestamp($this->clock->now()),
                $this->checkpoint->heartbeat['last_ping_at'],
                null,
            ),
        );
        $this->scheduleHeartbeat();
    }

    private function beginReconnect(string $reason): void
    {
        $this->cancelTimers();
        ++$this->activeGeneration;
        $this->transport->close();
        $this->queue->clear();
        $this->transportFailure = null;
        $this->subscriptions->reset();
        $this->checkpoint = $this->checkpointStore->save(
            $this->checkpoint->beginReconnect($reason),
        );
        if ($this->checkpoint->phase === 'failed') {
            $this->transportFailure = new HyperliquidPaperLiveIntegrityException(
                'hyperliquid_paper_public_reconnect_exhausted',
            );

            return;
        }
        $this->scheduleReconnect();
    }

    private function scheduleReconnect(): void
    {
        if ($this->reconnectTimer instanceof TimerInterface) {
            return;
        }
        $attempt = $this->checkpoint->reconnectAttempt;
        $delay = HyperliquidPaperLivePolicy::RECONNECT_DELAYS_SECONDS[$attempt - 1]
            ?? throw new \LogicException();
        $generation = $this->activeGeneration;
        $this->reconnectTimer = $this->loop->addTimer(
            $delay,
            function () use ($generation): void {
                if ($generation !== $this->activeGeneration
                    || $this->stopped
                    || $this->checkpoint->phase !== 'reconnecting'
                ) {
                    return;
                }
                $this->reconnectTimer = null;
                $this->openTransport(reconnecting: true);
            },
        );
    }

    private function cancelTimers(): void
    {
        foreach (['heartbeatTimer', 'pongTimer', 'reconnectTimer', 'fundingTimer'] as $property) {
            $timer = $this->{$property};
            if ($timer instanceof TimerInterface) {
                $this->loop->cancelTimer($timer);
                $this->{$property} = null;
            }
        }
        $this->fundingRefreshDue = false;
    }

    private function shutdownAfterFailure(): void
    {
        $this->stopped = true;
        ++$this->activeGeneration;
        try {
            $this->cancelTimers();
            $this->transport->close();
            $this->queue->clear();
            $this->loop->stop();
        } catch (\Throwable) {
            // The original stable public failure remains authoritative.
        }
    }

    private function timestamp(\DateTimeInterface $timestamp): string
    {
        return \DateTimeImmutable::createFromInterface($timestamp)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
