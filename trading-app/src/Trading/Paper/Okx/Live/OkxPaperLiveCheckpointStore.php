<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Dataset\PaperDatasetRecorderFilesystem;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;
use Brick\Math\BigInteger;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

final class OkxPaperLiveCheckpointStore
{
    private const REGULAR_FILE_TYPE = 0100000;
    private const DIRECTORY_FILE_TYPE = 0040000;
    private const SYMLINK_FILE_TYPE = 0120000;
    private const FILE_TYPE_MASK = 0170000;
    private const WRITER_LOCK_FILENAME = '.writer.lock';
    private const CHECKPOINT_FILENAME = 'checkpoint.json';
    private const MAX_CHECKPOINT_BYTES = 1_048_576;
    private const SHA256_PATTERN = '/\A[a-f0-9]{64}\z/D';

    /** @var list<array{suffix: string, stages: list<string>}> */
    private const WARMING_REST_WORK = [
        ['suffix' => 'rest/candle_1m', 'stages' => ['current_candles', 'history_candles']],
        ['suffix' => 'rest/candle_5m', 'stages' => ['current_candles', 'history_candles']],
        ['suffix' => 'rest/candle_15m', 'stages' => ['current_candles', 'history_candles']],
        ['suffix' => 'rest/candle_1H', 'stages' => ['current_candles', 'history_candles']],
        ['suffix' => 'rest/public_trade', 'stages' => ['recent_trades', 'history_trades']],
        ['suffix' => 'rest/top_of_book', 'stages' => ['order_book']],
    ];

