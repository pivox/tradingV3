<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
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
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Symfony\Component\Clock\ClockInterface;

final class OkxPaperPublicLiveSource implements PaperLiveMarketDataSourceInterface
{
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

    /** @var array{public: int, business: int} */
    private array $pongGenerations = ['public' => 0, 'business' => 0];

    /** @var array{public: bool, business: bool} */
    private array $socketOpen = ['public' => false, 'business' => false];

    private bool $resumedHealthyStop;
    private bool $resumedBookRecovery;
    private bool $resumedStreaming;

    private ?string $nextEventStream = null;

    /** @var array<string, mixed> */
    private array $nextEventTransition = [];

    private ?string $activeQueuedSocket = null;
    private int $activeQueuedEventsRemaining = 0;

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
    ) {
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
        $this->resumedHealthyStop = $checkpoint->phase === 'stopping';
        $this->resumedBookRecovery = $checkpoint->phase === 'resyncing';
        $this->resumedStreaming = $checkpoint->phase === 'streaming';
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
            if (!\in_array($this->checkpoint->phase, ['failed', 'complete'], true)) {
                $reason = $this->terminalPublicFailureReason($exception);
                if ($reason !== null) {
                    $this->failTerminal($reason);
                }
            }

            throw $exception;
        }
    }

    /** @return iterable<PaperMarketEvent> */
    private function eventFlow(): iterable
    {
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
        if ($this->checkpoint->phase === 'warming') {
            yield from $this->warmup();
        }
        if ($this->checkpoint->phase === 'stopping') {
            yield from $this->healthyStopFlow();

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
            }
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
        if ($this->checkpoint->phase === 'streaming') {
            $this->startHeartbeatTimers();
        }
        while (!$this->stopped) {
            if ($this->checkpoint->phase === 'stopping') {
                yield from $this->healthyStopFlow();

                return;
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
            $this->checkpoint = $this->checkpointStore->acknowledgeOpaqueUnsequenced(
                $this->checkpoint,
                $eventId,
                $this->continuationTransition,
            );
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
                $this->completeActiveQueuedFrame();
            }
        }
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
        if (!$this->healthyStopPreconditionsHold()) {
            $this->failTerminal('okx_paper_public_healthy_stop_invalid');
        }
        $state = $this->checkpoint->toArray();
        $state['remaining_symbols'] = ['BTCUSDT', 'ETHUSDT'];
        $state['healthy_stop'] = [
            'requested' => true,
            'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
        ];
        $candidate = OkxPaperLiveCheckpoint::fromArray($state);
        $this->checkpoint = $this->checkpointStore->saveTransition(
            $candidate,
            'stopping',
            $this->stoppedEventTransition('BTCUSDT'),
        );
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
                $rows = $this->restClient->currentCandles(
                    $instrumentId,
                    $bar,
                    null,
                    null,
                    300,
                );
                $this->sortCandleRows($rows);
                $events = $this->acceptedEvents(
                    $stream,
                    $rows,
                    fn (array $row, OkxPaperMarketEventNormalizer $normalizer): ?PaperMarketEvent => $normalizer
                        ->warmupCandle($instrumentId, $bar, $row),
                    fn (array $row): ?OkxPaperStreamFrontier => $this->candleFrontier(
                        $instrumentId,
                        $bar,
                        $row,
                    ),
                );
                if ($events === []
                    && ($this->checkpoint->streamFrontiers[$stream] ?? null) === null
                ) {
                    throw new OkxPaperLiveIntegrityException(
                        'okx_paper_public_response_invalid',
                    );
                }
                yield from $this->yieldMarketEvents($events, $stream, $transition);
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
                $events = $this->acceptedEvents(
                    $tradeStream,
                    $rows,
                    static fn (
                        array $row,
                        OkxPaperMarketEventNormalizer $normalizer,
                    ): PaperMarketEvent => $normalizer->recoveryTrade($row),
                    fn (array $row): OkxPaperStreamFrontier => $this->tradeFrontier($row),
                );
                yield from $this->yieldMarketEvents($events, $tradeStream, $tradeTransition);
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
        foreach ($pagination['retained_rows'] as $row) {
            if (!\is_array($row)) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            }
            $candidate = str_ends_with($stream, '/public_trade')
                ? $this->tradeFrontier($row)
                : $this->candleFrontier(
                    $this->instruments->nativeInstrumentId($this->checkpoint->pendingEvent->symbol),
                    substr($stream, strrpos($stream, '_') + 1),
                    $row,
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
    ): array {
        if ($rows === []) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        $frontier = $this->checkpoint->streamFrontiers[$stream] ?? null;
        /** @var array<string, string> $requiredOverlapDigests */
        $requiredOverlapDigests = [];
        if ($frontier instanceof OkxPaperStreamFrontier
            && ($this->requiresOverlap[$stream] ?? false)
        ) {
            $requiredFrontiers = [$frontier];
            if ($requireSiblingOverlap) {
                $requiredFrontiers[] = $this->siblingFrontier($stream);
            }
            foreach ($requiredFrontiers as $requiredFrontier) {
                if (!$requiredFrontier instanceof OkxPaperStreamFrontier) {
                    continue;
                }
                $existingDigest = $requiredOverlapDigests[
                    $requiredFrontier->naturalIdentity
                ] ?? null;
                if (\is_string($existingDigest)
                    && !hash_equals($existingDigest, $requiredFrontier->canonicalDigest)
                ) {
                    throw new OkxPaperLiveIntegrityException(
                        'market_event_identity_conflict',
                    );
                }
                $requiredOverlapDigests[$requiredFrontier->naturalIdentity] =
                    $requiredFrontier->canonicalDigest;
            }
        }
        $candidates = [];
        /** @var array<string, true> $representedInBatch */
        $representedInBatch = [];
        foreach ($rows as $row) {
            $candidateFrontier = $frontierForRow($row);
            if ($candidateFrontier === null) {
                continue;
            }
            $observed = $this->observedFrontiers[$stream][$candidateFrontier->sourceIdentity]
                ?? null;
            if ($observed instanceof OkxPaperStreamFrontier
                && !hash_equals($observed->canonicalDigest, $candidateFrontier->canonicalDigest)
            ) {
                throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
            }
            $acknowledgedDigest = $this->acknowledgedIdentityDigest(
                $stream,
                $candidateFrontier,
            );
            $requiredDurableOverlap = array_key_exists(
                $candidateFrontier->naturalIdentity,
                $requiredOverlapDigests,
            );
            if ($acknowledgedDigest !== null && !$requiredDurableOverlap) {
                if (!hash_equals($acknowledgedDigest, $candidateFrontier->canonicalDigest)) {
                    throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
                }

                continue;
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
            $this->rememberObservedFrontier($stream, $candidateFrontier);
            $representedInBatch[$candidateFrontier->naturalIdentity] = true;
            $candidates[] = [
                'row' => $row,
                'frontier' => $candidateFrontier,
                'previous' => $observed,
            ];
        }

        $start = 0;
        if ($requiredOverlapDigests !== []) {
            $overlaps = [];
            foreach ($requiredOverlapDigests as $identity => $digest) {
                $overlap = null;
                foreach ($candidates as $index => $candidate) {
                    $candidateFrontier = $candidate['frontier'];
                    if (!hash_equals($identity, $candidateFrontier->naturalIdentity)) {
                        continue;
                    }
                    if (!hash_equals($digest, $candidateFrontier->canonicalDigest)) {
                        throw new OkxPaperLiveIntegrityException(
                            'market_event_identity_conflict',
                        );
                    }
                    $overlap = $index;
                    break;
                }
                if (!\is_int($overlap)) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                $overlaps[] = $overlap;
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

    private function rememberObservedFrontier(
        string $stream,
        OkxPaperStreamFrontier $frontier,
    ): void {
        unset($this->observedFrontiers[$stream][$frontier->sourceIdentity]);
        $this->observedFrontiers[$stream][$frontier->sourceIdentity] = $frontier;
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

    private function acknowledgedIdentityDigest(
        string $stream,
        OkxPaperStreamFrontier $candidate,
    ): ?string {
        if ((!str_contains($stream, '/candle_') && !str_ends_with($stream, '/public_trade'))
            || (!str_contains($stream, '/rest/') && !str_contains($stream, '/ws/'))
        ) {
            return null;
        }
        $logicalStream = str_replace(['/rest/', '/ws/'], '/', $stream);
        $identityHash = hash('sha256', $candidate->naturalIdentity);
        foreach ($this->checkpoint->acknowledgedIdentityHistory[$logicalStream] ?? [] as $entry) {
            if (hash_equals($entry['natural_identity_sha256'], $identityHash)) {
                return $entry['canonical_digest'];
            }
        }

        return null;
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
        $frontier = $this->checkpoint->streamFrontiers[$stream] ?? null;
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
    private function yieldMarketEvents(array $events, string $stream, array $transition): \Generator
    {
        if ($events === []) {
            return;
        }
        $last = \count($events) - 1;
        foreach ($events as $index => $accepted) {
            $event = $accepted['event'];
            $frontier = $accepted['frontier'];
            $this->checkpoint = $this->checkpointStore->savePending(
                $this->checkpoint,
                $event,
                $accepted['ordinal_state'],
                ['stream' => $stream, 'frontier' => $frontier->toArray()],
            );
            $this->continuationTransition = $index < $last && $transition !== []
                ? $transition
                : null;
            yield $this->checkpoint->pendingEvent
                ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid');
            $this->assertPendingWasAcknowledged();
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

    /** @param list<array<array-key, mixed>> $rows */
    private function sortTradeRows(array &$rows): void
    {
        usort($rows, static function (array $left, array $right): int {
            $timestamp = self::compareUnsigned($left['ts'] ?? null, $right['ts'] ?? null);

            return $timestamp !== 0
                ? $timestamp
                : self::compareUnsigned($left['tradeId'] ?? null, $right['tradeId'] ?? null);
        });
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
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
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
                $this->connectSocket(
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
                );

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
    ): void {
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
            function () use (&$opened, $generation, $socket): void {
                if ($generation !== $this->connectionGeneration) {
                    return;
                }
                $opened = true;
                $this->socketOpen[$socket] = true;
                $this->loop->stop();
            },
            function (string $frame) use ($queue, $generation, $socket): void {
                $this->admitSocketFrame($frame, $queue, $generation, $socket);
            },
            function () use (&$terminal, &$opened, $generation, $socket): void {
                if ($generation !== $this->connectionGeneration) {
                    return;
                }
                $this->socketOpen[$socket] = false;
                if (!$opened) {
                    $terminal = new OkxPaperLiveIntegrityException(
                        'okx_paper_public_reconnect_exhausted',
                    );
                } else {
                    $this->beginPairedReconnect();
                }
                $this->loop->stop();
            },
            function (\Throwable $error) use (&$terminal, &$opened, $generation, $socket): void {
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
        if (!$opened && $terminal === null) {
            $this->loop->run();
        }
        if ($terminal instanceof \Throwable) {
            throw $terminal;
        }
        if (!$opened) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_reconnect_exhausted');
        }
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
            $socket === 'public'
                ? $this->decoder->decodePublic($frame)
                : $this->decoder->decodeBusiness($frame);
            $this->refreshInboundFreshness($socket, $frame);
        } catch (OkxPaperLiveIntegrityException $exception) {
            $reason = $this->terminalPublicFailureReason($exception);
            if ($reason !== null) {
                $this->failTerminal($reason);
            }

            throw $exception;
        }
        try {
            $queue->enqueue($frame);
        } catch (OkxPaperLiveIntegrityException $exception) {
            if ($exception->getMessage() === 'market_data_backpressure_exhausted') {
                $this->failTerminal('market_data_backpressure_exhausted');
            }

            throw $exception;
        }
        try {
            $this->persistStreamingQueues();
        } catch (OkxPaperLiveIntegrityException $exception) {
            if ($exception->getMessage() === 'market_data_backpressure_exhausted') {
                $this->failTerminal('market_data_backpressure_exhausted');
            }

            throw $exception;
        }
        $this->loop->stop();
    }

    private function healthyStopPreconditionsHold(): bool
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
            || $this->publicQueue->count() !== 0
            || $this->businessQueue->count() !== 0
        ) {
            return false;
        }
        return $this->socketFreshnessWithinPolicy();
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

    private function beginPairedReconnect(): void
    {
        if ($this->checkpoint->phase === 'stopping') {
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
            function () use (&$opened, $generation, $socket, $afterOpen): void {
                if ($generation !== $this->connectionGeneration || $this->stopped) {
                    return;
                }
                $opened = true;
                $this->socketOpen[$socket] = true;
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
            function () use (&$opened, $generation, $socket): void {
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
                'kind' => 'subscription_send',
                'symbol' => null,
                'stream' => 'public',
                'stage' => 'subscribe',
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
                try {
                    $events = $this->reconnectBookEvents($symbol, $transition);
                } catch (\Throwable $exception) {
                    if ($this->isIdentityConflict($exception)) {
                        $this->failTerminal('market_event_identity_conflict');
                    }
                    $this->failTerminal('market_data_gap_unresolved');
                }
                yield from $this->yieldMarketEvents($events, $stream, $transition);

                continue;
            }

            try {
                $events = $this->reconnectFrontierEvents($symbol, $stream, $transition);
            } catch (\Throwable $exception) {
                if ($this->isIdentityConflict($exception)) {
                    $this->failTerminal('market_event_identity_conflict');
                }
                if ($exception->getMessage() !== 'market_data_gap_unresolved') {
                    throw $exception;
                }
                $this->failTerminal('market_data_gap_unresolved');
            }
            if ($events === []) {
                $this->completeReconnectFrontierOverlap($symbol, $stream);

                continue;
            }
            $transition = $this->checkpoint->pendingTransition ?? $transition;
            yield from $this->yieldMarketEvents($events, $stream, $transition);
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
            $rows = $pagination['retained_rows'];
            unset($this->observedFrontiers[$stream]);
            try {
                return $this->acceptedRecoveryCandleEvents(
                    $stream,
                    $instrumentId,
                    $matches[1],
                    $rows,
                );
            } catch (OkxPaperLiveIntegrityException $exception) {
                if ($exception->getMessage() !== 'market_data_gap_unresolved') {
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
            $rows = $pagination['retained_rows'];
            unset($this->observedFrontiers[$stream]);
            try {
                return $this->acceptedRecoveryTradeEvents($stream, $rows);
            } catch (OkxPaperLiveIntegrityException $exception) {
                if ($exception->getMessage() !== 'market_data_gap_unresolved') {
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
                if ($exception->getMessage() !== 'market_data_gap_unresolved') {
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
        $rows = $this->restClient->recentTrades($instrumentId, 500);
        if ($this->reconnectRecoveryDeadlineExpired($symbol)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
        $this->sortTradeRows($rows);

        try {
            $events = $this->acceptedRecoveryTradeEvents($stream, $rows);
            $this->persistRecentTradeSuffix($symbol, $stream, $rows, $events);

            return $events;
        } catch (OkxPaperLiveIntegrityException $exception) {
            if ($exception->getMessage() !== 'market_data_gap_unresolved') {
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
            'target_frontier' => $resync['frontier']->toArray(),
            'deadline_at' => $resync['deadline_at'],
            'retained_rows' => $rows,
        ];
        $state['pending_transition'] = $historyTransition;
        $this->checkpoint = $this->checkpointStore->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($state),
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
        foreach ($rows as $row) {
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
        $historyTransition = $this->restTransition(
            $symbol,
            $stream,
            'history_trades',
        );
        $state = $this->checkpoint->toArray();
        $state['overlap_pagination_by_stream'][$stream] = [
            'endpoint' => 'history_trades',
            'pagination_type' => 2,
            'next_cursor' => $oldestTimestamp,
            'pages_consumed' => 0,
            'pages_remaining' => OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
            'target_frontier' => $resync['frontier']->toArray(),
            'deadline_at' => $resync['deadline_at'],
            'retained_rows' => $rows,
        ];
        $state['pending_transition'] = $historyTransition;
        $this->checkpoint = $this->checkpointStore->saveTransition(
            OkxPaperLiveCheckpoint::fromArray($state),
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
                'target_frontier' => $resync['frontier']->toArray(),
                'deadline_at' => $resync['deadline_at'],
                'retained_rows' => $newerRows,
            ];
            $state['pending_transition'] = $historyTransition;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($state),
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
            $newerRows = [...$rows, ...$pagination['retained_rows']];
            $this->sortCandleRows($newerRows);
            $state = $this->checkpoint->toArray();
            $next = $state['overlap_pagination_by_stream'][$stream];
            ++$next['pages_consumed'];
            --$next['pages_remaining'];
            $next['next_cursor'] = $oldestTimestamp;
            $next['retained_rows'] = $newerRows;
            $state['overlap_pagination_by_stream'][$stream] = $next;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($state),
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
                if ($exception->getMessage() !== 'market_data_gap_unresolved') {
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
            $state['overlap_pagination_by_stream'][$stream] = [
                'endpoint' => 'history_trades',
                'pagination_type' => 2,
                'next_cursor' => $oldestTimestamp,
                'pages_consumed' => 0,
                'pages_remaining' => OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
                'target_frontier' => $resync['frontier']->toArray(),
                'deadline_at' => $resync['deadline_at'],
                'retained_rows' => $newerRows,
            ];
            $state['pending_transition'] = $historyTransition;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($state),
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
            $newerRows = [...$rows, ...$pagination['retained_rows']];
            $this->sortTradeRows($newerRows);
            $state = $this->checkpoint->toArray();
            $next = $state['overlap_pagination_by_stream'][$stream];
            ++$next['pages_consumed'];
            --$next['pages_remaining'];
            $next['pagination_type'] = 1;
            $next['next_cursor'] = $oldestTradeId;
            $next['retained_rows'] = $newerRows;
            $state['overlap_pagination_by_stream'][$stream] = $next;
            $this->checkpoint = $this->checkpointStore->saveTransition(
                OkxPaperLiveCheckpoint::fromArray($state),
                'reconnecting',
                $historyTransition,
            );
            unset($this->observedFrontiers[$stream]);
            try {
                return $this->acceptedRecoveryTradeEvents($stream, $newerRows);
            } catch (OkxPaperLiveIntegrityException $exception) {
                if ($exception->getMessage() !== 'market_data_gap_unresolved') {
                    throw $exception;
                }
            }
        }
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
        $state['resync_by_symbol'][$symbol] = null;
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
            $this->requireQueuedReconnectBookOverlap(
                $instrumentId,
                $replacementState->sourceSequence,
            );
        }
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

    private function requireQueuedReconnectBookOverlap(
        string $instrumentId,
        string $snapshotSequence,
    ): void {
        if (!$this->filterQueuedBookOverlap($instrumentId, $snapshotSequence)) {
            $this->failTerminal('market_data_gap_unresolved');
        }
    }

    private function scheduleNextReconnectAttempt(): void
    {
        $attempt = $this->checkpoint->reconnect['attempt'];
        if ($attempt >= \count(OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS)) {
            $this->failTerminal('okx_paper_public_reconnect_exhausted');
        }
        ++$this->connectionGeneration;
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
        $this->heartbeatTimers[$socket] = $this->loop->addTimer(
            $delay,
            function () use ($socket, $generation): void {
                if ($generation !== $this->heartbeatGenerations[$socket]
                    || $this->checkpoint->phase !== 'streaming'
                ) {
                    return;
                }
                unset($this->heartbeatTimers[$socket]);
                $lastInbound = $this->lastInboundAt[$socket];
                if (!$lastInbound instanceof \DateTimeImmutable) {
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
                $pongGeneration = ++$this->pongGenerations[$socket];
                $this->pongTimers[$socket] = $this->loop->addTimer(
                    OkxPaperLivePolicy::PONG_TIMEOUT_SECONDS,
                    function () use ($socket, $pongGeneration): void {
                        if ($pongGeneration !== $this->pongGenerations[$socket]
                            || $this->checkpoint->phase !== 'streaming'
                        ) {
                            return;
                        }
                        unset($this->pongTimers[$socket]);
                        $this->beginPairedReconnect();
                    },
                );
            },
        );
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
        ++$this->pongGenerations['public'];
        ++$this->pongGenerations['business'];
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
        while (!$this->socketReady($business)) {
            $beforeAsyncProgress = [
                $this->checkpoint->pendingTransition,
                $this->socketOpen,
            ];
            if ($queue->count() === 0) {
                $this->loop->run();
            }
            $framesToInspect = $queue->count();
            if ($framesToInspect === 0) {
                if ($this->checkpoint->phase === 'reconnecting'
                    && $beforeAsyncProgress !== [
                        $this->checkpoint->pendingTransition,
                        $this->socketOpen,
                    ]
                ) {
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
            if (!$acknowledged) {
                $this->loop->run();
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
        if ($this->publicQueue->count() > 0) {
            return $this->eventsFromQueuedFrame($this->publicQueue, false);
        }
        if ($this->businessQueue->count() > 0) {
            return $this->eventsFromQueuedFrame($this->businessQueue, true);
        }
        $this->loop->run();

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
        $frame = $queue->peek();
        if ($frame === null) {
            return [];
        }
        $message = $business
            ? $this->decoder->decodeBusiness($frame)
            : $this->decoder->decodePublic($frame);
        try {
            $events = $this->eventsFromMessage($message, $business);
        } catch (\RuntimeException $exception) {
            if ($business
                || $exception->getMessage() !== 'okx_paper_book_sequence_gap'
                || !$this->isBookUpdate($message)
            ) {
                if (\in_array($exception->getMessage(), [
                    'market_event_identity_conflict',
                    'market_data_gap_unresolved',
                ], true)) {
                    $this->failTerminal($exception->getMessage());
                }

                throw $exception;
            }

            return $this->startBookResync($message);
        }
        if ($events === []) {
            $queue->dequeue();
            $this->persistStreamingQueues();
        } else {
            $this->activeQueuedSocket = $business ? 'business' : 'public';
            $this->activeQueuedEventsRemaining = \count($events);
        }

        return $events;
    }

    private function completeActiveQueuedFrame(): void
    {
        if ($this->activeQueuedSocket === null) {
            return;
        }
        $queue = $this->activeQueuedSocket === 'public'
            ? $this->publicQueue
            : $this->businessQueue;
        $queue->dequeue();
        $this->activeQueuedSocket = null;
        $this->activeQueuedEventsRemaining = 0;
        $this->persistStreamingQueues();
    }

    private function persistStreamingQueues(): void
    {
        if (\in_array($this->checkpoint->phase, ['complete', 'failed', 'stopping'], true)) {
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
        /** @phpstan-ignore-next-line REST test doubles may run timeout callbacks synchronously. */
        if ($this->resyncAttemptExpired($symbol, $generation, $deadline)
            || \count($rows) !== 1
            || !\is_array($rows[0])
        ) {
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

    private function failTerminal(string $reason): never
    {
        ++$this->connectionGeneration;
        $this->stopped = true;
        $this->checkpoint = $this->checkpointStore->fail($this->checkpoint, $reason);
        $this->publicQueue->clear();
        $this->businessQueue->clear();
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
        throw new OkxPaperLiveIntegrityException($reason);
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
            ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
            ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
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
                $this->connectSocket(
                    $action['stream'],
                    $public
                        ? $this->config->webSocketUri
                        : $this->config->businessWebSocketUri,
                    $public ? $this->publicTransport : $this->businessTransport,
                    $public ? $this->publicQueue : $this->businessQueue,
                    false,
                );

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
                if (!\is_array($row)
                    || !\is_string($row['seqId'] ?? null)
                    || !\is_string($row['prevSeqId'] ?? null)
                ) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                if (self::compareUnsigned($row['seqId'], $snapshotSequence) <= 0) {
                    continue;
                }
                if (!hash_equals($expectedPreviousSequence, $row['prevSeqId'])) {
                    return false;
                }
                $found = true;
                $expectedPreviousSequence = $row['seqId'];
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

        return $this->frontierFromCanonical(
            $instrumentId,
            $channel,
            $sourceIdentity,
            [
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
            ],
            $timestamp,
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
     * @param array<string, mixed> $sourceFields
     */
    private function frontierFromCanonical(
        string $instrumentId,
        string $channel,
        string $sourceIdentity,
        array $sourceFields,
        string $exchangeTimestamp,
    ): OkxPaperStreamFrontier {
        $canonical = [
            'channel' => $channel,
            'native_symbol' => $instrumentId,
            'source_fields' => $sourceFields,
            'venue' => PaperMarketDataVenue::OKX->value,
            'exchange_timestamp' => $this->formattedTimestamp($exchangeTimestamp),
        ];

        return OkxPaperStreamFrontier::fromArray([
            'source_identity' => $sourceIdentity,
            'natural_identity' => implode('|', [
                PaperMarketDataVenue::OKX->value,
                $instrumentId,
                $channel,
                $sourceIdentity,
            ]),
            'canonical_digest' => hash('sha256', CanonicalJson::encode($canonical)),
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
