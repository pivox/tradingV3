<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\PaperLiveMarketDataSourceInterface;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\Normalization\OkxMaterializedBookState;
use App\Trading\Paper\Okx\Normalization\OkxPaperMarketEventNormalizer;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use App\Trading\Paper\Okx\OkxPaperInstrumentMap;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use React\EventLoop\LoopInterface;
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
        foreach ($this->instruments->nativeInstrumentIds() as $instrumentId) {
            $this->books[$instrumentId] = new OkxPaperOrderBookMaterializer();
        }
        foreach ($checkpoint->streamFrontiers as $stream => $frontier) {
            $this->requiresOverlap[$stream] = $frontier instanceof OkxPaperStreamFrontier;
        }
    }

    public function venue(): PaperMarketDataVenue
    {
        return PaperMarketDataVenue::OKX;
    }

    public function events(): iterable
    {
        if (!$this->config->acquisitionEnabled) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_acquisition_disabled');
        }

        if ($this->checkpoint->pendingEvent !== null) {
            $this->continuationTransition = $this->pendingWarmupContinuation();
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

        if ($this->checkpoint->phase !== 'streaming') {
            $this->connectAndSubscribe();
        }
        $this->awaitReadiness();
        if ($this->checkpoint->phase !== 'streaming') {
            $this->checkpoint = $this->checkpointStore->saveTransition(
                $this->checkpoint,
                'streaming',
                null,
            );
        }
        while (!$this->stopped) {
            $events = $this->nextStreamingEvents();
            if ($events === []) {
                continue;
            }
            yield from $this->yieldMarketEvents(
                $events,
                $this->streamForEvent($events[0]['event']),
                [],
            );
        }
    }

    public function acknowledge(string $eventId): void
    {
        $this->checkpoint = $this->checkpointStore->acknowledge(
            $this->checkpoint,
            $eventId,
            $this->continuationTransition,
        );
        $this->continuationTransition = null;
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->publicTransport->close();
        $this->businessTransport->close();
        $this->loop->stop();
    }

    public function isComplete(): bool
    {
        return $this->checkpoint->phase === 'complete';
    }

    public function requestHealthyOperatorStop(): void
    {
        $this->stopped = true;
        $this->loop->stop();
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
            yield $boundary;
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
    ): array {
        if ($rows === []) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_response_invalid');
        }

        $frontier = $this->checkpoint->streamFrontiers[$stream] ?? null;
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
            $alreadyRepresented = isset(
                $representedInBatch[$candidateFrontier->naturalIdentity],
            );
            $requiredDurableOverlap = $frontier instanceof OkxPaperStreamFrontier
                && ($this->requiresOverlap[$stream] ?? false)
                && hash_equals(
                    $frontier->naturalIdentity,
                    $candidateFrontier->naturalIdentity,
                );
            if ($alreadyRepresented
                || ($observed instanceof OkxPaperStreamFrontier
                    && !$requiredDurableOverlap)
            ) {
                continue;
            }
            $this->observedFrontiers[$stream][$candidateFrontier->sourceIdentity] =
                $candidateFrontier;
            $representedInBatch[$candidateFrontier->naturalIdentity] = true;
            $candidates[] = [
                'row' => $row,
                'frontier' => $candidateFrontier,
                'previous' => $observed,
            ];
        }

        $start = 0;
        if ($frontier instanceof OkxPaperStreamFrontier
            && ($this->requiresOverlap[$stream] ?? false)
        ) {
            $overlap = null;
            foreach ($candidates as $index => $candidate) {
                $candidateFrontier = $candidate['frontier'];
                if (!hash_equals(
                    $frontier->naturalIdentity,
                    $candidateFrontier->naturalIdentity,
                )) {
                    continue;
                }
                if (!hash_equals(
                    $frontier->canonicalDigest,
                    $candidateFrontier->canonicalDigest,
                )) {
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
            $this->requiresOverlap[$stream] = false;
            $start = $overlap + 1;
        }

        $events = [];
        foreach ($candidates as $index => $candidate) {
            if ($index < $start) {
                continue;
            }
            $row = $candidate['row'];
            $candidateFrontier = $candidate['frontier'];
            if ($frontier instanceof OkxPaperStreamFrontier
                && $this->compareStreamSourceIdentities(
                    $stream,
                    $candidateFrontier->sourceIdentity,
                    $frontier->sourceIdentity,
                ) <= 0
            ) {
                $expected = hash_equals(
                    $frontier->naturalIdentity,
                    $candidateFrontier->naturalIdentity,
                ) ? $frontier : $candidate['previous'];
                if (!$expected instanceof OkxPaperStreamFrontier) {
                    throw new OkxPaperLiveIntegrityException('market_data_gap_unresolved');
                }
                if (!hash_equals($expected->canonicalDigest, $candidateFrontier->canonicalDigest)) {
                    throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
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

    private function compareStreamSourceIdentities(string $stream, string $left, string $right): int
    {
        if (str_contains($stream, '/candle_')) {
            [, $leftTimestamp] = explode('|', $left, 2);
            [, $rightTimestamp] = explode('|', $right, 2);

            return self::compareUnsigned($leftTimestamp, $rightTimestamp);
        }

        return self::compareUnsigned($left, $right);
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

            return [];
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
            yield $event;
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
        $transport->connect(
            $uri,
            function () use (&$opened): void {
                $opened = true;
                $this->loop->stop();
            },
            function (string $frame) use ($queue): void {
                $queue->enqueue($frame);
                $this->loop->stop();
            },
            function () use (&$terminal): void {
                $terminal = new OkxPaperLiveIntegrityException(
                    'okx_paper_public_reconnect_exhausted',
                );
                $this->loop->stop();
            },
            function (\Throwable $error) use (&$terminal): void {
                $terminal = $error;
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
            if ($queue->count() === 0) {
                $this->loop->run();
            }
            $framesToInspect = $queue->count();
            if ($framesToInspect === 0) {
                throw new OkxPaperLiveIntegrityException('okx_paper_public_reconnect_exhausted');
            }
            $acknowledged = false;
            for ($index = 0; $index < $framesToInspect; ++$index) {
                $frame = $queue->dequeue();
                if ($frame === null) {
                    break;
                }
                $message = $business
                    ? $this->decoder->decodeBusiness($frame)
                    : $this->decoder->decodePublic($frame);
                if (!isset($message['event'])) {
                    $queue->enqueue($frame);
                    continue;
                }
                if ($message['event'] === 'pong') {
                    continue;
                }
                $acknowledged = true;
                if ($this->acknowledgeReadinessMessage($message, $business)) {
                    break;
                }
            }
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

    /** @return array<string, mixed> */
    private function nextDecoded(OkxPaperPublicFrameQueue $queue, bool $business): array
    {
        $frame = $queue->dequeue();
        if ($frame === null) {
            $this->loop->run();
            $frame = $queue->dequeue();
        }
        if ($frame === null) {
            throw new OkxPaperLiveIntegrityException('okx_paper_public_reconnect_exhausted');
        }

        return $business
            ? $this->decoder->decodeBusiness($frame)
            : $this->decoder->decodePublic($frame);
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
            return $this->eventsFromMessage($this->nextDecoded($this->publicQueue, false), false);
        }
        if ($this->businessQueue->count() > 0) {
            return $this->eventsFromMessage($this->nextDecoded($this->businessQueue, true), true);
        }
        $this->loop->run();

        return [];
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