    /** @var list<array{kind: string, symbol: string|null, stream: string|null, stage: string}> */
    private const CLEANUP_ACTIONS = [
        ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'public', 'stage' => 'close'],
        ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'business', 'stage' => 'close'],
        ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'cancel_reconnect_timer'],
        ['kind' => 'timer_cancel', 'symbol' => 'BTCUSDT', 'stream' => 'BTCUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
        ['kind' => 'timer_cancel', 'symbol' => 'ETHUSDT', 'stream' => 'ETHUSDT/ws/top_of_book', 'stage' => 'cancel_resync_timer'],
        ['kind' => 'loop_stop', 'symbol' => null, 'stream' => null, 'stage' => 'stop_loop'],
    ];

    private readonly PaperDatasetRecorderFilesystem $filesystem;
    private readonly ClockInterface $clock;
    private readonly string $checkpointPath;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $datasetPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $checkpointsPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} */
    private array $directoryPin;

    /** @var array{handle: resource, identity: array{dev: int, ino: int}, path: string} */
    private array $writerLock;

    private ?string $datasetId = null;
    private ?string $configurationSha256 = null;
    private ?string $currentStateHash = null;
    private ?OkxPaperLiveCheckpoint $currentCheckpoint = null;

    /** @var array{dev: int, ino: int}|null */
    private ?array $currentFileIdentity = null;

    public function __construct(
        #[\SensitiveParameter] string $datasetDirectory,
        ?PaperDatasetRecorderFilesystem $filesystem = null,
        ?ClockInterface $clock = null,
    ) {
        $this->filesystem = $filesystem ?? new PaperDatasetRecorderFilesystem();
        $this->clock = $clock ?? new NativeClock(new \DateTimeZone('UTC'));
        $this->assertNoSymlinkComponents($datasetDirectory);
        $resolved = realpath($datasetDirectory);
        if ($resolved === false) {
            throw self::invalidCheckpoint();
        }

        $this->datasetPin = $this->openPinnedDirectory($resolved, requirePrivate: true);
        try {
            $this->checkpointsPin = $this->ensureManagedDirectory($this->datasetPin, 'checkpoints');
            $this->directoryPin = $this->ensureManagedDirectory($this->checkpointsPin, 'okx-live');
            $this->checkpointPath = $this->directoryPin['path'] . '/' . self::CHECKPOINT_FILENAME;
            $this->writerLock = $this->acquireWriterLock();
        } catch (\Throwable $failure) {
            $this->closeInitializedResources();

            throw $failure;
        }
    }

    public function __destruct()
    {
        $this->closeInitializedResources();
    }

    public function loadOrCreate(string $datasetId, string $configurationSha256): OkxPaperLiveCheckpoint
    {
        try {
            PaperDatasetManifest::assertDatasetId($datasetId);
            if (preg_match(self::SHA256_PATTERN, $configurationSha256) !== 1) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }

        if (($this->datasetId !== null && !hash_equals($this->datasetId, $datasetId))
            || ($this->configurationSha256 !== null
                && !hash_equals($this->configurationSha256, $configurationSha256))
        ) {
            throw self::invalidCheckpoint();
        }
        $this->datasetId = $datasetId;
        $this->configurationSha256 = $configurationSha256;

        $this->assertManagedDirectories();
        $statistics = $this->pathStatistics($this->checkpointPath);
        if ($statistics === false) {
            $checkpoint = OkxPaperLiveCheckpoint::fresh($datasetId, $configurationSha256);
            $this->persist($checkpoint);

            return $checkpoint;
        }

        $contents = $this->readCheckpoint();
        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded) || array_is_list($decoded)) {
                throw new \InvalidArgumentException();
            }
            $checkpoint = OkxPaperLiveCheckpoint::fromArray($decoded);
            if (!hash_equals($datasetId, $checkpoint->datasetId)
                || !hash_equals($configurationSha256, $checkpoint->configurationSha256)
                || CanonicalJson::encode($checkpoint->toArray()) . "\n" !== $contents
            ) {
                throw new \InvalidArgumentException();
            }
            $this->assertSemanticallyResumable($checkpoint);
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }
        $this->currentStateHash = $this->stateHash($checkpoint);
        $this->currentFileIdentity = $this->checkpointIdentity();
        $this->currentCheckpoint = $checkpoint;
        $this->assertManagedDirectories();

        return $checkpoint;
    }

    public function save(#[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint): void
    {
        $this->assertCurrent($checkpoint);
        $this->assertCompleteIsTerminal($checkpoint);
        $this->persist($checkpoint);
    }

    public function fail(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $failureReason,
    ): OkxPaperLiveCheckpoint {
        $this->assertCurrent($checkpoint);
        if ($checkpoint->phase === 'complete') {
            throw self::invalidCheckpoint();
        }
        if ($checkpoint->phase === 'failed') {
            if ($checkpoint->failureReason === $failureReason) {
                return $checkpoint;
            }

            throw self::invalidCheckpoint();
        }

        $state = $checkpoint->toArray();
        $state['phase'] = 'failed';
        $state['failure_reason'] = $failureReason;
        $state['pending_transition'] = self::CLEANUP_ACTIONS[0];
        $state['pending_event'] = null;
        $state['pending_frontier'] = null;
        $failed = $this->validatedCheckpoint($state);
        $this->persist($failed);

        return $failed;
    }

    /** @param array<string, mixed>|null $pendingTransition */
    public function saveTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $phase,
        #[\SensitiveParameter] ?array $pendingTransition,
    ): OkxPaperLiveCheckpoint {
        $stabilityAction = $this->reconnectStabilityTransitionAction(
            $checkpoint,
            $phase,
            $pendingTransition,
        );
        $this->assertTransitionMutationsMatchAction($checkpoint, $phase, $pendingTransition);
        $state = $checkpoint->toArray();
        $state['phase'] = $phase;
        $state['pending_transition'] = $pendingTransition;
        if ($stabilityAction === 'start') {
            $state['reconnect'] = [
                'attempt' => $checkpoint->reconnect['attempt'],
                'deadline_at' => null,
                'stable_since' => $this->nowUtc(),
                'accepted_events' => 0,
            ];
        } elseif ($stabilityAction === 'reset') {
            $state['reconnect'] = [
                'attempt' => 0,
                'deadline_at' => null,
                'stable_since' => null,
                'accepted_events' => 0,
            ];
        }
        $next = $this->validatedCheckpoint($state);
        $this->assertReconnectProgresses($next, allowCompletion: $stabilityAction !== null);
        $this->assertRecoveryStateProgresses(
            $next,
            allowCompletion: $this->isExactOverlapCompletionTransition(
                $checkpoint,
                $phase,
                $pendingTransition,
            ),
        );
        $this->assertTransitionTargetsWorkHead($next);
        $this->assertDurableHealthyStopCleanupOrder($next);
        $this->assertCompleteIsTerminal($next, allowFinalizedTransition: true);
        $this->persist($next);

        return $next;
    }

    /**
     * @param array<string, mixed>      $ordinalState
     * @param array<string, mixed>|null $pendingFrontier
     */
    public function savePending(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] PaperMarketEvent $event,
        #[\SensitiveParameter] array $ordinalState,
        #[\SensitiveParameter] ?array $pendingFrontier,
    ): OkxPaperLiveCheckpoint {
        $this->assertCurrent($checkpoint);
        if ($checkpoint->pendingEvent !== null) {
            throw self::invalidCheckpoint();
        }
        $stopped = $event->channel->value === 'connection_state'
            && ($event->payload['state'] ?? null) === 'stopped';
        if ($checkpoint->pendingTransition === null
            && ($event->channel->value === 'snapshot_boundary' || $stopped)
        ) {
            throw self::invalidCheckpoint();
        }
        $this->assertTransitionTargetsWorkHead($checkpoint);
        $this->assertTransitionProducesEvent(
            $checkpoint,
            $checkpoint->pendingTransition,
            $event,
            $pendingFrontier,
        );
        $this->assertOrdinalAdvancesExactly($checkpoint, $event, $ordinalState);
        $state = $checkpoint->toArray();
        $state['ordinal_state'] = $ordinalState;
        $state['pending_event'] = $event->toArray();
        $state['pending_frontier'] = $pendingFrontier;
        $state['pending_transition'] = null;
        $next = $this->validatedCheckpoint($state);
        $this->persist($next);

        return $next;
    }

    /** @param array<string, mixed> $ordinalState */
    private function assertOrdinalAdvancesExactly(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] PaperMarketEvent $event,
        #[\SensitiveParameter] array $ordinalState,
    ): void {
        try {
            $scope = implode('/', [
                $event->sourceVenue->value,
                $event->symbol,
                $event->channel->value,
            ]);
            $latest = $ordinalState['scopes'][$scope]['latest'] ?? null;
            if (!\is_array($latest)
                || !\is_string($latest['natural_identity'] ?? null)
                || !\is_string($latest['assignment_digest'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            $expected = OkxPaperSourceOrdinal::restore($checkpoint->ordinalState);
            $expected->commit(
                $scope,
                $latest['natural_identity'],
                $latest['assignment_digest'],
                $event,
            );
            if (!$this->sameCanonicalValue($expected->snapshot(), $ordinalState)) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }
    }

    /**
     * @param array<string, mixed>|null $transition
     * @param array<string, mixed>|null $pendingFrontier
     */
    private function assertTransitionProducesEvent(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] ?array $transition,
        #[\SensitiveParameter] PaperMarketEvent $event,
        #[\SensitiveParameter] ?array $pendingFrontier,
    ): void {
        if ($transition === null) {
            $this->assertPendingOriginMatchesStream($event, $pendingFrontier);

            return;
        }
        $stream = $pendingFrontier['stream'] ?? self::uniqueControlStream($event);
        if (!\is_string($stream)
            || ($transition['symbol'] ?? null) !== $event->symbol
            || ($transition['stream'] ?? null) !== $stream
        ) {
            throw self::invalidCheckpoint();
        }
        $kind = $transition['kind'] ?? null;
        $stage = $transition['stage'] ?? null;
        $channel = $event->channel->value;
        $origin = $event->payload['origin'] ?? null;
        $valid = $kind === 'rest_fetch' && match ($stage) {
            'current_candles', 'history_candles' => str_starts_with($channel, 'candle_')
                && \in_array($origin, ['rest_history', 'rest_warmup'], true),
            'recent_trades', 'history_trades' => $channel === 'public_trade'
                && \in_array($origin, ['rest_history', 'rest_recovery'], true),
            'order_book' => $channel === 'top_of_book'
                && $origin === (\is_array($checkpoint->resyncBySymbol[$event->symbol] ?? null)
                    ? 'rest_resync_snapshot'
                    : 'rest_initial_snapshot'),
            default => false,
        };
        if ($kind === 'emit_boundary') {
            $valid = $channel === 'snapshot_boundary'
                && ($event->payload['reason'] ?? null) === $stage;
            if ($valid) {
                $this->assertBoundaryEventMatchesAcknowledgedSnapshot($checkpoint, $event);
            }
        }
        if ($kind === 'healthy_stop' && $stage === 'emit_stopped') {
            $valid = $channel === 'connection_state'
                && ($event->payload['state'] ?? null) === 'stopped';
        }
        if (!$valid) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertBoundaryEventMatchesAcknowledgedSnapshot(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] PaperMarketEvent $event,
    ): void {
        $bookFrontier = $checkpoint->streamFrontiers[$event->symbol . '/rest/top_of_book'] ?? null;
        if (!$bookFrontier instanceof OkxPaperStreamFrontier
            || ($event->payload['source_epoch'] ?? null) !== $checkpoint->sourceEpochs[$event->symbol]
            || ($event->payload['source_seq_id'] ?? null) !== $bookFrontier->sourceIdentity
        ) {
            throw self::invalidCheckpoint();
        }
    }

    /** @param array<string, mixed>|null $pendingFrontier */
    private function assertPendingOriginMatchesStream(
        #[\SensitiveParameter] PaperMarketEvent $event,
        #[\SensitiveParameter] ?array $pendingFrontier,
    ): void {
        $channel = $event->channel->value;
        if (\in_array($channel, ['connection_state', 'snapshot_boundary'], true)) {
            $stream = $pendingFrontier['stream'] ?? self::uniqueControlStream($event);
            if ($stream !== self::uniqueControlStream($event)) {
                throw self::invalidCheckpoint();
            }

            return;
        }

        $origin = $event->payload['origin'] ?? null;
        $transport = match (true) {
            str_starts_with($channel, 'candle_')
                && \in_array($origin, ['rest_history', 'rest_warmup'], true) => 'rest',
            str_starts_with($channel, 'candle_') && $origin === 'ws_candle' => 'ws',
            $channel === 'public_trade'
                && \in_array($origin, ['rest_history', 'rest_recovery'], true) => 'rest',
            $channel === 'public_trade' && $origin === 'ws_aggregated' => 'ws',
            $channel === 'top_of_book' && $origin === 'ws_books' => 'ws',
            default => null,
        };
        $stream = $pendingFrontier['stream'] ?? null;
        $streamChannel = $channel === 'candle_1h' ? 'candle_1H' : $channel;
        if ($transport === null
            || $stream !== implode('/', [$event->symbol, $transport, $streamChannel])
        ) {
            throw self::invalidCheckpoint();
        }
    }

    public function acknowledge(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $eventId,
    ): OkxPaperLiveCheckpoint {
        $this->assertCurrent($checkpoint);
        if (preg_match(self::SHA256_PATTERN, $eventId) !== 1) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        }

        if ($checkpoint->pendingEvent === null) {
            if ($checkpoint->lastAcknowledgedEventId !== null
                && hash_equals($checkpoint->lastAcknowledgedEventId, $eventId)
            ) {
                return $checkpoint;
            }

            throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        }
        if (!hash_equals($checkpoint->pendingEvent->eventId, $eventId)) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        }

        $state = $checkpoint->toArray();
        $acknowledgedFrontier = $checkpoint->pendingFrontier;
        if ($acknowledgedFrontier === null) {
            $controlStream = self::uniqueControlStream($checkpoint->pendingEvent);
            if ($controlStream !== null) {
                $acknowledgedFrontier = [
                    'stream' => $controlStream,
                    'frontier' => OkxPaperStreamFrontier::fromEvent($checkpoint->pendingEvent),
                ];
            }
        }
        if ($acknowledgedFrontier !== null) {
            $stream = $acknowledgedFrontier['stream'];
            $nextFrontier = $acknowledgedFrontier['frontier'];
            $currentFrontier = $checkpoint->streamFrontiers[$stream];
            if ($currentFrontier !== null) {
                $this->assertFrontierAdvances($stream, $currentFrontier, $nextFrontier);
            }
            $state['stream_frontiers'][$stream] = $nextFrontier->toArray();
        }
        $this->applyAcknowledgementWorkEffects($checkpoint, $state);
        $this->applyRecoveryAcknowledgementEffect($checkpoint, $state);
        $this->applyReconnectAcknowledgementEffect($checkpoint, $state);
        $state['last_acknowledged_event_id'] = $eventId;
        $state['pending_event'] = null;
        $state['pending_frontier'] = null;
        if ($state['phase'] === 'reconnecting') {
            $candidate = $this->validatedCheckpoint($state);
            $completedStream = $checkpoint->pendingFrontier['stream']
                ?? self::uniqueControlStream($checkpoint->pendingEvent);
            $headSymbol = $candidate->remainingSymbols[0] ?? null;
            $state['pending_transition'] = $this->nextReconnectRecoveryTransition(
                $candidate,
                $headSymbol === $checkpoint->pendingEvent->symbol ? $completedStream : null,
            );
        }
        $next = $this->validatedCheckpoint($state);
        $this->assertReconnectProgresses($next, allowCompletion: true);
        $this->assertRecoveryStateProgresses($next, allowCompletion: true);
        $this->assertTransitionTargetsWorkHead($next);
        $this->persist($next);

        return $next;
    }

    /** @param array<string, mixed> $state */
    private function applyAcknowledgementWorkEffects(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] array &$state,
    ): void {
        $event = $checkpoint->pendingEvent
            ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        $channel = $event->channel->value;
        if ($channel === 'snapshot_boundary') {
            $reason = $event->payload['reason'] ?? null;
            $expectedBoundary = \is_string($reason) ? [
                'symbol' => $event->symbol,
                'reason' => $reason,
            ] : null;
            if ($expectedBoundary === null
                || ($checkpoint->remainingBoundaries[0] ?? null) !== $expectedBoundary
                || ($checkpoint->remainingSymbols[0] ?? null) !== $event->symbol
            ) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
            }
            $state['remaining_boundaries'] = array_slice($checkpoint->remainingBoundaries, 1);
            $state['remaining_symbols'] = array_slice($checkpoint->remainingSymbols, 1);

            return;
        }

        $stopped = $channel === 'connection_state'
            && ($event->payload['state'] ?? null) === 'stopped';
        if (!$stopped) {
            return;
        }
        if ($checkpoint->phase !== 'stopping'
            || !$checkpoint->healthyStop['requested']
            || ($checkpoint->healthyStop['remaining_symbols'][0] ?? null) !== $event->symbol
            || ($checkpoint->remainingSymbols[0] ?? null) !== $event->symbol
        ) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        }
        $state['remaining_symbols'] = array_slice($checkpoint->remainingSymbols, 1);
        $state['healthy_stop']['remaining_symbols'] = array_slice(
            $checkpoint->healthyStop['remaining_symbols'],
            1,
        );
    }

    /** @param array<string, mixed> $state */
    private function applyReconnectAcknowledgementEffect(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] array &$state,
    ): void {
        $reconnect = $checkpoint->reconnect;
        if ($checkpoint->phase !== 'streaming'
            || $reconnect['attempt'] === 0
            || !\is_string($reconnect['stable_since'])
            || !$this->isNewMarketFrontierAcknowledgement($checkpoint)
        ) {
            return;
        }
        $acceptedEvents = min(
            OkxPaperLivePolicy::RECONNECT_STABLE_ACCEPTED_EVENTS,
            $reconnect['accepted_events'] + 1,
        );
        $stableSince = new \DateTimeImmutable($reconnect['stable_since']);
        if ($acceptedEvents === OkxPaperLivePolicy::RECONNECT_STABLE_ACCEPTED_EVENTS
            && $this->clock->now() >= $stableSince->modify(sprintf(
                '+%d seconds',
                (int) OkxPaperLivePolicy::RECONNECT_STABLE_SECONDS,
            ))
        ) {
            $state['reconnect'] = [
                'attempt' => 0,
                'deadline_at' => null,
                'stable_since' => null,
                'accepted_events' => 0,
            ];

            return;
        }
        $state['reconnect']['accepted_events'] = $acceptedEvents;
    }

    /** @param array<string, mixed> $state */
    private function applyRecoveryAcknowledgementEffect(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] array &$state,
    ): bool {
        $event = $checkpoint->pendingEvent
            ?? throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        $symbol = $event->symbol;
        $resync = $checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync)) {
            return false;
        }

        if ($resync['policy'] === 'frontier_overlap_v1') {
            $stream = $checkpoint->pendingFrontier['stream'] ?? null;
            $currentFrontier = \is_string($stream)
                ? ($checkpoint->streamFrontiers[$stream] ?? null)
                : null;
            if (!\is_string($stream)
                || !$currentFrontier instanceof OkxPaperStreamFrontier
                || !$this->sameCanonicalValue($currentFrontier, $resync['frontier'])
                || !$this->isNewMarketFrontierAcknowledgement($checkpoint)
            ) {
                return false;
            }
            if (($checkpoint->overlapPaginationByStream[$stream] ?? null) !== null) {
                $state['overlap_pagination_by_stream'][$stream] = null;
            }
            $otherPaginationRemains = false;
            foreach ($state['overlap_pagination_by_stream'] as $candidateStream => $pagination) {
                if (str_starts_with($candidateStream, $symbol . '/') && $pagination !== null) {
                    $otherPaginationRemains = true;
                    break;
                }
            }
            if (!$otherPaginationRemains) {
                $state['resync_by_symbol'][$symbol] = null;
                $this->leaveResyncingPhaseWhenRecoveryIsComplete($state);
            }

            return true;
        }

        $reason = $event->channel->value === 'snapshot_boundary'
            ? ($event->payload['reason'] ?? null)
            : null;
        if (!\in_array($reason, ['reconnect', 'sequence_gap'], true)) {
            return false;
        }
        $bookFrontier = $checkpoint->streamFrontiers[$symbol . '/rest/top_of_book'] ?? null;
        $valid = $bookFrontier instanceof OkxPaperStreamFrontier
            && ($event->payload['source_seq_id'] ?? null) === $bookFrontier->sourceIdentity
            && ($event->payload['source_epoch'] ?? null) === $checkpoint->sourceEpochs[$symbol]
            && $resync['policy'] === 'book_seq_overlap_v1';
        if (!$valid) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_acknowledgement_invalid');
        }
        $state['resync_by_symbol'][$symbol] = null;
        $this->leaveResyncingPhaseWhenRecoveryIsComplete($state);

        return false;
    }

    /** @param array<string, mixed> $state */
    private function leaveResyncingPhaseWhenRecoveryIsComplete(array &$state): void
    {
        if ($state['phase'] === 'resyncing'
            && array_filter(
                $state['resync_by_symbol'],
                static fn (mixed $resync): bool => $resync !== null,
            ) === []
        ) {
            $state['phase'] = 'streaming';
        }
    }

    private function assertTransitionTargetsWorkHead(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
    ): void {
        $transition = $checkpoint->pendingTransition;
        if ($transition === null) {
            return;
        }
        if ($transition['kind'] === 'emit_boundary') {
            $expectedBoundary = [
                'symbol' => $transition['symbol'],
                'reason' => $transition['stage'],
            ];
            if (($checkpoint->remainingBoundaries[0] ?? null) !== $expectedBoundary
                || ($checkpoint->remainingSymbols[0] ?? null) !== $transition['symbol']
            ) {
                throw self::invalidCheckpoint();
            }
            $this->assertBoundaryIsActionable($checkpoint, $transition['symbol'], $transition['stage']);

            return;
        }
        if ($transition['kind'] === 'healthy_stop' && $transition['stage'] === 'emit_stopped') {
            if (($checkpoint->healthyStop['remaining_symbols'][0] ?? null) !== $transition['symbol']
                || ($checkpoint->remainingSymbols[0] ?? null) !== $transition['symbol']
            ) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        $cleanupTimerCancellation = $transition['kind'] === 'timer_cancel'
            && $transition['stage'] === 'cancel_resync_timer'
            && \in_array($checkpoint->phase, ['stopping', 'failed'], true);
        if ($transition['symbol'] !== null
            && !$cleanupTimerCancellation
            && ($checkpoint->remainingSymbols[0] ?? null) !== $transition['symbol']
        ) {
            throw self::invalidCheckpoint();
        }
        if ($checkpoint->phase === 'warming' && $transition['kind'] === 'rest_fetch') {
            $this->assertWarmingRestWorkIsActionable($checkpoint, $transition);
        }
        if ($checkpoint->phase === 'reconnecting'
            && $transition['kind'] === 'transport_connect'
            && !$this->isInterruptedInitialConnect($checkpoint)
        ) {
            $this->assertReconnectTransportHasWriteAheadBudget($checkpoint);
        }
    }

    private function assertSemanticallyResumable(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
    ): void {
        $this->assertTransitionTargetsWorkHead($checkpoint);
        if ($checkpoint->phase !== 'reconnecting'
            || $checkpoint->pendingTransition !== null
            || $checkpoint->pendingEvent !== null
        ) {
            return;
        }
        $activeRecovery = array_filter(
            $checkpoint->resyncBySymbol,
            static fn (mixed $resync): bool => $resync !== null,
        ) !== [] || array_filter(
            $checkpoint->overlapPaginationByStream,
            static fn (mixed $pagination): bool => $pagination !== null,
        ) !== [];
        if ($activeRecovery
            || $checkpoint->remainingSymbols !== []
            || $checkpoint->remainingBoundaries !== []
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function isInterruptedInitialConnect(OkxPaperLiveCheckpoint $checkpoint): bool
    {
        return $checkpoint->connectionEpoch === 1
            && $checkpoint->remainingSymbols === []
            && $checkpoint->remainingBoundaries === []
            && $checkpoint->reconnect === [
                'attempt' => 0,
                'deadline_at' => null,
                'stable_since' => null,
                'accepted_events' => 0,
            ];
    }

    /** @param array<string, mixed> $transition */
    private function assertWarmingRestWorkIsActionable(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        #[\SensitiveParameter] array $transition,
    ): void {
        $symbol = $transition['symbol'] ?? null;
        $stream = $transition['stream'] ?? null;
        $stage = $transition['stage'] ?? null;
        if (!\is_string($symbol) || !\is_string($stream) || !\is_string($stage)) {
            throw self::invalidCheckpoint();
        }

        $completedPrefix = 0;
        foreach (self::WARMING_REST_WORK as $position => $work) {
            $frontier = $checkpoint->streamFrontiers[$symbol . '/' . $work['suffix']] ?? null;
            if (!$frontier instanceof OkxPaperStreamFrontier) {
                break;
            }
            $completedPrefix = $position + 1;
        }
        for ($position = $completedPrefix; $position < \count(self::WARMING_REST_WORK); ++$position) {
            $later = self::WARMING_REST_WORK[$position];
            if (($checkpoint->streamFrontiers[$symbol . '/' . $later['suffix']] ?? null) !== null) {
                throw self::invalidCheckpoint();
            }
        }

        $actionPosition = null;
        foreach (self::WARMING_REST_WORK as $position => $work) {
            if ($stream === $symbol . '/' . $work['suffix'] && \in_array($stage, $work['stages'], true)) {
                $actionPosition = $position;
                break;
            }
        }
        $repeatPosition = $completedPrefix > 0 ? $completedPrefix - 1 : null;
        if (!\is_int($actionPosition)
            || ($actionPosition !== $completedPrefix && $actionPosition !== $repeatPosition)
            || ($stage === 'history_candles' || $stage === 'history_trades')
                && !($checkpoint->streamFrontiers[$stream] ?? null) instanceof OkxPaperStreamFrontier
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertBoundaryIsActionable(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
        string $reason,
    ): void {
        $bookFrontier = $checkpoint->streamFrontiers[$symbol . '/rest/top_of_book'] ?? null;
        if (!$bookFrontier instanceof OkxPaperStreamFrontier) {
            throw self::invalidCheckpoint();
        }
        if ($reason === 'initial') {
            if ($checkpoint->phase !== 'warming'
                || $checkpoint->sourceEpochs[$symbol] !== 1
                || ($checkpoint->streamFrontiers[$symbol . '/control/snapshot_boundary'] ?? null) !== null
            ) {
                throw self::invalidCheckpoint();
            }
            foreach (self::WARMING_REST_WORK as $work) {
                if (!($checkpoint->streamFrontiers[$symbol . '/' . $work['suffix']] ?? null)
                    instanceof OkxPaperStreamFrontier
                ) {
                    throw self::invalidCheckpoint();
                }
            }

            return;
        }

        $expectedPhase = $reason === 'reconnect' ? 'reconnecting' : 'resyncing';
        $resync = $checkpoint->resyncBySymbol[$symbol] ?? null;
        $previousBoundary = $checkpoint->streamFrontiers[$symbol . '/control/snapshot_boundary'] ?? null;
        $interruptedResync = $reason === 'sequence_gap'
            && $this->isInterruptedResyncReconnect($checkpoint);
        if (($checkpoint->phase !== $expectedPhase && !$interruptedResync)
            || !\is_array($resync)
            || $resync['policy'] !== 'book_seq_overlap_v1'
            || !$previousBoundary instanceof OkxPaperStreamFrontier
        ) {
            throw self::invalidCheckpoint();
        }
        $previousParts = explode('|', $previousBoundary->sourceIdentity);
        if (\count($previousParts) !== 3
            || preg_match('/\A[1-9][0-9]*\z/D', $previousParts[0]) !== 1
            || $checkpoint->sourceEpochs[$symbol] <= (int) $previousParts[0]
        ) {
            throw self::invalidCheckpoint();
        }
        $this->assertCurrentRecoveryBookSnapshotWasAcknowledged(
            $checkpoint,
            $symbol,
            $bookFrontier,
        );
    }

    private function isInterruptedResyncReconnect(OkxPaperLiveCheckpoint $checkpoint): bool
    {
        $transition = $checkpoint->pendingTransition;
        $symbol = $transition['symbol'] ?? null;
        $resync = \is_string($symbol) ? ($checkpoint->resyncBySymbol[$symbol] ?? null) : null;

        return $checkpoint->phase === 'reconnecting'
            && $checkpoint->reconnect === [
                'attempt' => 0,
                'deadline_at' => null,
                'stable_since' => null,
                'accepted_events' => 0,
            ]
            && \is_array($resync)
            && $resync['policy'] === 'book_seq_overlap_v1'
            && ($checkpoint->remainingBoundaries[0] ?? null) === [
                'symbol' => $symbol,
                'reason' => 'sequence_gap',
            ];
    }

    private function assertCurrentRecoveryBookSnapshotWasAcknowledged(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
        OkxPaperStreamFrontier $bookFrontier,
    ): void {
        if (!$this->currentRecoveryBookSnapshotWasAcknowledged(
            $checkpoint,
            $symbol,
            $bookFrontier,
        )) {
            throw self::invalidCheckpoint();
        }
    }

    private function currentRecoveryBookSnapshotWasAcknowledged(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
        OkxPaperStreamFrontier $bookFrontier,
    ): bool {
        $latestEventState = $checkpoint->ordinalState['scopes'][
            'okx/' . $symbol . '/top_of_book'
        ]['latest']['event'] ?? null;
        try {
            if (!\is_array($latestEventState) || array_is_list($latestEventState)) {
                throw new \InvalidArgumentException();
            }
            $latestEvent = PaperMarketEvent::fromArray($latestEventState);
            $latestFrontier = OkxPaperStreamFrontier::fromEvent($latestEvent);
        } catch (\Throwable) {
            return false;
        }

        return $latestEvent->symbol === $symbol
            && $latestEvent->channel->value === 'top_of_book'
            && ($latestEvent->payload['origin'] ?? null) === 'rest_resync_snapshot'
            && ($latestEvent->payload['source_epoch'] ?? null) === $checkpoint->sourceEpochs[$symbol]
            && ($latestEvent->payload['source_seq_id'] ?? null) === $bookFrontier->sourceIdentity
            && $this->sameCanonicalValue($latestFrontier, $bookFrontier)
            && $checkpoint->lastAcknowledgedEventId !== null
            && hash_equals($checkpoint->lastAcknowledgedEventId, $latestEvent->eventId);
    }

    private function assertReconnectTransportHasWriteAheadBudget(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
    ): void {
        if ($checkpoint->reconnect['attempt'] < 1
            || !\is_string($checkpoint->reconnect['deadline_at'])
            || $checkpoint->reconnect['stable_since'] !== null
            || $checkpoint->reconnect['accepted_events'] !== 0
            || $checkpoint->connectionEpoch < 2
            || $checkpoint->remainingSymbols !== ['BTCUSDT', 'ETHUSDT']
            || $checkpoint->remainingBoundaries !== [
                ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
                ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
            ]
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function isNewMarketFrontierAcknowledgement(OkxPaperLiveCheckpoint $checkpoint): bool
    {
        $event = $checkpoint->pendingEvent;
        $pendingFrontier = $checkpoint->pendingFrontier;
        if ($event === null
            || $pendingFrontier === null
            || !\in_array($event->channel->value, [
                'candle_1m',
                'candle_5m',
                'candle_15m',
                'candle_1h',
                'public_trade',
                'top_of_book',
            ], true)
        ) {
            return false;
        }
        $stream = $pendingFrontier['stream'];
        if (!str_starts_with($stream, $event->symbol . '/rest/')
            && !str_starts_with($stream, $event->symbol . '/ws/')
        ) {
            return false;
        }
        $current = $checkpoint->streamFrontiers[$stream] ?? null;
        if ($current === null) {
            return true;
        }
        try {
            $this->assertFrontierAdvances($stream, $current, $pendingFrontier['frontier']);

            return true;
        } catch (OkxPaperLiveIntegrityException) {
            return false;
        }
    }

    private static function uniqueControlStream(PaperMarketEvent $event): ?string
    {
        $channel = $event->channel->value;
        if (!\in_array($event->symbol, ['BTCUSDT', 'ETHUSDT'], true)
            || !\in_array($channel, ['connection_state', 'snapshot_boundary'], true)
        ) {
            return null;
        }

        return $event->symbol . '/control/' . $channel;
    }

    /** @param array<string, mixed> $state */
    private function validatedCheckpoint(#[\SensitiveParameter] array $state): OkxPaperLiveCheckpoint
    {
        try {
            return OkxPaperLiveCheckpoint::fromArray($state);
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }
    }

    private function persist(#[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint): void
    {
        $this->assertBoundIdentity($checkpoint);
        $checkpoint = $this->validatedCheckpoint($checkpoint->toArray());
        $this->assertSemanticallyResumable($checkpoint);
        try {
            $contents = CanonicalJson::encode($checkpoint->toArray()) . "\n";
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }
        if (\strlen($contents) > self::MAX_CHECKPOINT_BYTES) {
            throw self::invalidCheckpoint();
        }
        $this->assertDurableStateUnchanged();
        $this->atomicWrite($contents);
        $this->currentStateHash = hash('sha256', $contents);
        $this->currentFileIdentity = $this->checkpointIdentity();
        $this->currentCheckpoint = $checkpoint;
    }

    private function assertBoundIdentity(#[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint): void
    {
        if ($this->datasetId === null
            || $this->configurationSha256 === null
            || !hash_equals($this->datasetId, $checkpoint->datasetId)
            || !hash_equals($this->configurationSha256, $checkpoint->configurationSha256)
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertCurrent(#[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint): void
    {
        $this->assertBoundIdentity($checkpoint);
        if ($this->currentStateHash === null
            || !hash_equals($this->currentStateHash, $this->stateHash($checkpoint))
        ) {
            throw self::invalidCheckpoint();
        }
    }

    /** @param array<string, mixed>|null $pendingTransition */
    private function assertPhaseGraphTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $targetPhase,
        #[\SensitiveParameter] ?array $pendingTransition,
    ): void {
        $sourcePhase = $current->phase;
        if ($sourcePhase === $targetPhase) {
            if (!$this->samePhasePendingTransitionCanAdvance(
                $current,
                $candidate,
                $targetPhase,
                $pendingTransition,
            )) {
                throw self::invalidCheckpoint();
            }

            return;
        }

        $reconnectEntry = $targetPhase === 'reconnecting'
            && $this->isExactReconnectEntryTransition($current, $pendingTransition);
        $kind = $pendingTransition['kind'] ?? null;
        $stage = $pendingTransition['stage'] ?? null;
        $streamingEntry = $targetPhase === 'streaming'
            && $pendingTransition === null
            && $this->recoveryWorkIsComplete($candidate);
        $valid = match ($sourcePhase) {
            'warming' => $targetPhase === 'connecting'
                && $this->isTransportTransition(
                    $pendingTransition,
                    'transport_connect',
                    'public',
                    'connect',
                )
                && $this->initialWarmupIsComplete($current),
            'connecting' => ($targetPhase === 'subscribing'
                    && $this->isExactInitialTransportSuccessor(
                        $current->pendingTransition,
                        $pendingTransition,
                    ))
                || $reconnectEntry,
            'subscribing' => ($targetPhase === 'connecting'
                    && $this->isTransportTransition(
                        $current->pendingTransition,
                        'subscription_send',
                        'public',
                        'subscribe',
                    )
                    && $this->isTransportTransition(
                        $pendingTransition,
                        'transport_connect',
                        'business',
                        'connect',
                    ))
                || ($streamingEntry
                    && $this->isTransportTransition(
                        $current->pendingTransition,
                        'subscription_send',
                        'business',
                        'subscribe',
                    )
                    && $this->initialWarmupIsComplete($current))
                || $reconnectEntry,
            'streaming' => ($targetPhase === 'resyncing'
                    && (($kind === 'rest_fetch' && $stage === 'order_book')
                        || ($kind === 'timer_schedule' && $stage === 'resync_timeout')))
                || ($targetPhase === 'stopping'
                    && $kind === 'healthy_stop'
                    && $stage === 'emit_stopped')
                || $reconnectEntry,
            'resyncing' => $streamingEntry || $reconnectEntry,
            'reconnecting' => $streamingEntry && $this->reconnectRecoveryIsComplete($candidate),
            'stopping' => $targetPhase === 'complete'
                && $pendingTransition === null
                && $current->pendingTransition === [
                    'kind' => 'healthy_stop',
                    'symbol' => null,
                    'stream' => null,
                    'stage' => 'finalize',
                ],
            'complete', 'failed' => false,
            default => false,
        };
        if (!$valid) {
            throw self::invalidCheckpoint();
        }
    }

    /** @param array<string, mixed>|null $pendingTransition */
    private function isExactReconnectEntryTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] ?array $pendingTransition,
    ): bool {
        if ($current->pendingTransition !== null) {
            return $this->sameCanonicalValue($current->pendingTransition, $pendingTransition);
        }

        return $this->sameCanonicalValue($pendingTransition, self::CLEANUP_ACTIONS[0]);
    }

    /** @param array<string, mixed>|null $pendingTransition */
    private function samePhasePendingTransitionCanAdvance(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $phase,
        #[\SensitiveParameter] ?array $pendingTransition,
    ): bool {
        $currentTransition = $current->pendingTransition;
        if ($this->sameCanonicalValue($currentTransition, $pendingTransition)) {
            return true;
        }
        if ($currentTransition === null) {
            return match ($phase) {
                'reconnecting' => $this->isExactReconnectNullSuccessor(
                    $current,
                    $pendingTransition,
                ),
                'failed' => false,
                default => true,
            };
        }
        if ($this->isExactOverlapCompletionTransition($candidate, $phase, $pendingTransition)
            || $this->isExactHistoryPaginationSuccessor(
                $current,
                $candidate,
                $pendingTransition,
            )
        ) {
            return true;
        }

        return match ($phase) {
            'reconnecting' => $this->isExactReconnectActionSuccessor(
                $current,
                $candidate,
                $pendingTransition,
            ),
            'resyncing' => $this->isExactResyncActionSuccessor(
                $currentTransition,
                $pendingTransition,
            ),
            'stopping', 'failed' => $this->isExactCleanupActionSuccessor(
                $currentTransition,
                $pendingTransition,
                $phase === 'stopping',
            ),
            default => false,
        };
    }

    /** @param array<string, mixed>|null $nextTransition */
    private function isExactReconnectNullSuccessor(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] ?array $nextTransition,
    ): bool {
        $activeRecovery = array_filter(
            $current->resyncBySymbol,
            static fn (mixed $resync): bool => $resync !== null,
        ) !== [] || array_filter(
            $current->overlapPaginationByStream,
            static fn (mixed $pagination): bool => $pagination !== null,
        ) !== [];
        $expected = $activeRecovery
            ? $this->expectedReconnectRecoveryTransition($current)
            : self::CLEANUP_ACTIONS[0];

        return $expected !== null && $this->sameCanonicalValue($expected, $nextTransition);
    }

    /** @param array<string, mixed>|null $nextTransition */
    private function isExactHistoryPaginationSuccessor(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        #[\SensitiveParameter] ?array $nextTransition,
    ): bool {
        $currentTransition = $current->pendingTransition;
        $historyStage = match ($currentTransition['stage'] ?? null) {
            'current_candles' => 'history_candles',
            'recent_trades' => 'history_trades',
            default => null,
        };
        $stream = $currentTransition['stream'] ?? null;
        if ($historyStage === null
            || !\is_string($stream)
            || ($currentTransition['kind'] ?? null) !== 'rest_fetch'
            || ($nextTransition['kind'] ?? null) !== 'rest_fetch'
            || ($nextTransition['symbol'] ?? null) !== ($currentTransition['symbol'] ?? null)
            || ($nextTransition['stream'] ?? null) !== $stream
            || ($nextTransition['stage'] ?? null) !== $historyStage
        ) {
            return false;
        }
        $pagination = $candidate->overlapPaginationByStream[$stream] ?? null;

        return \is_array($pagination)
            && $pagination['endpoint'] === $historyStage
            && !$this->sameCanonicalValue(
                $current->overlapPaginationByStream[$stream] ?? null,
                $pagination,
            );
    }

    /** @param array<string, mixed>|null $nextTransition */
    private function isExactReconnectActionSuccessor(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        #[\SensitiveParameter] ?array $nextTransition,
    ): bool {
        $currentTransition = $current->pendingTransition;
        if ($this->isTransportTransition(
            $currentTransition,
            'subscription_send',
            'business',
            'subscribe',
        )) {
            return $this->isExactFirstReconnectRecoverySuccessor(
                $current,
                $candidate,
                $nextTransition,
            );
        }
        if ($this->hasRecoveryTransitionMutation($current, $candidate, $nextTransition)) {
            return true;
        }
        if ($this->sameCanonicalValue($nextTransition, self::CLEANUP_ACTIONS[0])) {
            if (!$this->pendingFrontierRecoveryAuthorityIsDurable($current)) {
                return false;
            }
            foreach (self::CLEANUP_ACTIONS as $cleanupAction) {
                if ($this->sameCanonicalValue($currentTransition, $cleanupAction)) {
                    return $this->isInterruptedResyncReconnect($current);
                }
            }

            return true;
        }
        $successors = [
            [
                ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'public', 'stage' => 'close'],
                ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'business', 'stage' => 'close'],
            ],
            [
                ['kind' => 'transport_close', 'symbol' => null, 'stream' => 'business', 'stage' => 'close'],
                ['kind' => 'timer_schedule', 'symbol' => null, 'stream' => null, 'stage' => 'reconnect_delay'],
            ],
            [
                ['kind' => 'timer_schedule', 'symbol' => null, 'stream' => null, 'stage' => 'reconnect_delay'],
                ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'cancel_reconnect_timer'],
            ],
            [
                ['kind' => 'timer_schedule', 'symbol' => null, 'stream' => null, 'stage' => 'reconnect_delay'],
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
            ],
            [
                ['kind' => 'timer_cancel', 'symbol' => null, 'stream' => null, 'stage' => 'cancel_reconnect_timer'],
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
            ],
            [
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'public', 'stage' => 'connect'],
                ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
            ],
            [
                ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'public', 'stage' => 'subscribe'],
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
            ],
            [
                ['kind' => 'transport_connect', 'symbol' => null, 'stream' => 'business', 'stage' => 'connect'],
                ['kind' => 'subscription_send', 'symbol' => null, 'stream' => 'business', 'stage' => 'subscribe'],
            ],
        ];
        foreach ($successors as [$action, $successor]) {
            if ($this->sameCanonicalValue($currentTransition, $action)
                && $this->sameCanonicalValue($nextTransition, $successor)
            ) {
                return true;
            }
        }
        return $this->isExactBookTimerCancellationSuccessor(
            $currentTransition,
            $nextTransition,
            'reconnect',
        );
    }

    /** @param array<string, mixed>|null $nextTransition */
    private function isExactFirstReconnectRecoverySuccessor(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        #[\SensitiveParameter] ?array $nextTransition,
    ): bool {
        $expected = $this->expectedReconnectRecoveryTransition($current);
        if ($expected === null || !$this->sameCanonicalValue($expected, $nextTransition)) {
            return false;
        }
        $symbol = $expected['symbol'];
        $stream = $expected['stream'];
        if (!\is_string($symbol) || !\is_string($stream)) {
            return false;
        }
        $currentResync = $current->resyncBySymbol[$symbol] ?? null;
        $candidateResync = $candidate->resyncBySymbol[$symbol] ?? null;
        if ($currentResync !== null) {
            return $this->sameCanonicalValue($currentResync, $candidateResync);
        }
        if ($expected['stage'] === 'order_book') {
            $bookFrontier = $current->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null;

            return $bookFrontier instanceof OkxPaperStreamFrontier
                && \is_array($candidateResync)
                && $candidateResync['attempt'] === 1
                && $candidateResync['source_sequence'] === $bookFrontier->sourceIdentity
                && $candidateResync['policy'] === 'book_seq_overlap_v1'
                && $this->sameCanonicalValue($candidateResync['frontier'], $bookFrontier)
                && $candidate->sourceEpochs[$symbol] === $current->sourceEpochs[$symbol] + 1;
        }
        $frontier = $current->streamFrontiers[$stream] ?? null;

        return $frontier instanceof OkxPaperStreamFrontier
            && \is_array($candidateResync)
            && $candidateResync['attempt'] === 1
            && $candidateResync['source_sequence'] === null
            && $candidateResync['policy'] === 'frontier_overlap_v1'
            && $this->sameCanonicalValue($candidateResync['frontier'], $frontier);
    }

    /**
     * @return array{kind: string, symbol: string|null, stream: string|null, stage: string}|null
     */
    private function expectedReconnectRecoveryTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
    ): ?array {
        return $this->nextReconnectRecoveryTransition($checkpoint);
    }

    /**
     * @return array{kind: string, symbol: string|null, stream: string|null, stage: string}|null
     */
    private function nextReconnectRecoveryTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        ?string $completedStream = null,
    ): ?array {
        $symbol = $checkpoint->remainingSymbols[0] ?? null;
        if (!\is_string($symbol)) {
            return null;
        }

        $paginations = [];
        foreach ($checkpoint->overlapPaginationByStream as $stream => $pagination) {
            if ($pagination !== null && str_starts_with($stream, $symbol . '/')) {
                $paginations[$stream] = $pagination;
            }
        }
        if ($paginations !== []) {
            ksort($paginations, SORT_STRING);
            $stream = array_key_first($paginations);
            $pagination = $paginations[$stream];
            if (!\is_string($stream) || !\is_array($pagination)) {
                throw self::invalidCheckpoint();
            }

            return [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $stream,
                'stage' => $pagination['endpoint'],
            ];
        }

        $resync = $checkpoint->resyncBySymbol[$symbol] ?? null;
        if (\is_array($resync)) {
            if ($resync['policy'] === 'book_seq_overlap_v1') {
                $bookFrontier = $checkpoint->streamFrontiers[$symbol . '/rest/top_of_book'] ?? null;
                $boundary = $checkpoint->remainingBoundaries[0] ?? null;
                if ($bookFrontier instanceof OkxPaperStreamFrontier
                    && \is_array($boundary)
                    && $boundary['symbol'] === $symbol
                    && \in_array($boundary['reason'], ['reconnect', 'sequence_gap'], true)
                    && $this->currentRecoveryBookSnapshotWasAcknowledged(
                        $checkpoint,
                        $symbol,
                        $bookFrontier,
                    )
                ) {
                    return [
                        'kind' => 'emit_boundary',
                        'symbol' => $symbol,
                        'stream' => $symbol . '/control/snapshot_boundary',
                        'stage' => $boundary['reason'],
                    ];
                }

                return [
                    'kind' => 'rest_fetch',
                    'symbol' => $symbol,
                    'stream' => $symbol . '/rest/top_of_book',
                    'stage' => 'order_book',
                ];
            }

            return $this->uniqueFrontierRecoveryTransition(
                $checkpoint,
                $symbol,
                $resync['frontier'],
            );
        }

        if ($completedStream === null) {
            $lastAcknowledged = $this->lastAcknowledgedRecoveryTransition($checkpoint, $symbol);
            if ($lastAcknowledged !== null) {
                return $lastAcknowledged;
            }
        }

        $afterCompleted = $completedStream === null;
        foreach ($checkpoint->streamFrontiers as $stream => $frontier) {
            $stage = $this->frontierRecoveryStage($symbol, $stream);
            if ($stage === null || !$frontier instanceof OkxPaperStreamFrontier) {
                continue;
            }
            if (!$afterCompleted) {
                if ($stream === $completedStream) {
                    $afterCompleted = true;
                }

                continue;
            }

            return [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $stream,
                'stage' => $stage,
            ];
        }
        if (!$afterCompleted) {
            return null;
        }

        return ($checkpoint->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null)
            instanceof OkxPaperStreamFrontier
                ? [
                    'kind' => 'rest_fetch',
                    'symbol' => $symbol,
                    'stream' => $symbol . '/rest/top_of_book',
                    'stage' => 'order_book',
                ]
                : null;
    }

    /**
     * @return array{kind: string, symbol: string, stream: string, stage: string}|null
     */
    private function lastAcknowledgedRecoveryTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
    ): ?array {
        $eventId = $checkpoint->lastAcknowledgedEventId;
        if ($eventId === null) {
            return null;
        }

        foreach ($checkpoint->ordinalState['scopes'] as $scope) {
            $eventState = $scope['latest']['event'] ?? null;
            if (!\is_array($eventState) || array_is_list($eventState)) {
                continue;
            }
            try {
                $event = PaperMarketEvent::fromArray($eventState);
            } catch (\Throwable) {
                continue;
            }
            if ($event->symbol !== $symbol || !hash_equals($eventId, $event->eventId)) {
                continue;
            }

            $stream = $this->recoveryStreamForEvent($event);
            if ($stream === null
                || !($checkpoint->streamFrontiers[$stream] ?? null) instanceof OkxPaperStreamFrontier
            ) {
                return null;
            }
            $stage = $this->frontierRecoveryStage($symbol, $stream);
            if ($stage === null && $stream === $symbol . '/ws/top_of_book') {
                return [
                    'kind' => 'rest_fetch',
                    'symbol' => $symbol,
                    'stream' => $symbol . '/rest/top_of_book',
                    'stage' => 'order_book',
                ];
            }

            return $stage === null ? null : [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $stream,
                'stage' => $stage,
            ];
        }

        return null;
    }

    private function recoveryStreamForEvent(#[\SensitiveParameter] PaperMarketEvent $event): ?string
    {
        $channel = $event->channel->value;
        $origin = $event->payload['origin'] ?? null;
        $transport = match (true) {
            str_starts_with($channel, 'candle_')
                && \in_array($origin, ['rest_history', 'rest_warmup'], true) => 'rest',
            str_starts_with($channel, 'candle_') && $origin === 'ws_candle' => 'ws',
            $channel === 'public_trade'
                && \in_array($origin, ['rest_history', 'rest_recovery'], true) => 'rest',
            $channel === 'public_trade' && $origin === 'ws_aggregated' => 'ws',
            $channel === 'top_of_book' && $origin === 'ws_books' => 'ws',
            default => null,
        };
        if ($transport === null) {
            return null;
        }
        $streamChannel = $channel === 'candle_1h' ? 'candle_1H' : $channel;

        return implode('/', [$event->symbol, $transport, $streamChannel]);
    }

    private function pendingFrontierRecoveryAuthorityIsDurable(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
    ): bool {
        $transition = $checkpoint->pendingTransition;
        $symbol = $transition['symbol'] ?? null;
        $stream = $transition['stream'] ?? null;
        if (($transition['kind'] ?? null) !== 'rest_fetch'
            || !\is_string($symbol)
            || !\is_string($stream)
        ) {
            return true;
        }
        if (($checkpoint->overlapPaginationByStream[$stream] ?? null) !== null) {
            return true;
        }
        $stage = $this->frontierRecoveryStage($symbol, $stream);
        if ($stage === null || ($transition['stage'] ?? null) !== $stage) {
            return true;
        }
        $resync = $checkpoint->resyncBySymbol[$symbol] ?? null;
        if (!\is_array($resync) || $resync['policy'] !== 'frontier_overlap_v1') {
            return true;
        }
        $expected = $this->uniqueFrontierRecoveryTransition(
            $checkpoint,
            $symbol,
            $resync['frontier'],
        );

        return $expected !== null && $this->sameCanonicalValue($expected, $transition);
    }

    /**
     * @return array{kind: string, symbol: string, stream: string, stage: string}|null
     */
    private function uniqueFrontierRecoveryTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint,
        string $symbol,
        #[\SensitiveParameter] OkxPaperStreamFrontier $targetFrontier,
    ): ?array {
        $match = null;
        foreach ($checkpoint->streamFrontiers as $stream => $frontier) {
            $stage = $this->frontierRecoveryStage($symbol, $stream);
            if ($stage === null
                || !$frontier instanceof OkxPaperStreamFrontier
                || !$this->sameCanonicalValue($frontier, $targetFrontier)
            ) {
                continue;
            }
            if ($match !== null) {
                return null;
            }
            $match = [
                'kind' => 'rest_fetch',
                'symbol' => $symbol,
                'stream' => $stream,
                'stage' => $stage,
            ];
        }

        return $match;
    }

    private function frontierRecoveryStage(string $symbol, string $stream): ?string
    {
        if (!str_starts_with($stream, $symbol . '/')) {
            return null;
        }
        if (preg_match('/\/(?:rest|ws)\/candle_(?:1m|5m|15m|1H)\z/D', $stream) === 1) {
            return 'current_candles';
        }

        return preg_match('/\/(?:rest|ws)\/public_trade\z/D', $stream) === 1
            ? 'recent_trades'
            : null;
    }

    /** @param array<string, mixed>|null $nextTransition */
    private function hasRecoveryTransitionMutation(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        #[\SensitiveParameter] ?array $nextTransition,
    ): bool {
        $currentTransition = $current->pendingTransition;
        $businessSubscription = [
            'kind' => 'subscription_send',
            'symbol' => null,
            'stream' => 'business',
            'stage' => 'subscribe',
        ];
        if (($nextTransition['kind'] ?? null) !== 'rest_fetch'
            || !$this->sameCanonicalValue($currentTransition, $businessSubscription)
        ) {
            return false;
        }

        return !$this->sameCanonicalValue($current->ordinalState, $candidate->ordinalState)
            || !$this->sameCanonicalValue($current->sourceEpochs, $candidate->sourceEpochs)
            || !$this->sameCanonicalValue($current->resyncBySymbol, $candidate->resyncBySymbol)
            || !$this->sameCanonicalValue(
                $current->overlapPaginationByStream,
                $candidate->overlapPaginationByStream,
            );
    }

    /**
     * @param array<string, mixed>      $currentTransition
     * @param array<string, mixed>|null $nextTransition
     */
    private function isExactResyncActionSuccessor(
        array $currentTransition,
        ?array $nextTransition,
    ): bool {
        if (($currentTransition['kind'] ?? null) === 'timer_schedule'
            && ($currentTransition['stage'] ?? null) === 'resync_timeout'
            && ($nextTransition['kind'] ?? null) === 'rest_fetch'
            && ($nextTransition['stage'] ?? null) === 'order_book'
            && ($nextTransition['symbol'] ?? null) === ($currentTransition['symbol'] ?? null)
        ) {
            return true;
        }

        return $this->isExactBookTimerCancellationSuccessor(
            $currentTransition,
            $nextTransition,
            'sequence_gap',
        );
    }

    /**
     * @param array<string, mixed>      $currentTransition
     * @param array<string, mixed>|null $nextTransition
     */
    private function isExactBookTimerCancellationSuccessor(
        array $currentTransition,
        ?array $nextTransition,
        string $boundaryReason,
    ): bool {
        $symbol = $currentTransition['symbol'] ?? null;
        if (!\is_string($symbol)) {
            return false;
        }
        $cancel = [
            'kind' => 'timer_cancel',
            'symbol' => $symbol,
            'stream' => $symbol . '/ws/top_of_book',
            'stage' => 'cancel_resync_timer',
        ];
        if (($currentTransition['kind'] ?? null) === 'rest_fetch'
            && ($currentTransition['stage'] ?? null) === 'order_book'
            && $this->sameCanonicalValue($nextTransition, $cancel)
        ) {
            return true;
        }

        return $this->sameCanonicalValue($currentTransition, $cancel)
            && $this->sameCanonicalValue($nextTransition, [
                'kind' => 'emit_boundary',
                'symbol' => $symbol,
                'stream' => $symbol . '/control/snapshot_boundary',
                'stage' => $boundaryReason,
            ]);
    }

    /**
     * @param array<string, mixed>      $currentTransition
     * @param array<string, mixed>|null $nextTransition
     */
    private function isExactCleanupActionSuccessor(
        array $currentTransition,
        ?array $nextTransition,
        bool $includeFinalize,
    ): bool {
        $cleanup = self::CLEANUP_ACTIONS;
        if ($includeFinalize) {
            $cleanup[] = [
                'kind' => 'healthy_stop',
                'symbol' => null,
                'stream' => null,
                'stage' => 'finalize',
            ];
        }
        foreach ($cleanup as $position => $action) {
            if ($this->sameCanonicalValue($currentTransition, $action)) {
                return $this->sameCanonicalValue($nextTransition, $cleanup[$position + 1] ?? null);
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $transition */
    private function isTransportTransition(
        ?array $transition,
        string $kind,
        string $stream,
        string $stage,
    ): bool {
        return $transition === [
            'kind' => $kind,
            'symbol' => null,
            'stream' => $stream,
            'stage' => $stage,
        ];
    }

    /**
     * @param array<string, mixed>|null $currentTransition
     * @param array<string, mixed>|null $nextTransition
     */
    private function isExactInitialTransportSuccessor(
        ?array $currentTransition,
        ?array $nextTransition,
    ): bool {
        foreach (['public', 'business'] as $stream) {
            if ($this->isTransportTransition($currentTransition, 'transport_connect', $stream, 'connect')
                && $this->isTransportTransition($nextTransition, 'subscription_send', $stream, 'subscribe')
            ) {
                return true;
            }
        }

        return false;
    }

    private function initialWarmupIsComplete(OkxPaperLiveCheckpoint $checkpoint): bool
    {
        if ($checkpoint->remainingSymbols !== [] || $checkpoint->remainingBoundaries !== []) {
            return false;
        }
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $boundary = $checkpoint->streamFrontiers[$symbol . '/control/snapshot_boundary'] ?? null;
            if (!$boundary instanceof OkxPaperStreamFrontier
                || !$this->boundaryFrontierMatches(
                    $boundary,
                    $checkpoint->sourceEpochs[$symbol],
                    'initial',
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function recoveryWorkIsComplete(OkxPaperLiveCheckpoint $checkpoint): bool
    {
        return $checkpoint->remainingSymbols === []
            && $checkpoint->remainingBoundaries === []
            && array_filter(
                $checkpoint->resyncBySymbol,
                static fn (mixed $resync): bool => $resync !== null,
            ) === []
            && array_filter(
                $checkpoint->overlapPaginationByStream,
                static fn (mixed $pagination): bool => $pagination !== null,
            ) === [];
    }

    private function reconnectRecoveryIsComplete(OkxPaperLiveCheckpoint $checkpoint): bool
    {
        if ($checkpoint->reconnect['attempt'] < 1
            || !\is_string($checkpoint->reconnect['deadline_at'])
            || $checkpoint->connectionEpoch < 2
            || !$this->recoveryWorkIsComplete($checkpoint)
        ) {
            return false;
        }
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            $boundary = $checkpoint->streamFrontiers[$symbol . '/control/snapshot_boundary'] ?? null;
            if (!$boundary instanceof OkxPaperStreamFrontier
                || !$this->boundaryFrontierMatches(
                    $boundary,
                    $checkpoint->sourceEpochs[$symbol],
                    'reconnect',
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private function boundaryFrontierMatches(
        OkxPaperStreamFrontier $frontier,
        int $sourceEpoch,
        string $reason,
    ): bool {
        $parts = explode('|', $frontier->sourceIdentity);

        return \count($parts) === 3
            && $parts[0] === (string) $sourceEpoch
            && $parts[2] === $reason;
    }

    /** @param array<string, mixed>|null $pendingTransition */
    private function assertTransitionMutationsMatchAction(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $phase,
        #[\SensitiveParameter] ?array $pendingTransition,
    ): void {
        $this->assertBoundIdentity($candidate);
        $current = $this->currentCheckpoint;
        if ($current === null || $current->pendingEvent !== null) {
            throw self::invalidCheckpoint();
        }
        $this->assertPhaseGraphTransition($current, $candidate, $phase, $pendingTransition);
        $currentState = $current->toArray();
        $candidateState = $candidate->toArray();
        if (!\in_array($candidate->phase, [$current->phase, $phase], true)
            || (!$this->sameCanonicalValue($candidate->pendingTransition, $current->pendingTransition)
                && !$this->sameCanonicalValue($candidate->pendingTransition, $pendingTransition))
        ) {
            throw self::invalidCheckpoint();
        }
        foreach ([
            'schema_version',
            'dataset_id',
            'configuration_sha256',
            'failure_reason',
            'stream_frontiers',
            'last_acknowledged_event_id',
            'pending_event',
            'pending_frontier',
        ] as $field) {
            if (!$this->sameCanonicalValue($currentState[$field], $candidateState[$field])) {
                throw self::invalidCheckpoint();
            }
        }

        $kind = $pendingTransition['kind'] ?? null;
        $stage = $pendingTransition['stage'] ?? null;
        $symbol = $pendingTransition['symbol'] ?? null;
        $reconnectWrite = $phase === 'reconnecting'
            && $kind === 'timer_schedule'
            && $stage === 'reconnect_delay';
        $bookGapWrite = $phase === 'resyncing'
            && \is_string($symbol)
            && (($kind === 'rest_fetch' && $stage === 'order_book')
                || ($kind === 'timer_schedule' && $stage === 'resync_timeout'));
        $reconnectBookWrite = $phase === 'reconnecting'
            && $kind === 'rest_fetch'
            && $stage === 'order_book'
            && \is_string($symbol);
        $healthyStopWrite = $phase === 'stopping'
            && $kind === 'healthy_stop'
            && $stage === 'emit_stopped'
            && $symbol === 'BTCUSDT';
        $recoveryWrite = $kind === 'rest_fetch';
        $paginationWrite = $recoveryWrite
            && \in_array($stage, ['history_candles', 'history_trades'], true);
        $overlapCompletion = $this->isExactOverlapCompletionTransition(
            $candidate,
            $phase,
            $pendingTransition,
        );
        $stabilityWrite = $this->reconnectStabilityTransitionAction(
            $candidate,
            $phase,
            $pendingTransition,
        ) !== null;

        $allowedFields = ['phase', 'pending_transition'];
        if ($reconnectWrite) {
            array_push(
                $allowedFields,
                'remaining_symbols',
                'remaining_boundaries',
                'connection_epoch',
                'reconnect',
            );
        }
        if ($bookGapWrite) {
            array_push(
                $allowedFields,
                'ordinal_state',
                'remaining_symbols',
                'remaining_boundaries',
                'source_epochs',
                'resync_by_symbol',
            );
        }
        if ($reconnectBookWrite) {
            array_push($allowedFields, 'source_epochs', 'resync_by_symbol');
        }
        if ($healthyStopWrite) {
            array_push($allowedFields, 'remaining_symbols', 'healthy_stop');
        }
        if ($recoveryWrite) {
            $allowedFields[] = 'resync_by_symbol';
        }
        if ($paginationWrite) {
            $allowedFields[] = 'overlap_pagination_by_stream';
        }
        if ($overlapCompletion) {
            array_push($allowedFields, 'resync_by_symbol', 'overlap_pagination_by_stream');
        }
        if ($stabilityWrite) {
            $allowedFields[] = 'reconnect';
        }
        $allowedFields = array_values(array_unique($allowedFields));
        foreach ([
            'ordinal_state',
            'remaining_symbols',
            'remaining_boundaries',
            'connection_epoch',
            'source_epochs',
            'healthy_stop',
            'reconnect',
            'resync_by_symbol',
            'overlap_pagination_by_stream',
        ] as $field) {
            if (!\in_array($field, $allowedFields, true)
                && !$this->sameCanonicalValue($currentState[$field], $candidateState[$field])
            ) {
                throw self::invalidCheckpoint();
            }
        }

        if ($reconnectWrite) {
            $this->assertExactReconnectTransitionMutation($current, $candidate);
        }
        if ($bookGapWrite) {
            $this->assertExactBookGapTransitionMutation($current, $candidate, $symbol);
        }
        if ($reconnectBookWrite) {
            $this->assertExactReconnectBookTransitionMutation($current, $candidate, $symbol);
        }
        if ($healthyStopWrite) {
            $this->assertExactHealthyStopTransitionMutation($current, $candidate);
        }
        if ($recoveryWrite && !$bookGapWrite && !$overlapCompletion && $stage !== 'order_book') {
            if (!\is_string($symbol) || !\is_string($pendingTransition['stream'] ?? null)) {
                throw self::invalidCheckpoint();
            }
            $this->assertExactFrontierRecoveryTransitionMutation(
                $current,
                $candidate,
                $symbol,
                $pendingTransition['stream'],
            );
        }
        if ($paginationWrite && !$overlapCompletion) {
            if (!\is_string($pendingTransition['stream'] ?? null)) {
                throw self::invalidCheckpoint();
            }
            $this->assertOnlyTransitionPaginationChanges(
                $current,
                $candidate,
                $pendingTransition['stream'],
            );
        }
    }

    private function assertExactReconnectBookTransitionMutation(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $symbol,
    ): void {
        foreach (['BTCUSDT', 'ETHUSDT'] as $candidateSymbol) {
            if ($candidateSymbol !== $symbol
                && (!$this->sameCanonicalValue(
                    $current->resyncBySymbol[$candidateSymbol],
                    $candidate->resyncBySymbol[$candidateSymbol],
                ) || $current->sourceEpochs[$candidateSymbol] !== $candidate->sourceEpochs[$candidateSymbol])
            ) {
                throw self::invalidCheckpoint();
            }
        }
        $currentResync = $current->resyncBySymbol[$symbol] ?? null;
        $candidateResync = $candidate->resyncBySymbol[$symbol] ?? null;
        if ($currentResync !== null) {
            if ($candidate->sourceEpochs[$symbol] !== $current->sourceEpochs[$symbol]) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        $frontier = $current->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null;
        if (!$frontier instanceof OkxPaperStreamFrontier
            || !\is_array($candidateResync)
            || $candidateResync['attempt'] !== 1
            || $candidateResync['policy'] !== 'book_seq_overlap_v1'
            || !$this->sameCanonicalValue($candidateResync['frontier'], $frontier)
            || $candidateResync['source_sequence'] !== $frontier->sourceIdentity
            || $candidate->sourceEpochs[$symbol] !== $current->sourceEpochs[$symbol] + 1
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertExactFrontierRecoveryTransitionMutation(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $symbol,
        string $stream,
    ): void {
        foreach (['BTCUSDT', 'ETHUSDT'] as $candidateSymbol) {
            if ($candidateSymbol !== $symbol
                && !$this->sameCanonicalValue(
                    $current->resyncBySymbol[$candidateSymbol],
                    $candidate->resyncBySymbol[$candidateSymbol],
                )
            ) {
                throw self::invalidCheckpoint();
            }
        }
        $currentResync = $current->resyncBySymbol[$symbol] ?? null;
        $candidateResync = $candidate->resyncBySymbol[$symbol] ?? null;
        if ($this->sameCanonicalValue($currentResync, $candidateResync)) {
            return;
        }
        $frontier = $current->streamFrontiers[$stream] ?? null;
        if ($currentResync === null
            && (!\is_array($candidateResync)
                || $candidateResync['attempt'] !== 1
                || $candidateResync['policy'] !== 'frontier_overlap_v1'
                || $candidateResync['source_sequence'] !== null
                || !$frontier instanceof OkxPaperStreamFrontier
                || !$this->sameCanonicalValue($candidateResync['frontier'], $frontier))
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertOnlyTransitionPaginationChanges(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $stream,
    ): void {
        foreach ($current->overlapPaginationByStream as $candidateStream => $pagination) {
            if ($candidateStream !== $stream
                && !$this->sameCanonicalValue(
                    $pagination,
                    $candidate->overlapPaginationByStream[$candidateStream],
                )
            ) {
                throw self::invalidCheckpoint();
            }
        }
    }

    /** @param array<string, mixed>|null $pendingTransition */
    private function reconnectStabilityTransitionAction(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $phase,
        #[\SensitiveParameter] ?array $pendingTransition,
    ): ?string {
        $current = $this->currentCheckpoint;
        if ($current === null
            || $phase !== 'streaming'
            || $pendingTransition !== null
            || $candidate->pendingTransition !== null
            || $current->reconnect['attempt'] === 0
            || !$this->sameCanonicalValue($candidate->reconnect, $current->reconnect)
        ) {
            return null;
        }
        $start = $current->reconnect['deadline_at'] !== null
            && $current->reconnect['stable_since'] === null
            && $current->reconnect['accepted_events'] === 0;
        if ($start) {
            return 'start';
        }
        $stableThresholdReached = \is_string($current->reconnect['stable_since'])
            && $this->clock->now() >= (new \DateTimeImmutable(
                $current->reconnect['stable_since'],
            ))->modify(sprintf(
                '+%d seconds',
                (int) OkxPaperLivePolicy::RECONNECT_STABLE_SECONDS,
            ));
        $reset = $current->reconnect['deadline_at'] === null
            && \is_string($current->reconnect['stable_since'])
            && $current->reconnect['accepted_events'] === OkxPaperLivePolicy::RECONNECT_STABLE_ACCEPTED_EVENTS
            && $stableThresholdReached;

        return $reset ? 'reset' : null;
    }

    private function nowUtc(): string
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    /** @param array<string, mixed>|null $pendingTransition */
    private function isExactOverlapCompletionTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $phase,
        #[\SensitiveParameter] ?array $pendingTransition,
    ): bool {
        $current = $this->currentCheckpoint;
        $currentTransition = $current?->pendingTransition;
        if ($current === null
            || ($currentTransition['kind'] ?? null) !== 'rest_fetch'
            || !\in_array($currentTransition['stage'] ?? null, [
                'current_candles',
                'recent_trades',
                'history_candles',
                'history_trades',
            ], true)
            || !\is_string($currentTransition['symbol'] ?? null)
            || !\is_string($currentTransition['stream'] ?? null)
            || $this->sameCanonicalValue($currentTransition, $pendingTransition)
            || !\in_array($phase, ['warming', 'reconnecting', 'streaming'], true)
        ) {
            return false;
        }
        if ($phase === 'warming'
            && !$this->isExactNextWarmingTransition($currentTransition, $pendingTransition)
        ) {
            return false;
        }
        $symbol = $currentTransition['symbol'];
        $stream = $currentTransition['stream'];
        $resync = $current->resyncBySymbol[$symbol] ?? null;
        $streamFrontier = $current->streamFrontiers[$stream] ?? null;
        if (!\is_array($resync)
            || $resync['policy'] !== 'frontier_overlap_v1'
            || !$streamFrontier instanceof OkxPaperStreamFrontier
            || !$this->sameCanonicalValue($resync['frontier'], $streamFrontier)
        ) {
            return false;
        }
        foreach (['BTCUSDT', 'ETHUSDT'] as $otherSymbol) {
            if ($otherSymbol !== $symbol
                && !$this->sameCanonicalValue(
                    $current->resyncBySymbol[$otherSymbol],
                    $candidate->resyncBySymbol[$otherSymbol],
                )
            ) {
                return false;
            }
        }
        foreach ($current->overlapPaginationByStream as $candidateStream => $pagination) {
            $nextPagination = $candidate->overlapPaginationByStream[$candidateStream];
            if ($candidateStream === $stream && $pagination !== null) {
                if ($nextPagination !== null
                    || !$this->sameCanonicalValue($pagination['target_frontier'], $resync['frontier'])
                    || $pagination['deadline_at'] !== $resync['deadline_at']
                ) {
                    return false;
                }

                continue;
            }
            if (!$this->sameCanonicalValue($pagination, $nextPagination)) {
                return false;
            }
        }

        $symbolPaginationRemains = false;
        foreach ($candidate->overlapPaginationByStream as $candidateStream => $pagination) {
            if ($pagination !== null && str_starts_with($candidateStream, $symbol . '/')) {
                $symbolPaginationRemains = true;
                break;
            }
        }
        $expectedResync = $symbolPaginationRemains ? $resync : null;
        if (!$this->sameCanonicalValue(
            $candidate->resyncBySymbol[$symbol] ?? null,
            $expectedResync,
        )) {
            return false;
        }
        if ($phase === 'reconnecting'
            && !$this->isExactNextReconnectOverlapTransition($candidate, $pendingTransition)
        ) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed>|null $nextTransition */
    private function isExactNextReconnectOverlapTransition(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        #[\SensitiveParameter] ?array $nextTransition,
    ): bool {
        $completedStream = $this->currentCheckpoint?->pendingTransition['stream'] ?? null;

        return $this->sameCanonicalValue(
            $nextTransition,
            \is_string($completedStream)
                ? $this->nextReconnectRecoveryTransition($candidate, $completedStream)
                : null,
        );
    }

    /**
     * @param array<string, mixed>      $currentTransition
     * @param array<string, mixed>|null $nextTransition
     */
    private function isExactNextWarmingTransition(array $currentTransition, ?array $nextTransition): bool
    {
        if ($nextTransition === null
            || !\is_string($currentTransition['symbol'] ?? null)
            || ($nextTransition['symbol'] ?? null) !== $currentTransition['symbol']
            || ($nextTransition['kind'] ?? null) !== 'rest_fetch'
        ) {
            return false;
        }
        $symbol = $currentTransition['symbol'];
        $currentPosition = null;
        foreach (self::WARMING_REST_WORK as $position => $work) {
            if (($currentTransition['stream'] ?? null) === $symbol . '/' . $work['suffix']) {
                $currentPosition = $position;
                break;
            }
        }
        if (!\is_int($currentPosition)) {
            return false;
        }
        $nextWork = self::WARMING_REST_WORK[$currentPosition + 1] ?? null;

        return \is_array($nextWork)
            && ($nextTransition['stream'] ?? null) === $symbol . '/' . $nextWork['suffix']
            && ($nextTransition['stage'] ?? null) === $nextWork['stages'][0];
    }

    private function assertExactReconnectTransitionMutation(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
    ): void {
        $reconnectChanged = !$this->sameCanonicalValue($current->reconnect, $candidate->reconnect);
        if ($reconnectChanged) {
            $expectedAttempt = $current->reconnect['attempt'] + 1;
            if ($candidate->reconnect['attempt'] !== $expectedAttempt
                || $candidate->reconnect['deadline_at'] === null
                || $candidate->reconnect['stable_since'] !== null
                || $candidate->reconnect['accepted_events'] !== 0
                || $candidate->remainingSymbols !== ['BTCUSDT', 'ETHUSDT']
                || $candidate->remainingBoundaries !== [
                    ['symbol' => 'BTCUSDT', 'reason' => 'reconnect'],
                    ['symbol' => 'ETHUSDT', 'reason' => 'reconnect'],
                ]
                || $candidate->connectionEpoch !== $current->connectionEpoch + 1
            ) {
                throw self::invalidCheckpoint();
            }
        }

        $listsChanged = !$this->sameCanonicalValue($current->remainingSymbols, $candidate->remainingSymbols)
            || !$this->sameCanonicalValue($current->remainingBoundaries, $candidate->remainingBoundaries);
        if (!$reconnectChanged
            && ($listsChanged || $candidate->connectionEpoch !== $current->connectionEpoch)
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertExactBookGapTransitionMutation(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        string $symbol,
    ): void {
        foreach (['BTCUSDT', 'ETHUSDT'] as $candidateSymbol) {
            if ($candidateSymbol !== $symbol
                && !$this->sameCanonicalValue(
                    $current->resyncBySymbol[$candidateSymbol],
                    $candidate->resyncBySymbol[$candidateSymbol],
                )
            ) {
                throw self::invalidCheckpoint();
            }
        }
        $currentResync = $current->resyncBySymbol[$symbol] ?? null;
        $candidateResync = $candidate->resyncBySymbol[$symbol] ?? null;
        if ($currentResync !== null) {
            if (!$this->sameCanonicalValue($current->ordinalState, $candidate->ordinalState)
                || !$this->sameCanonicalValue($current->remainingSymbols, $candidate->remainingSymbols)
                || !$this->sameCanonicalValue($current->remainingBoundaries, $candidate->remainingBoundaries)
                || !$this->sameCanonicalValue($current->sourceEpochs, $candidate->sourceEpochs)
            ) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        $frontier = $current->streamFrontiers[$symbol . '/ws/top_of_book'] ?? null;
        if (!$frontier instanceof OkxPaperStreamFrontier
            || !\is_array($candidateResync)
            || $candidateResync['attempt'] !== 1
            || $candidateResync['policy'] !== 'book_seq_overlap_v1'
            || !$this->sameCanonicalValue($candidateResync['frontier'], $frontier)
            || $candidateResync['source_sequence'] !== $frontier->sourceIdentity
            || $current->remainingSymbols !== []
            || $current->remainingBoundaries !== []
            || $candidate->remainingSymbols !== [$symbol]
            || $candidate->remainingBoundaries !== [[
                'symbol' => $symbol,
                'reason' => 'sequence_gap',
            ]]
        ) {
            throw self::invalidCheckpoint();
        }
        foreach (['BTCUSDT', 'ETHUSDT'] as $candidateSymbol) {
            $expectedEpoch = $current->sourceEpochs[$candidateSymbol]
                + ($candidateSymbol === $symbol ? 1 : 0);
            if ($candidate->sourceEpochs[$candidateSymbol] !== $expectedEpoch) {
                throw self::invalidCheckpoint();
            }
        }
        try {
            $ordinals = OkxPaperSourceOrdinal::restore($current->ordinalState);
            $ordinals->reserveGap('okx/' . $symbol . '/top_of_book');
            if (!$this->sameCanonicalValue($ordinals->snapshot(), $candidate->ordinalState)) {
                throw self::invalidCheckpoint();
            }
        } catch (OkxPaperLiveIntegrityException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }
    }

    private function assertExactHealthyStopTransitionMutation(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $current,
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
    ): void {
        $alreadyRequested = $current->healthyStop['requested'];
        if ($alreadyRequested) {
            if (!$this->sameCanonicalValue($current->healthyStop, $candidate->healthyStop)
                || !$this->sameCanonicalValue($current->remainingSymbols, $candidate->remainingSymbols)
            ) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($current->remainingSymbols !== []
            || $current->remainingBoundaries !== []
            || $candidate->remainingSymbols !== ['BTCUSDT', 'ETHUSDT']
            || $candidate->healthyStop !== [
                'requested' => true,
                'remaining_symbols' => ['BTCUSDT', 'ETHUSDT'],
            ]
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertCompleteIsTerminal(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        bool $allowFinalizedTransition = false,
    ): void {
        if ($this->currentCheckpoint !== null
            && $this->currentCheckpoint->phase !== 'complete'
            && $candidate->phase === 'complete'
        ) {
            $finalized = $allowFinalizedTransition
                && $this->currentCheckpoint->phase === 'stopping'
                && $this->currentCheckpoint->pendingTransition === [
                    'kind' => 'healthy_stop',
                    'symbol' => null,
                    'stream' => null,
                    'stage' => 'finalize',
                ]
                && $candidate->pendingTransition === null;
            if (!$finalized) {
                throw self::invalidCheckpoint();
            }
        }
        if ($this->currentCheckpoint !== null
            && $this->currentCheckpoint->phase === 'complete'
            && !$this->sameCanonicalValue(
                $this->currentCheckpoint->toArray(),
                $candidate->toArray(),
            )
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertDurableHealthyStopCleanupOrder(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
    ): void {
        $current = $this->currentCheckpoint;
        if ($current === null
            || $candidate->phase !== 'stopping'
            || !$candidate->healthyStop['requested']
        ) {
            return;
        }
        if ($candidate->healthyStop['remaining_symbols'] !== []) {
            $transition = $candidate->pendingTransition;
            if (($transition['kind'] ?? null) !== 'healthy_stop'
                || ($transition['stage'] ?? null) !== 'emit_stopped'
            ) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($candidate->remainingSymbols !== [] || $candidate->remainingBoundaries !== []) {
            throw self::invalidCheckpoint();
        }

        $cleanup = [...self::CLEANUP_ACTIONS, [
            'kind' => 'healthy_stop',
            'symbol' => null,
            'stream' => null,
            'stage' => 'finalize',
        ]];
        $currentTransition = $current->pendingTransition;
        $candidateTransition = $candidate->pendingTransition;
        if ($this->sameCanonicalValue($currentTransition, $candidateTransition)) {
            return;
        }
        $currentPosition = -1;
        foreach ($cleanup as $position => $transition) {
            if ($this->sameCanonicalValue($currentTransition, $transition)) {
                $currentPosition = $position;
                break;
            }
        }
        $expected = $cleanup[$currentPosition + 1] ?? null;
        if (!$this->sameCanonicalValue($candidateTransition, $expected)) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertReconnectProgresses(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        bool $allowCompletion = false,
    ): void {
        if ($this->currentCheckpoint === null) {
            throw self::invalidCheckpoint();
        }
        $current = $this->currentCheckpoint->reconnect;
        $next = $candidate->reconnect;
        $currentAttempt = $current['attempt'];
        $nextAttempt = $next['attempt'];
        if ($nextAttempt < $currentAttempt) {
            $stableReset = $allowCompletion
                && $nextAttempt === 0
                && $current['stable_since'] !== null
                && $current['accepted_events'] >= OkxPaperLivePolicy::RECONNECT_STABLE_ACCEPTED_EVENTS - 1;
            if (!$stableReset) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($nextAttempt > $currentAttempt + 1) {
            throw self::invalidCheckpoint();
        }
        if ($nextAttempt === $currentAttempt + 1) {
            if ($next['deadline_at'] === null
                || $next['stable_since'] !== null
                || $next['accepted_events'] !== 0
            ) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($current['stable_since'] !== null
            && ($next['stable_since'] !== $current['stable_since']
                || $next['accepted_events'] < $current['accepted_events'])
        ) {
            throw self::invalidCheckpoint();
        }
        if ($next['deadline_at'] !== $current['deadline_at']) {
            $acknowledgedStability = $allowCompletion
                && $current['deadline_at'] !== null
                && $next['deadline_at'] === null
                && $next['stable_since'] !== null;
            if (!$acknowledgedStability) {
                throw self::invalidCheckpoint();
            }
        }
    }

    private function assertRecoveryStateProgresses(
        #[\SensitiveParameter] OkxPaperLiveCheckpoint $candidate,
        bool $allowCompletion = false,
    ): void {
        if ($this->currentCheckpoint === null) {
            throw self::invalidCheckpoint();
        }
        if ($candidate->connectionEpoch < $this->currentCheckpoint->connectionEpoch) {
            throw self::invalidCheckpoint();
        }
        foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
            if ($candidate->sourceEpochs[$symbol] < $this->currentCheckpoint->sourceEpochs[$symbol]) {
                throw self::invalidCheckpoint();
            }
            $this->assertResyncProgresses(
                $this->currentCheckpoint->resyncBySymbol[$symbol],
                $candidate->resyncBySymbol[$symbol],
                $allowCompletion,
            );
        }
        foreach ($candidate->overlapPaginationByStream as $stream => $next) {
            $this->assertPaginationProgresses(
                $this->currentCheckpoint->overlapPaginationByStream[$stream],
                $next,
                $allowCompletion,
            );
        }
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>|null $next
     */
    private function assertResyncProgresses(?array $current, ?array $next, bool $allowCompletion): void
    {
        if ($current === null) {
            if ($next !== null && $next['attempt'] !== 1) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($next === null) {
            if (!$allowCompletion) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($next['attempt'] < $current['attempt'] || $next['attempt'] > $current['attempt'] + 1) {
            throw self::invalidCheckpoint();
        }
        foreach (['frontier', 'source_sequence', 'policy'] as $field) {
            if (!$this->sameCanonicalValue($current[$field], $next[$field])) {
                throw self::invalidCheckpoint();
            }
        }
        if ($next['attempt'] === $current['attempt'] && $next['deadline_at'] !== $current['deadline_at']) {
            throw self::invalidCheckpoint();
        }
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed>|null $next
     */
    private function assertPaginationProgresses(?array $current, ?array $next, bool $allowCompletion): void
    {
        if ($current === null) {
            if ($next !== null && $next['pages_consumed'] !== 0) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($next === null) {
            if (!$allowCompletion) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        foreach (['endpoint', 'target_frontier', 'deadline_at'] as $field) {
            if (!$this->sameCanonicalValue($current[$field], $next[$field])) {
                throw self::invalidCheckpoint();
            }
        }
        $consumed = $next['pages_consumed'] - $current['pages_consumed'];
        if ($consumed < 0 || $consumed > 1) {
            throw self::invalidCheckpoint();
        }
        if ($consumed === 0) {
            if ($next['pagination_type'] !== $current['pagination_type']
                || $next['next_cursor'] !== $current['next_cursor']
            ) {
                throw self::invalidCheckpoint();
            }

            return;
        }
        if ($current['pagination_type'] === 2 && $next['pagination_type'] !== 1) {
            throw self::invalidCheckpoint();
        }
        if ($current['pagination_type'] !== 2
            && $next['pagination_type'] !== $current['pagination_type']
        ) {
            throw self::invalidCheckpoint();
        }
        if (\in_array($current['pagination_type'], [null, 1], true)) {
            if (!\is_string($current['next_cursor'])
                || !\is_string($next['next_cursor'])
                || BigInteger::of($next['next_cursor'])->compareTo(
                    BigInteger::of($current['next_cursor']),
                ) >= 0
            ) {
                throw self::invalidCheckpoint();
            }
        }
    }

    private function sameCanonicalValue(#[\SensitiveParameter] mixed $left, #[\SensitiveParameter] mixed $right): bool
    {
        try {
            return hash_equals(
                CanonicalJson::encode($this->canonicalizableValue($left)),
                CanonicalJson::encode($this->canonicalizableValue($right)),
            );
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }
    }

    private function canonicalizableValue(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($value instanceof OkxPaperStreamFrontier) {
            return $value->toArray();
        }
        if (!\is_array($value)) {
            return $value;
        }

        return array_map(
            fn (mixed $item): mixed => $this->canonicalizableValue($item),
            $value,
        );
    }

    private function stateHash(#[\SensitiveParameter] OkxPaperLiveCheckpoint $checkpoint): string
    {
        try {
            return hash('sha256', CanonicalJson::encode($checkpoint->toArray()) . "\n");
        } catch (\Throwable $exception) {
            throw self::invalidCheckpoint($exception);
        }
    }

    private function assertDurableStateUnchanged(): void
    {
        if ($this->currentStateHash === null && $this->currentFileIdentity === null) {
            return;
        }
        if ($this->currentStateHash === null || $this->currentFileIdentity === null) {
            throw self::invalidCheckpoint();
        }
        $identity = $this->checkpointIdentity();
        if (!$this->sameFile($this->currentFileIdentity, $identity)
            || !hash_equals($this->currentStateHash, hash('sha256', $this->readCheckpoint()))
        ) {
            throw self::invalidCheckpoint();
        }
    }

    /** @return array{dev: int, ino: int} */
    private function checkpointIdentity(): array
    {
        $statistics = $this->pathStatistics($this->checkpointPath);
        if ($statistics === false
            || !$this->isPrivateRegularFile($statistics)
            || !isset($statistics['dev'], $statistics['ino'])
            || !\is_int($statistics['dev'])
            || !\is_int($statistics['ino'])
        ) {
            throw self::invalidCheckpoint();
        }

        return ['dev' => $statistics['dev'], 'ino' => $statistics['ino']];
    }

    private function assertFrontierAdvances(
        string $stream,
        OkxPaperStreamFrontier $current,
        OkxPaperStreamFrontier $next,
    ): void {
        if (hash_equals($current->naturalIdentity, $next->naturalIdentity)
            && !hash_equals($current->canonicalDigest, $next->canonicalDigest)
        ) {
            throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
        }
        if ($this->compareSourceIdentities($stream, $current->sourceIdentity, $next->sourceIdentity) >= 0) {
            throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
        }
    }

    private function compareSourceIdentities(string $stream, string $left, string $right): int
    {
        $channel = substr($stream, strrpos($stream, '/') + 1);
        try {
            if ($channel === 'public_trade' || $channel === 'top_of_book') {
                return BigInteger::of($left)->compareTo(BigInteger::of($right));
            }
            if (str_starts_with($channel, 'candle_')) {
                [$leftBar, $leftTimestamp] = $this->splitIdentity($left, 2);
                [$rightBar, $rightTimestamp] = $this->splitIdentity($right, 2);
                if ($leftBar !== $rightBar) {
                    throw new \InvalidArgumentException();
                }

                return BigInteger::of($leftTimestamp)->compareTo(BigInteger::of($rightTimestamp));
            }
            if ($channel === 'connection_state') {
                [$leftEpoch, $leftState] = $this->splitIdentity($left, 2);
                [$rightEpoch, $rightState] = $this->splitIdentity($right, 2);
                $epochOrder = BigInteger::of($leftEpoch)->compareTo(BigInteger::of($rightEpoch));
                if ($epochOrder !== 0) {
                    return $epochOrder;
                }
                $states = ['connected', 'subscribed', 'reconnecting', 'stopped'];

                return $this->finiteOrder($leftState, $rightState, $states);
            }
            if ($channel === 'snapshot_boundary') {
                [$leftEpoch, $leftSequence, $leftReason] = $this->splitIdentity($left, 3);
                [$rightEpoch, $rightSequence, $rightReason] = $this->splitIdentity($right, 3);
                $epochOrder = BigInteger::of($leftEpoch)->compareTo(BigInteger::of($rightEpoch));
                if ($epochOrder !== 0) {
                    return $epochOrder;
                }
                $sequenceOrder = BigInteger::of($leftSequence)->compareTo(BigInteger::of($rightSequence));
                if ($sequenceOrder !== 0) {
                    return $sequenceOrder;
                }

                return $this->finiteOrder(
                    $leftReason,
                    $rightReason,
                    ['initial', 'reconnect', 'sequence_gap'],
                );
            }
        } catch (\Throwable $exception) {
            throw new OkxPaperLiveIntegrityException('market_event_identity_conflict', 0, $exception);
        }

        throw new OkxPaperLiveIntegrityException('market_event_identity_conflict');
    }

    /** @return list<string> */
    private function splitIdentity(string $identity, int $count): array
    {
        $parts = explode('|', $identity);
        if (\count($parts) !== $count) {
            throw new \InvalidArgumentException();
        }

        return $parts;
    }

    /** @param list<string> $values */
    private function finiteOrder(string $left, string $right, array $values): int
    {
        $leftPosition = array_search($left, $values, true);
        $rightPosition = array_search($right, $values, true);
        if (!\is_int($leftPosition) || !\is_int($rightPosition)) {
            throw new \InvalidArgumentException();
        }

        return $leftPosition <=> $rightPosition;
    }

    /**
     * @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $parentPin
     * @return array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool}
     */
    private function ensureManagedDirectory(array $parentPin, string $name): array
    {
        $this->assertPinnedDirectory($parentPin);
        $path = $parentPin['path'] . '/' . $name;
        $statistics = $this->pathStatistics($path);
        $created = false;
        if ($statistics === false) {
            $this->assertPinnedDirectory($parentPin);
            if (!$this->filesystem->createDirectory($path, 0700)) {
                $statistics = $this->pathStatistics($path);
                if ($statistics === false) {
                    throw self::invalidCheckpoint();
                }
            } else {
                $created = true;
                $statistics = $this->pathStatistics($path);
            }
        }
        if ($statistics === false || $this->isSymlink($statistics) || !$this->isPrivateDirectory($statistics)) {
            throw self::invalidCheckpoint();
        }
        $pin = $this->openPinnedDirectory($path, requirePrivate: true, expected: $statistics);
        try {
            $this->assertPinnedDirectory($parentPin);
            $this->assertPinnedDirectory($pin);
            if ($created && !$this->filesystem->sync($parentPin['handle'], 'okx_paper_live_directory_parent_sync')) {
                throw self::invalidCheckpoint();
            }
            $this->assertPinnedDirectory($parentPin);
            $this->assertPinnedDirectory($pin);

            return $pin;
        } catch (\Throwable $failure) {
            fclose($pin['handle']);

            throw $failure;
        }
    }

    /**
     * @param array<string, mixed>|null $expected
     * @return array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool}
     */
    private function openPinnedDirectory(string $path, bool $requirePrivate, ?array $expected = null): array
    {
        $before = $this->pathStatistics($path);
        if ($before === false
            || $this->isSymlink($before)
            || !$this->isDirectory($before)
            || ($requirePrivate && !$this->isPrivateDirectory($before))
            || ($expected !== null && !$this->sameFile($expected, $before))
        ) {
            throw self::invalidCheckpoint();
        }
        $handle = $this->filesystem->openDirectory($path, 'okx_paper_live_directory_open');
        if ($handle === false) {
            throw self::invalidCheckpoint();
        }
        try {
            $opened = $this->filesystem->stat($handle, 'okx_paper_live_directory_validation');
            if ($opened === false
                || !$this->isDirectory($opened)
                || ($requirePrivate && !$this->isPrivateDirectory($opened))
                || !$this->sameFile($before, $opened)
                || !isset($opened['dev'], $opened['ino'])
                || !\is_int($opened['dev'])
                || !\is_int($opened['ino'])
            ) {
                throw self::invalidCheckpoint();
            }
            $pin = [
                'handle' => $handle,
                'identity' => ['dev' => $opened['dev'], 'ino' => $opened['ino']],
                'path' => $path,
                'private' => $requirePrivate,
            ];
            $this->assertPinnedDirectory($pin);

            return $pin;
        } catch (\Throwable $failure) {
            fclose($handle);

            throw $failure;
        }
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string, private: bool} $pin */
    private function assertPinnedDirectory(array $pin): void
    {
        $opened = $this->filesystem->stat($pin['handle'], 'okx_paper_live_directory_validation');
        $current = $this->pathStatistics($pin['path']);
        if ($opened === false
            || $current === false
            || $this->isSymlink($current)
            || !$this->isDirectory($opened)
            || !$this->isDirectory($current)
            || ($pin['private'] && (!$this->isPrivateDirectory($opened) || !$this->isPrivateDirectory($current)))
            || !$this->sameFile($pin['identity'], $opened)
            || !$this->sameFile($pin['identity'], $current)
        ) {
            throw self::invalidCheckpoint();
        }
    }

    private function assertManagedDirectories(): void
    {
        $this->assertPinnedDirectory($this->datasetPin);
        $this->assertPinnedDirectory($this->checkpointsPin);
        $this->assertPinnedDirectory($this->directoryPin);
        if (isset($this->writerLock)) {
            $this->assertWriterLock();
        }
    }

    /** @return array{handle: resource, identity: array{dev: int, ino: int}, path: string} */
    private function acquireWriterLock(): array
    {
        $path = $this->directoryPin['path'] . '/' . self::WRITER_LOCK_FILENAME;
        $this->assertManagedDirectories();
        $statistics = $this->pathStatistics($path);
        if ($statistics !== false && !$this->isPrivateRegularFile($statistics)) {
            throw self::invalidCheckpoint();
        }
        $created = $statistics === false;
        $handle = $created
            ? $this->filesystem->createPrivateFile($path, 'okx_paper_live_lock_create')
            : @fopen($path, 'r+b');
        if ($handle === false && $created) {
            $statistics = $this->pathStatistics($path);
            if ($statistics !== false && $this->isPrivateRegularFile($statistics)) {
                $created = false;
                $handle = @fopen($path, 'r+b');
            }
        }
        if ($handle === false) {
            throw self::invalidCheckpoint();
        }

        $locked = false;
        try {
            $identity = $this->assertHandleMatchesPath($handle, $path);
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_lock_unavailable');
            }
            $locked = true;
            $lock = ['handle' => $handle, 'identity' => $identity, 'path' => $path];
            $this->assertWriterLockPin($lock);
            if ($created
                && !$this->filesystem->sync($this->directoryPin['handle'], 'okx_paper_live_lock_directory_sync')
            ) {
                throw self::invalidCheckpoint();
            }
            $this->assertWriterLockPin($lock);

            return $lock;
        } catch (\Throwable $failure) {
            if ($locked) {
                @flock($handle, \LOCK_UN);
            }
            fclose($handle);

            throw $failure;
        }
    }

    private function assertWriterLock(): void
    {
        $this->assertPinnedDirectory($this->directoryPin);
        $this->assertWriterLockPin($this->writerLock);
    }

    /** @param array{handle: resource, identity: array{dev: int, ino: int}, path: string} $lock */
    private function assertWriterLockPin(array $lock): void
    {
        $this->assertHandleMatchesPath($lock['handle'], $lock['path'], $lock['identity']);
    }

    private function readCheckpoint(): string
    {
        $this->assertManagedDirectories();
        $before = $this->pathStatistics($this->checkpointPath);
        if ($before === false
            || $this->isSymlink($before)
            || !$this->isPrivateRegularFile($before)
            || !isset($before['size'])
            || !\is_int($before['size'])
            || $before['size'] < 2
            || $before['size'] > self::MAX_CHECKPOINT_BYTES
        ) {
            throw self::invalidCheckpoint();
        }
        $handle = @fopen($this->checkpointPath, 'rb');
        if ($handle === false) {
            throw self::invalidCheckpoint();
        }
        try {
            $opened = $this->filesystem->stat($handle, 'okx_paper_live_checkpoint_load');
            if ($opened === false
                || !$this->isPrivateRegularFile($opened)
                || !$this->sameSnapshot($before, $opened)
            ) {
                throw self::invalidCheckpoint();
            }
            $contents = '';
            while (\strlen($contents) < $opened['size']) {
                $chunk = $this->filesystem->read(
                    $handle,
                    min(8192, $opened['size'] - \strlen($contents)),
                    'okx_paper_live_checkpoint_load',
                );
                if ($chunk === false || $chunk === '') {
                    throw self::invalidCheckpoint();
                }
                $contents .= $chunk;
            }
            $extra = $this->filesystem->read($handle, 1, 'okx_paper_live_checkpoint_load');
            $afterHandle = $this->filesystem->stat($handle, 'okx_paper_live_checkpoint_load');
            $afterPath = $this->pathStatistics($this->checkpointPath);
            $this->assertManagedDirectories();
            if ($extra === false
                || $extra !== ''
                || !$this->sameSnapshot($opened, $afterHandle)
                || !$this->sameSnapshot($opened, $afterPath)
            ) {
                throw self::invalidCheckpoint();
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    private function atomicWrite(#[\SensitiveParameter] string $contents): void
    {
        $this->assertManagedDirectories();
        $this->assertDestinationIsSafe();
        try {
            $temporaryPath = $this->directoryPin['path'] . '/.okx-live-' . bin2hex(random_bytes(16));
        } catch (\Throwable $exception) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_write_failed', 0, $exception);
        }
        $handle = $this->filesystem->createPrivateFile($temporaryPath, 'okx_paper_live_checkpoint_create');
        if ($handle === false) {
            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_write_failed');
        }

        $renamed = false;
        try {
            $temporaryIdentity = $this->assertHandleMatchesPath($handle, $temporaryPath);
            $this->assertManagedDirectories();
            $this->assertHandleMatchesPath($handle, $temporaryPath, $temporaryIdentity);
            $this->writeAll($handle, $contents);
            if (!$this->filesystem->flush($handle, 'okx_paper_live_checkpoint_flush')
                || !$this->filesystem->sync($handle, 'okx_paper_live_checkpoint_sync')
            ) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_write_failed');
            }
            $this->assertHandleMatchesPath($handle, $temporaryPath, $temporaryIdentity);
            $this->assertManagedDirectories();
            $this->assertDestinationIsSafe();
            $this->assertHandleMatchesPath($handle, $temporaryPath, $temporaryIdentity);
            if (!$this->filesystem->move(
                $temporaryPath,
                $this->checkpointPath,
                'okx_paper_live_checkpoint_publish',
            )) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_write_failed');
            }
            $renamed = true;
            $this->assertManagedDirectories();
            $this->assertHandleMatchesPath($handle, $this->checkpointPath, $temporaryIdentity);
            if (!$this->filesystem->sync($this->directoryPin['handle'], 'okx_paper_live_checkpoint_directory_sync')) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_write_failed');
            }
            $this->assertManagedDirectories();
            $this->assertHandleMatchesPath($handle, $this->checkpointPath, $temporaryIdentity);
        } catch (\Throwable $failure) {
            if (!$renamed) {
                $this->removeTemporaryPath($temporaryPath);
            }
            if ($failure instanceof OkxPaperLiveIntegrityException) {
                throw $failure;
            }

            throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_write_failed', 0, $failure);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, #[\SensitiveParameter] string $contents): void
    {
        $offset = 0;
        while ($offset < \strlen($contents)) {
            $written = $this->filesystem->write(
                $handle,
                substr($contents, $offset),
                'okx_paper_live_checkpoint_write',
            );
            if ($written === false || $written < 1) {
                throw new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_write_failed');
            }
            $offset += $written;
        }
    }

    private function assertDestinationIsSafe(): void
    {
        $statistics = $this->pathStatistics($this->checkpointPath);
        if ($statistics !== false && ($this->isSymlink($statistics) || !$this->isPrivateRegularFile($statistics))) {
            throw self::invalidCheckpoint();
        }
    }

    /**
     * @param resource $handle
     * @param array{dev: int, ino: int}|null $expected
     * @return array{dev: int, ino: int}
     */
    private function assertHandleMatchesPath($handle, string $path, ?array $expected = null): array
    {
        $opened = $this->filesystem->stat($handle, 'okx_paper_live_file_validation');
        $current = $this->pathStatistics($path);
        if ($opened === false
            || $current === false
            || $this->isSymlink($current)
            || !$this->isPrivateRegularFile($opened)
            || !$this->isPrivateRegularFile($current)
            || !$this->sameFile($opened, $current)
            || ($expected !== null && !$this->sameFile($expected, $opened))
            || !isset($opened['dev'], $opened['ino'])
            || !\is_int($opened['dev'])
            || !\is_int($opened['ino'])
        ) {
            throw self::invalidCheckpoint();
        }

        return ['dev' => $opened['dev'], 'ino' => $opened['ino']];
    }

    private function removeTemporaryPath(string $path): void
    {
        $statistics = $this->pathStatistics($path);
        if ($statistics !== false
            && isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && \in_array(
                $statistics['mode'] & self::FILE_TYPE_MASK,
                [self::REGULAR_FILE_TYPE, self::SYMLINK_FILE_TYPE],
                true,
            )
        ) {
            @unlink($path);
        }
    }

    /** @return array<string, mixed>|false */
    private function pathStatistics(string $path): array|false
    {
        $statistics = $this->filesystem->pathStat($path, 'okx_paper_live_path_validation');
        if ($statistics === false && (file_exists($path) || is_link($path))) {
            throw self::invalidCheckpoint();
        }

        return $statistics;
    }

    /** @param array<string, mixed> $statistics */
    private function isSymlink(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::SYMLINK_FILE_TYPE;
    }

    /** @param array<string, mixed> $statistics */
    private function isDirectory(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::DIRECTORY_FILE_TYPE;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateDirectory(array $statistics): bool
    {
        return $this->isDirectory($statistics) && ($statistics['mode'] & 0777) === 0700;
    }

    /** @param array<string, mixed> $statistics */
    private function isPrivateRegularFile(array $statistics): bool
    {
        return isset($statistics['mode'])
            && \is_int($statistics['mode'])
            && ($statistics['mode'] & self::FILE_TYPE_MASK) === self::REGULAR_FILE_TYPE
            && ($statistics['mode'] & 0777) === 0600
            && isset($statistics['nlink'])
            && \is_int($statistics['nlink'])
            && $statistics['nlink'] === 1;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sameFile(array $left, array $right): bool
    {
        return isset($left['dev'], $left['ino'], $right['dev'], $right['ino'])
            && \is_int($left['dev'])
            && \is_int($left['ino'])
            && \is_int($right['dev'])
            && \is_int($right['ino'])
            && $left['dev'] === $right['dev']
            && $left['ino'] === $right['ino'];
    }

    /**
     * @param array<string, mixed>       $expected
     * @param array<string, mixed>|false $actual
     */
    private function sameSnapshot(array $expected, array|false $actual): bool
    {
        return $actual !== false
            && $this->isPrivateRegularFile($actual)
            && $this->sameFile($expected, $actual)
            && isset($expected['size'], $actual['size'])
            && \is_int($expected['size'])
            && \is_int($actual['size'])
            && $expected['size'] === $actual['size'];
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                throw self::invalidCheckpoint();
            }
            $path = $workingDirectory . DIRECTORY_SEPARATOR . $path;
        }
        $current = DIRECTORY_SEPARATOR;
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                $current = dirname($current);
                continue;
            }
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $component;
            $statistics = $this->filesystem->pathStat($current, 'okx_paper_live_path_validation');
            if ($statistics !== false && $this->isSymlink($statistics)) {
                throw self::invalidCheckpoint();
            }
        }
    }

    private function closeInitializedResources(): void
    {
        if (isset($this->writerLock) && \is_resource($this->writerLock['handle'])) {
            @flock($this->writerLock['handle'], \LOCK_UN);
            fclose($this->writerLock['handle']);
            unset($this->writerLock);
        }
        foreach (['directoryPin', 'checkpointsPin', 'datasetPin'] as $property) {
            if (isset($this->{$property}) && \is_resource($this->{$property}['handle'])) {
                fclose($this->{$property}['handle']);
                unset($this->{$property});
            }
        }
    }

    private static function invalidCheckpoint(?\Throwable $previous = null): OkxPaperLiveIntegrityException
    {
        return new OkxPaperLiveIntegrityException('okx_paper_live_checkpoint_invalid', 0, $previous);
    }
}
