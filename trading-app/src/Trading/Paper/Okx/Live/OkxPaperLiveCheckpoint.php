<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Okx\Normalization\OkxPaperSourceOrdinal;

final readonly class OkxPaperLiveCheckpoint
{
    public const SCHEMA_VERSION = 7;
    public const MISSING_CANONICAL_DIGEST = '----------------------------------------------------------------';

    /** @var list<string> */
    private const SYMBOLS = ['BTCUSDT', 'ETHUSDT'];
    private const SHA256_PATTERN = '/\A[a-f0-9]{64}\z/D';
    private const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** @var list<string> */
    private const PHASES = [
        'warming',
        'connecting',
        'subscribing',
        'streaming',
        'resyncing',
        'reconnecting',
        'stopping',
        'complete',
        'failed',
    ];

    /** @var list<string> */
    private const FAILURE_REASONS = [
        'market_event_identity_conflict',
        'market_data_gap_unresolved',
        'market_data_backpressure_exhausted',
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
    ];

    /**
     * @param array<string, OkxPaperStreamFrontier|null> $streamFrontiers
     * @param array<string, mixed>                       $ordinalState
     * @param array<string, mixed>|null                  $pendingTransition
     * @param list<string>                               $remainingSymbols
     * @param list<array{symbol: string, reason: string}> $remainingBoundaries
     * @param array<string, int>                         $sourceEpochs
     * @param array{liveness_proven: bool, remaining_symbols: list<string>, requested: bool} $healthyStop
     * @param array<string, mixed>                       $reconnect
     * @param array<string, mixed>                       $resyncBySymbol
     * @param array<string, mixed>                       $overlapPaginationByStream
     * @param array{public: list<string>, business: list<string>} $streamingQueues
     * @param array{
     *     format: string,
     *     sha256: string,
     *     storage_bytes: int,
     *     public: array{frames: int, bytes: int},
     *     business: array{frames: int, bytes: int}
     * }|null $streamingQueueRef
     * @param array<string, list<array{string, string, string, string}|string>> $acknowledgedIdentityHistory
     * @param array{stream: string, frontier: OkxPaperStreamFrontier}|null $pendingFrontier
     */
    private function __construct(
        public int $schemaVersion,
        public string $datasetId,
        public string $configurationSha256,
        public string $phase,
        public ?string $failureReason,
        public ?array $pendingTransition,
        public array $remainingSymbols,
        public array $remainingBoundaries,
        public int $connectionEpoch,
        public array $sourceEpochs,
        public array $streamFrontiers,
        public array $ordinalState,
        public ?string $lastAcknowledgedEventId,
        public ?PaperMarketEvent $pendingEvent,
        public ?array $pendingFrontier,
        public array $healthyStop,
        public array $reconnect,
        public array $resyncBySymbol,
        public array $overlapPaginationByStream,
        public array $streamingQueues,
        public ?array $streamingQueueRef,
        public array $acknowledgedIdentityHistory,
        private bool $streamingQueuesExplicit,
    ) {
    }

    public static function fresh(string $datasetId, string $configurationSha256): self
    {
        $frontiers = array_fill_keys(self::streamKeys(), null);
        $pagination = array_fill_keys(self::paginationStreamKeys(), null);

        return self::fromArray([
            'configuration_sha256' => $configurationSha256,
            'connection_epoch' => 1,
            'dataset_id' => $datasetId,
            'failure_reason' => null,
            'healthy_stop' => [
                'liveness_proven' => false,
                'remaining_symbols' => [],
                'requested' => false,
            ],
            'last_acknowledged_event_id' => null,
            'ordinal_state' => ['schema_version' => 1, 'scopes' => []],
            'overlap_pagination_by_stream' => $pagination,
            'pending_event' => null,
            'pending_frontier' => null,
            'pending_transition' => null,
            'phase' => 'warming',
            'reconnect' => [
                'attempt' => 0,
                'deadline_at' => null,
                'stable_since' => null,
                'accepted_events' => 0,
            ],
            'remaining_boundaries' => [
                ['symbol' => 'BTCUSDT', 'reason' => 'initial'],
                ['symbol' => 'ETHUSDT', 'reason' => 'initial'],
            ],
            'remaining_symbols' => self::SYMBOLS,
            'resync_by_symbol' => ['BTCUSDT' => null, 'ETHUSDT' => null],
            'schema_version' => self::SCHEMA_VERSION,
            'source_epochs' => ['BTCUSDT' => 1, 'ETHUSDT' => 1],
            'stream_frontiers' => $frontiers,
        ]);
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(#[\SensitiveParameter] array $state): self
    {
        try {
            $expectedKeys = [
                'schema_version',
                'dataset_id',
                'configuration_sha256',
                'phase',
                'failure_reason',
                'pending_transition',
                'remaining_symbols',
                'remaining_boundaries',
                'connection_epoch',
                'source_epochs',
                'stream_frontiers',
                'ordinal_state',
                'last_acknowledged_event_id',
                'pending_event',
                'pending_frontier',
                'healthy_stop',
                'reconnect',
                'resync_by_symbol',
                'overlap_pagination_by_stream',
            ];
            if (array_key_exists('streaming_queues', $state)) {
                $expectedKeys[] = 'streaming_queues';
            }
            if (array_key_exists('streaming_queue_ref', $state)) {
                $expectedKeys[] = 'streaming_queue_ref';
            }
            if (array_key_exists('acknowledged_identity_history', $state)) {
                $expectedKeys[] = 'acknowledged_identity_history';
            }
            self::assertExactKeys($state, $expectedKeys);
            if ($state['schema_version'] !== self::SCHEMA_VERSION
                || !\is_string($state['dataset_id'])
                || !\is_string($state['configuration_sha256'])
                || preg_match(self::SHA256_PATTERN, $state['configuration_sha256']) !== 1
                || !\is_string($state['phase'])
                || !\is_array($state['remaining_symbols'])
                || !\is_array($state['remaining_boundaries'])
                || !\is_int($state['connection_epoch'])
                || $state['connection_epoch'] < 1
                || !\is_array($state['source_epochs'])
                || !\is_array($state['stream_frontiers'])
                || !\is_array($state['ordinal_state'])
                || ($state['last_acknowledged_event_id'] !== null
                    && (!\is_string($state['last_acknowledged_event_id'])
                        || preg_match(self::SHA256_PATTERN, $state['last_acknowledged_event_id']) !== 1))
                || !\is_array($state['healthy_stop'])
                || !\is_array($state['reconnect'])
                || !\is_array($state['resync_by_symbol'])
                || !\is_array($state['overlap_pagination_by_stream'])
            ) {
                throw new \InvalidArgumentException();
            }
            PaperDatasetManifest::assertDatasetId($state['dataset_id']);
            self::assertExactMapKeys($state['stream_frontiers'], self::streamKeys());
            self::assertExactMapKeys($state['overlap_pagination_by_stream'], self::paginationStreamKeys());
            self::assertExactMapKeys($state['source_epochs'], self::SYMBOLS);
            self::assertExactMapKeys($state['resync_by_symbol'], self::SYMBOLS);

            self::assertPhaseAndFailure($state['phase'], $state['failure_reason']);
            $pendingTransition = self::transition($state['pending_transition']);
            $remainingSymbols = self::orderedSymbols($state['remaining_symbols']);
            $remainingBoundaries = self::boundaries($state['remaining_boundaries']);
            $healthyStop = self::healthyStop($state['healthy_stop']);
            $reconnect = self::reconnect($state['reconnect']);
            $ordinalState = OkxPaperSourceOrdinal::restore($state['ordinal_state'])->snapshot();

            $frontiers = [];
            foreach ($state['stream_frontiers'] as $stream => $frontier) {
                if ($frontier !== null && (!\is_array($frontier) || array_is_list($frontier))) {
                    throw new \InvalidArgumentException();
                }
                $parsed = $frontier === null ? null : OkxPaperStreamFrontier::fromArray($frontier);
                if ($parsed !== null) {
                    self::assertFrontierMatchesStream($parsed, $stream);
                }
                $frontiers[$stream] = $parsed;
            }

            $resyncBySymbol = [];
            foreach (self::SYMBOLS as $symbol) {
                if (!\is_int($state['source_epochs'][$symbol]) || $state['source_epochs'][$symbol] < 1) {
                    throw new \InvalidArgumentException();
                }
                $resyncBySymbol[$symbol] = self::resync($state['resync_by_symbol'][$symbol], $symbol);
            }

            $paginationByStream = [];
            foreach ($state['overlap_pagination_by_stream'] as $stream => $pagination) {
                $paginationByStream[$stream] = self::pagination($pagination, $stream);
            }
            $streamingQueuesExplicit = array_key_exists('streaming_queues', $state);
            $streamingQueueRef = array_key_exists('streaming_queue_ref', $state)
                ? self::streamingQueueRef($state['streaming_queue_ref'])
                : null;
            if ($streamingQueuesExplicit && $streamingQueueRef !== null) {
                throw new \InvalidArgumentException();
            }
            $streamingQueues = $streamingQueuesExplicit
                ? self::streamingQueues($state['streaming_queues'])
                : ($streamingQueueRef === null
                    ? self::legacyResyncStreamingQueues($resyncBySymbol)
                    : ['public' => [], 'business' => []]);
            self::assertResyncQueueMirrorsMatchStreamingQueues(
                $resyncBySymbol,
                $streamingQueues,
                $streamingQueueRef,
            );
            $acknowledgedIdentityHistory = self::acknowledgedIdentityHistory(
                $state['acknowledged_identity_history'] ?? [],
            );

            $pendingEvent = self::pendingEvent($state['pending_event']);
            $pendingFrontier = self::pendingFrontier($state['pending_frontier'], $pendingEvent);
            if ($pendingEvent !== null
                && $pendingFrontier === null
                && !\in_array($pendingEvent->channel->value, [
                    'connection_state',
                    'snapshot_boundary',
                ], true)
            ) {
                throw new \InvalidArgumentException();
            }
            self::assertOrdinalContainsPendingEvent($ordinalState, $pendingEvent);
            self::assertRecoveryContinuation(
                $state['phase'],
                $pendingTransition,
                $pendingEvent,
                $pendingFrontier,
                $reconnect,
                $resyncBySymbol,
                $paginationByStream,
                $frontiers,
            );
            self::assertPhaseTransition($state['phase'], $pendingTransition);
            if ($pendingEvent !== null && $pendingTransition !== null) {
                throw new \InvalidArgumentException();
            }
            if ($state['phase'] === 'complete' && $pendingEvent !== null) {
                throw new \InvalidArgumentException();
            }
            if ($state['phase'] === 'failed' && $pendingEvent !== null) {
                throw new \InvalidArgumentException();
            }
            if (!$healthyStop['requested']
                && ($healthyStop['remaining_symbols'] !== []
                    || $healthyStop['liveness_proven'])
            ) {
                throw new \InvalidArgumentException();
            }
            if ($state['phase'] === 'stopping'
                && (!$healthyStop['requested'] || !$healthyStop['liveness_proven'])
            ) {
                throw new \InvalidArgumentException();
            }
            if ($state['phase'] === 'complete') {
                if (!$healthyStop['requested']
                    || !$healthyStop['liveness_proven']
                    || $healthyStop['remaining_symbols'] !== []
                    || $remainingSymbols !== []
                    || $remainingBoundaries !== []
                    || $reconnect !== [
                        'attempt' => 0,
                        'deadline_at' => null,
                        'stable_since' => null,
                        'accepted_events' => 0,
                    ]
                    || array_filter($resyncBySymbol, static fn (mixed $value): bool => $value !== null) !== []
                    || array_filter(
                        $paginationByStream,
                        static fn (mixed $value): bool => $value !== null,
                    ) !== []
                ) {
                    throw new \InvalidArgumentException();
                }
                foreach (self::SYMBOLS as $symbol) {
                    $stopped = $frontiers[$symbol . '/control/connection_state'] ?? null;
                    if (!$stopped instanceof OkxPaperStreamFrontier
                        || !str_ends_with($stopped->sourceIdentity, '|stopped')
                    ) {
                        throw new \InvalidArgumentException();
                    }
                }
            }

            return new self(
                schemaVersion: self::SCHEMA_VERSION,
                datasetId: $state['dataset_id'],
                configurationSha256: $state['configuration_sha256'],
                phase: $state['phase'],
                failureReason: $state['failure_reason'],
                pendingTransition: $pendingTransition,
                remainingSymbols: $remainingSymbols,
                remainingBoundaries: $remainingBoundaries,
                connectionEpoch: $state['connection_epoch'],
                sourceEpochs: $state['source_epochs'],
                streamFrontiers: $frontiers,
                ordinalState: $ordinalState,
                lastAcknowledgedEventId: $state['last_acknowledged_event_id'],
                pendingEvent: $pendingEvent,
                pendingFrontier: $pendingFrontier,
                healthyStop: $healthyStop,
                reconnect: $reconnect,
                resyncBySymbol: $resyncBySymbol,
                overlapPaginationByStream: $paginationByStream,
                streamingQueues: $streamingQueues,
                streamingQueueRef: $streamingQueueRef,
                acknowledgedIdentityHistory: $acknowledgedIdentityHistory,
                streamingQueuesExplicit: $streamingQueuesExplicit,
            );
        } catch (\Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException
                && $exception->getMessage() === 'okx_paper_live_checkpoint_invalid'
            ) {
                throw $exception;
            }

            throw new \InvalidArgumentException('okx_paper_live_checkpoint_invalid', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $state = [
            'configuration_sha256' => $this->configurationSha256,
            'connection_epoch' => $this->connectionEpoch,
            'dataset_id' => $this->datasetId,
            'failure_reason' => $this->failureReason,
            'healthy_stop' => $this->healthyStop,
            'last_acknowledged_event_id' => $this->lastAcknowledgedEventId,
            'ordinal_state' => $this->ordinalState,
            'overlap_pagination_by_stream' => array_map(
                static function (?array $pagination): ?array {
                    if ($pagination === null) {
                        return null;
                    }
                    $state = [
                        'endpoint' => $pagination['endpoint'],
                        'pagination_type' => $pagination['pagination_type'],
                        'next_cursor' => $pagination['next_cursor'],
                        'pages_consumed' => $pagination['pages_consumed'],
                        'pages_remaining' => $pagination['pages_remaining'],
                        'target_frontier' => $pagination['target_frontier']->toArray(),
                        'deadline_at' => $pagination['deadline_at'],
                    ];
                    if (array_key_exists('retained_rows', $pagination)) {
                        $state['retained_rows'] = $pagination['retained_rows'];
                    }

                    return $state;
                },
                $this->overlapPaginationByStream,
            ),
            'pending_event' => $this->pendingEvent?->toArray(),
            'pending_frontier' => $this->pendingFrontier === null ? null : [
                'stream' => $this->pendingFrontier['stream'],
                'frontier' => $this->pendingFrontier['frontier']->toArray(),
            ],
            'pending_transition' => $this->pendingTransition,
            'phase' => $this->phase,
            'reconnect' => $this->reconnect,
            'remaining_boundaries' => $this->remainingBoundaries,
            'remaining_symbols' => $this->remainingSymbols,
            'resync_by_symbol' => array_map(
                static function (?array $resync): ?array {
                    if ($resync === null) {
                        return null;
                    }
                    $state = [
                        'attempt' => $resync['attempt'],
                        'frontier' => $resync['frontier']->toArray(),
                        'source_sequence' => $resync['source_sequence'],
                        'deadline_at' => $resync['deadline_at'],
                        'policy' => $resync['policy'],
                    ];
                    foreach ([
                        'book_snapshot',
                        'queued_public_frames',
                        'queued_business_frames',
                    ] as $field) {
                        if (array_key_exists($field, $resync)) {
                            $state[$field] = $resync[$field];
                        }
                    }

                    return $state;
                },
                $this->resyncBySymbol,
            ),
            'schema_version' => $this->schemaVersion,
            'source_epochs' => $this->sourceEpochs,
            'stream_frontiers' => array_map(
                static fn (?OkxPaperStreamFrontier $frontier): ?array => $frontier?->toArray(),
                $this->streamFrontiers,
            ),
        ];
        if ($this->streamingQueueRef !== null) {
            $state['streaming_queue_ref'] = $this->streamingQueueRef;
        } elseif ($this->streamingQueuesExplicit) {
            $state['streaming_queues'] = $this->streamingQueues;
        }
        if ($this->acknowledgedIdentityHistory !== []) {
            $state['acknowledged_identity_history'] = $this->acknowledgedIdentityHistory;
        }

        return $state;
    }

    /**
     * @return array<string, list<array{string, string, string, string}|string>>
     */
    private static function acknowledgedIdentityHistory(mixed $value): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException();
        }
        $allowed = array_fill_keys(self::logicalIdentityStreamKeys(), true);
        $validated = [];
        foreach ($value as $stream => $entries) {
            if (!\is_string($stream)
                || !isset($allowed[$stream])
                || !\is_array($entries)
                || !array_is_list($entries)
                || \count($entries)
                    > OkxPaperLivePolicy::acknowledgedIdentityHistoryWindow($stream)
            ) {
                throw new \InvalidArgumentException();
            }
            $seen = [];
            foreach ($entries as $entry) {
                if (!\is_array($entry) && !\is_string($entry)) {
                    throw new \InvalidArgumentException();
                }
                try {
                    [$identity] = OkxPaperAcknowledgedIdentityEntry::expand($entry);
                } catch (\InvalidArgumentException) {
                    throw new \InvalidArgumentException();
                }
                if (isset($seen[$identity])) {
                    throw new \InvalidArgumentException();
                }
                $seen[$identity] = true;
            }
            $validated[$stream] = $entries;
        }

        return $validated;
    }

    /** @return list<string> */
    private static function logicalIdentityStreamKeys(): array
    {
        $keys = [];
        foreach (self::SYMBOLS as $symbol) {
            foreach (['candle_1m', 'candle_5m', 'candle_15m', 'candle_1H', 'public_trade'] as $channel) {
                $keys[] = $symbol . '/' . $channel;
            }
        }

        return $keys;
    }

    /**
     * @return array{public: list<string>, business: list<string>}
     */
    private static function streamingQueues(mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys($value, ['public', 'business']);
        foreach (['public', 'business'] as $socket) {
            if (!\is_array($value[$socket]) || !array_is_list($value[$socket])) {
                throw new \InvalidArgumentException();
            }
            $bytes = 0;
            foreach ($value[$socket] as $frame) {
                if (!\is_string($frame)
                    || $frame === ''
                    || \strlen($frame) > OkxPaperLivePolicy::MAX_FRAME_BYTES
                ) {
                    throw new \InvalidArgumentException();
                }
                $bytes += \strlen($frame);
            }
            if (\count($value[$socket]) > OkxPaperLivePolicy::MAX_QUEUED_FRAMES
                || $bytes > OkxPaperLivePolicy::MAX_QUEUED_BYTES
            ) {
                throw new \InvalidArgumentException();
            }
        }

        return [
            'public' => $value['public'],
            'business' => $value['business'],
        ];
    }

    /**
     * @return array{
     *     format: string,
     *     sha256: string,
     *     storage_bytes: int,
     *     public: array{frames: int, bytes: int},
     *     business: array{frames: int, bytes: int}
     * }
     */
    private static function streamingQueueRef(mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys(
            $value,
            ['format', 'sha256', 'storage_bytes', 'public', 'business'],
        );
        if ($value['format'] !== 'okx_raw_queue_v1'
            || !\is_string($value['sha256'])
            || preg_match(self::SHA256_PATTERN, $value['sha256']) !== 1
            || !\is_int($value['storage_bytes'])
            || $value['storage_bytes'] < 14
            || $value['storage_bytes'] > 2 * OkxPaperLivePolicy::MAX_QUEUED_BYTES
                + 8 * OkxPaperLivePolicy::MAX_QUEUED_FRAMES
                + 14
        ) {
            throw new \InvalidArgumentException();
        }
        foreach (['public', 'business'] as $socket) {
            if (!\is_array($value[$socket]) || array_is_list($value[$socket])) {
                throw new \InvalidArgumentException();
            }
            self::assertExactKeys($value[$socket], ['frames', 'bytes']);
            if (!\is_int($value[$socket]['frames'])
                || $value[$socket]['frames'] < 0
                || $value[$socket]['frames'] > OkxPaperLivePolicy::MAX_QUEUED_FRAMES
                || !\is_int($value[$socket]['bytes'])
                || $value[$socket]['bytes'] < 0
                || $value[$socket]['bytes'] > OkxPaperLivePolicy::MAX_QUEUED_BYTES
            ) {
                throw new \InvalidArgumentException();
            }
        }

        return $value;
    }

    /** @return list<string> */
    private static function streamKeys(): array
    {
        $suffixes = [
            'control/connection_state',
            'control/snapshot_boundary',
            'rest/candle_15m',
            'rest/candle_1H',
            'rest/candle_1m',
            'rest/candle_5m',
            'rest/instrument_metadata',
            'rest/funding_rate',
            'rest/public_trade',
            'rest/top_of_book',
            'ws/candle_15m',
            'ws/candle_1H',
            'ws/candle_1m',
            'ws/candle_5m',
            'ws/public_trade',
            'ws/top_of_book',
        ];
        $keys = [];
        foreach (self::SYMBOLS as $symbol) {
            foreach ($suffixes as $suffix) {
                $keys[] = $symbol . '/' . $suffix;
            }
        }
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return list<string> */
    private static function paginationStreamKeys(): array
    {
        return array_values(array_filter(
            self::streamKeys(),
            static fn (string $stream): bool => str_contains($stream, '/candle_')
                || str_ends_with($stream, '/public_trade'),
        ));
    }

    private static function assertPhaseAndFailure(string $phase, mixed $failureReason): void
    {
        if (!\in_array($phase, self::PHASES, true)) {
            throw new \InvalidArgumentException();
        }
        if ($phase === 'failed') {
            if (!\is_string($failureReason) || !\in_array($failureReason, self::FAILURE_REASONS, true)) {
                throw new \InvalidArgumentException();
            }

            return;
        }
        if ($failureReason !== null) {
            throw new \InvalidArgumentException();
        }
    }

    /** @return array{kind: string, symbol: string|null, stream: string|null, stage: string}|null */
    private static function transition(mixed $transition): ?array
    {
        if ($transition === null) {
            return null;
        }
        if (!\is_array($transition) || array_is_list($transition)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys($transition, ['kind', 'symbol', 'stream', 'stage']);
        if (!\is_string($transition['kind'])
            || ($transition['symbol'] !== null && !\is_string($transition['symbol']))
            || ($transition['stream'] !== null && !\is_string($transition['stream']))
            || !\is_string($transition['stage'])
        ) {
            throw new \InvalidArgumentException();
        }

        $kind = $transition['kind'];
        $symbol = $transition['symbol'];
        $stream = $transition['stream'];
        $stage = $transition['stage'];
        $valid = match ($kind) {
            'rest_fetch' => self::validRestTransition($symbol, $stream, $stage),
            'transport_connect' => $stage === 'connect' && $symbol === null && self::isTransport($stream),
            'transport_close' => $stage === 'close' && $symbol === null && self::isTransport($stream),
            'subscription_send' => $stage === 'subscribe' && $symbol === null && self::isTransport($stream),
            'timer_schedule' => ($stage === 'reconnect_delay' && $symbol === null && $stream === null)
                || ($stage === 'resync_timeout' && self::isSymbolBookStream($symbol, $stream)),
            'timer_cancel' => ($stage === 'cancel_reconnect_timer' && $symbol === null && $stream === null)
                || ($stage === 'cancel_resync_timer' && self::isSymbolBookStream($symbol, $stream)),
            'emit_boundary' => \in_array($stage, ['initial', 'reconnect', 'sequence_gap'], true)
                && self::isSymbolControlStream($symbol, $stream, 'snapshot_boundary'),
            'emit_connection_state' => $stage === 'reconnecting'
                && self::isSymbolControlStream($symbol, $stream, 'connection_state'),
            'healthy_stop' => ($stage === 'emit_stopped'
                    && self::isSymbolControlStream($symbol, $stream, 'connection_state'))
                || ($stage === 'finalize' && $symbol === null && $stream === null),
            'loop_stop' => $stage === 'stop_loop' && $symbol === null && $stream === null,
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException();
        }

        return ['kind' => $kind, 'symbol' => $symbol, 'stream' => $stream, 'stage' => $stage];
    }

    private static function validRestTransition(?string $symbol, ?string $stream, string $stage): bool
    {
        if ($symbol === null || !\in_array($symbol, self::SYMBOLS, true) || $stream === null
            || !str_starts_with($stream, $symbol . '/') || !\in_array($stream, self::streamKeys(), true)
        ) {
            return false;
        }
        $suffix = substr($stream, \strlen($symbol) + 1);

        return match ($stage) {
            'instrument_metadata' => $suffix === 'rest/instrument_metadata',
            'funding_rate' => $suffix === 'rest/funding_rate',
            'current_candles', 'history_candles' => preg_match('/\A(?:rest|ws)\/candle_(?:1m|5m|15m|1H)\z/D', $suffix) === 1,
            'recent_trades', 'history_trades' => preg_match('/\A(?:rest|ws)\/public_trade\z/D', $suffix) === 1,
            'order_book' => $suffix === 'rest/top_of_book',
            default => false,
        };
    }

    /** @param array{kind: string, symbol: string|null, stream: string|null, stage: string}|null $transition */
    private static function assertPhaseTransition(string $phase, ?array $transition): void
    {
        if ($transition === null) {
            return;
        }
        $kind = $transition['kind'];
        $stage = $transition['stage'];
        $valid = match ($phase) {
            'warming' => ($kind === 'rest_fetch'
                    && \in_array($stage, [
                        'current_candles',
                        'instrument_metadata',
                        'funding_rate',
                        'recent_trades',
                        'history_candles',
                        'history_trades',
                        'order_book',
                    ], true))
                || ($kind === 'emit_boundary' && $stage === 'initial'),
            'connecting' => $kind === 'transport_connect',
            'subscribing' => $kind === 'subscription_send',
            'streaming', 'complete' => false,
            'resyncing' => \in_array($kind, [
                'rest_fetch',
                'transport_connect',
                'subscription_send',
            ], true)
                || ($kind === 'timer_schedule' && $stage === 'resync_timeout')
                || ($kind === 'timer_cancel' && $stage === 'cancel_resync_timer')
                || ($kind === 'emit_boundary' && $stage === 'sequence_gap'),
            'reconnecting' => \in_array($kind, [
                'rest_fetch',
                'transport_connect',
                'transport_close',
                'subscription_send',
                'emit_connection_state',
            ], true)
                || ($kind === 'timer_schedule' && $stage === 'reconnect_delay')
                || ($kind === 'timer_schedule' && $stage === 'resync_timeout')
                || ($kind === 'timer_cancel' && $stage === 'cancel_reconnect_timer')
                || ($kind === 'timer_cancel' && $stage === 'cancel_resync_timer')
                || ($kind === 'emit_boundary' && \in_array($stage, [
                    'reconnect',
                    'sequence_gap',
                ], true)),
            'stopping' => $kind === 'transport_close'
                || $kind === 'timer_cancel'
                || $kind === 'healthy_stop'
                || $kind === 'loop_stop',
            'failed' => $kind === 'transport_close'
                || $kind === 'timer_cancel'
                || $kind === 'loop_stop',
            default => false,
        };
        if (!$valid) {
            throw new \InvalidArgumentException();
        }
    }

    /**
     * @param array{kind: string, symbol: string|null, stream: string|null, stage: string}|null $transition
     * @param array{stream: string, frontier: OkxPaperStreamFrontier}|null $pendingFrontier
     * @param array{attempt: int, deadline_at: string|null, stable_since: string|null, accepted_events: int} $reconnect
     * @param array<string, mixed> $resyncBySymbol
     * @param array<string, mixed> $paginationByStream
     * @param array<string, OkxPaperStreamFrontier|null> $streamFrontiers
     */
    private static function assertRecoveryContinuation(
        string $phase,
        ?array $transition,
        ?PaperMarketEvent $pendingEvent,
        ?array $pendingFrontier,
        array $reconnect,
        array $resyncBySymbol,
        array $paginationByStream,
        array $streamFrontiers,
    ): void {
        if ($phase === 'resyncing') {
            $activeResyncs = array_filter(
                $resyncBySymbol,
                static fn (mixed $resync): bool => $resync !== null,
            );
            if ($activeResyncs === []) {
                throw new \InvalidArgumentException();
            }
            $continuationSymbol = $transition['symbol'] ?? $pendingEvent?->symbol;
            if ($continuationSymbol !== null
                && !\is_array($resyncBySymbol[$continuationSymbol] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
        }
        self::assertResyncContinuationMatches(
            $phase,
            $transition,
            $pendingEvent,
            $pendingFrontier,
            $resyncBySymbol,
            $streamFrontiers,
        );
        if (($transition['stage'] ?? null) === 'reconnect_delay'
            && ($reconnect['attempt'] < 1 || $reconnect['deadline_at'] === null)
        ) {
            throw new \InvalidArgumentException();
        }
        if (\in_array($transition['stage'] ?? null, ['resync_timeout', 'cancel_resync_timer'], true)) {
            $symbol = $transition['symbol'];
            $resync = \is_string($symbol) ? ($resyncBySymbol[$symbol] ?? null) : null;
            $cleanupCancellation = $transition['stage'] === 'cancel_resync_timer'
                && \in_array($phase, ['stopping', 'failed'], true);
            if (!$cleanupCancellation
                && (!\is_array($resync) || ($resync['policy'] ?? null) !== 'book_seq_overlap_v1')
            ) {
                throw new \InvalidArgumentException();
            }
        }

        $historyStream = null;
        if (($transition['kind'] ?? null) === 'rest_fetch'
            && \in_array($transition['stage'], ['history_candles', 'history_trades'], true)
        ) {
            $historyStream = $transition['stream'];
            if (!\is_string($historyStream) || !\is_array($paginationByStream[$historyStream] ?? null)) {
                throw new \InvalidArgumentException();
            }
        }

        foreach ($paginationByStream as $stream => $pagination) {
            if ($pagination === null) {
                continue;
            }
            if ($transition !== null
                && $transition['stream'] === $stream
                && ($transition['kind'] !== 'rest_fetch'
                    || $transition['stage'] !== $pagination['endpoint'])
            ) {
                throw new \InvalidArgumentException();
            }
            $symbol = strstr($stream, '/', true);
            $resync = \is_string($symbol) ? ($resyncBySymbol[$symbol] ?? null) : null;
            if (!\is_array($resync)
                || ($resync['policy'] ?? null) !== 'frontier_overlap_v1'
                || ($resync['deadline_at'] ?? null) !== ($pagination['deadline_at'] ?? null)
                || !self::sameFrontier(
                    $resync['frontier'] ?? null,
                    $pagination['target_frontier'] ?? null,
                )
            ) {
                throw new \InvalidArgumentException();
            }
        }
    }

    /**
     * @param array{kind: string, symbol: string|null, stream: string|null, stage: string}|null $transition
     * @param array{stream: string, frontier: OkxPaperStreamFrontier}|null $pendingFrontier
     * @param array<string, mixed> $resyncBySymbol
     * @param array<string, OkxPaperStreamFrontier|null> $streamFrontiers
     */
    private static function assertResyncContinuationMatches(
        string $phase,
        ?array $transition,
        ?PaperMarketEvent $pendingEvent,
        ?array $pendingFrontier,
        array $resyncBySymbol,
        array $streamFrontiers,
    ): void {
        $symbol = $transition['symbol'] ?? $pendingEvent?->symbol;
        if (!\is_string($symbol) || !\is_array($resyncBySymbol[$symbol] ?? null)) {
            return;
        }
        $resync = $resyncBySymbol[$symbol];
        if (\in_array($phase, ['failed', 'stopping'], true)
            && ($transition['kind'] ?? null) === 'timer_cancel'
            && $transition['stage'] === 'cancel_resync_timer'
        ) {
            return;
        }
        $stream = $transition['stream'] ?? $pendingFrontier['stream'] ?? null;
        if ($stream === null && $pendingEvent !== null) {
            $stream = $symbol . '/control/' . $pendingEvent->channel->value;
        }
        if (!\is_string($stream) || !str_starts_with($stream, $symbol . '/')) {
            throw new \InvalidArgumentException();
        }

        if ($resync['policy'] === 'book_seq_overlap_v1') {
            if (!self::sameFrontier(
                $resync['frontier'],
                $streamFrontiers[$symbol . '/ws/top_of_book']
                    ?? $streamFrontiers[$symbol . '/rest/top_of_book']
                    ?? null,
            )) {
                throw new \InvalidArgumentException();
            }
            $boundaryReason = $phase === 'reconnecting'
                && ($transition['kind'] ?? null) === 'emit_boundary'
                && $transition['stage'] === 'sequence_gap'
                    ? 'sequence_gap'
                    : match ($phase) {
                        'reconnecting' => 'reconnect',
                        'resyncing' => 'sequence_gap',
                        default => null,
                    };
            if ($transition !== null) {
                $valid = ($transition['kind'] === 'rest_fetch'
                        && $transition['stage'] === 'order_book'
                        && $stream === $symbol . '/rest/top_of_book')
                    || ($transition['kind'] === 'timer_schedule'
                        && $transition['stage'] === 'resync_timeout'
                        && $stream === $symbol . '/ws/top_of_book')
                    || ($transition['kind'] === 'timer_cancel'
                        && $transition['stage'] === 'cancel_resync_timer'
                        && $stream === $symbol . '/ws/top_of_book')
                    || ($transition['kind'] === 'emit_boundary'
                        && $boundaryReason !== null
                        && $transition['stage'] === $boundaryReason
                        && $stream === $symbol . '/control/snapshot_boundary');
                if (!$valid) {
                    throw new \InvalidArgumentException();
                }

                return;
            }
            if ($pendingEvent === null) {
                return;
            }
            $valid = ($pendingEvent->channel->value === 'top_of_book'
                    && $stream === $symbol . '/rest/top_of_book'
                    && ($pendingEvent->payload['origin'] ?? null) === 'rest_resync_snapshot')
                || ($pendingEvent->channel->value === 'snapshot_boundary'
                    && $stream === $symbol . '/control/snapshot_boundary'
                    && $boundaryReason !== null
                    && ($pendingEvent->payload['reason'] ?? null) === $boundaryReason);
            if (!$valid) {
                throw new \InvalidArgumentException();
            }

            return;
        }

        $frontierChannel = self::frontierChannel($resync['frontier'], $symbol);
        $streamChannel = substr($stream, strrpos($stream, '/') + 1);
        $streamChannel = $streamChannel === 'candle_1H' ? 'candle_1h' : $streamChannel;
        if ($streamChannel !== $frontierChannel) {
            throw new \InvalidArgumentException();
        }
        if (!self::sameFrontier($resync['frontier'], $streamFrontiers[$stream] ?? null)) {
            throw new \InvalidArgumentException();
        }
        if ($transition !== null) {
            $validStages = $frontierChannel === 'public_trade'
                ? ['recent_trades', 'history_trades']
                : ['current_candles', 'history_candles'];
            if ($transition['kind'] !== 'rest_fetch'
                || !\in_array($transition['stage'], $validStages, true)
            ) {
                throw new \InvalidArgumentException();
            }

            return;
        }
        if ($pendingEvent !== null && $pendingEvent->channel->value !== $frontierChannel) {
            throw new \InvalidArgumentException();
        }
    }

    private static function sameFrontier(mixed $left, mixed $right): bool
    {
        return $left instanceof OkxPaperStreamFrontier
            && $right instanceof OkxPaperStreamFrontier
            && hash_equals(
                CanonicalJson::encode($left->toArray()),
                CanonicalJson::encode($right->toArray()),
            );
    }

    private static function isTransport(?string $stream): bool
    {
        return $stream === 'public' || $stream === 'business';
    }

    private static function isSymbolBookStream(?string $symbol, ?string $stream): bool
    {
        return $symbol !== null
            && \in_array($symbol, self::SYMBOLS, true)
            && $stream === $symbol . '/ws/top_of_book';
    }

    private static function isSymbolControlStream(?string $symbol, ?string $stream, string $channel): bool
    {
        return $symbol !== null
            && \in_array($symbol, self::SYMBOLS, true)
            && $stream === $symbol . '/control/' . $channel;
    }

    /**
     * @param array<array-key, mixed> $symbols
     * @return list<string>
     */
    private static function orderedSymbols(array $symbols): array
    {
        if (!array_is_list($symbols)) {
            throw new \InvalidArgumentException();
        }
        $last = -1;
        foreach ($symbols as $symbol) {
            if (!\is_string($symbol)) {
                throw new \InvalidArgumentException();
            }
            $position = array_search($symbol, self::SYMBOLS, true);
            if (!\is_int($position) || $position <= $last) {
                throw new \InvalidArgumentException();
            }
            $last = $position;
        }

        return $symbols;
    }

    /**
     * @param array<array-key, mixed> $boundaries
     * @return list<array{symbol: string, reason: string}>
     */
    private static function boundaries(array $boundaries): array
    {
        if (!array_is_list($boundaries)) {
            throw new \InvalidArgumentException();
        }
        $reasons = ['initial', 'reconnect', 'sequence_gap'];
        $last = -1;
        $validated = [];
        foreach ($boundaries as $boundary) {
            if (!\is_array($boundary) || array_is_list($boundary)) {
                throw new \InvalidArgumentException();
            }
            self::assertExactKeys($boundary, ['symbol', 'reason']);
            if (!\is_string($boundary['symbol']) || !\is_string($boundary['reason'])) {
                throw new \InvalidArgumentException();
            }
            $symbolPosition = array_search($boundary['symbol'], self::SYMBOLS, true);
            $reasonPosition = array_search($boundary['reason'], $reasons, true);
            if (!\is_int($symbolPosition) || !\is_int($reasonPosition)) {
                throw new \InvalidArgumentException();
            }
            $position = $symbolPosition * \count($reasons) + $reasonPosition;
            if ($position <= $last) {
                throw new \InvalidArgumentException();
            }
            $last = $position;
            $validated[] = ['symbol' => $boundary['symbol'], 'reason' => $boundary['reason']];
        }

        return $validated;
    }

    /**
     * @param array<string, mixed> $resyncBySymbol
     * @return array{public: list<string>, business: list<string>}
     */
    private static function legacyResyncStreamingQueues(array $resyncBySymbol): array
    {
        $legacy = null;
        foreach ($resyncBySymbol as $resync) {
            if (!\is_array($resync)
                || !array_key_exists('queued_public_frames', $resync)
                || !array_key_exists('queued_business_frames', $resync)
            ) {
                continue;
            }
            $candidate = [
                'public' => $resync['queued_public_frames'],
                'business' => $resync['queued_business_frames'],
            ];
            if ($legacy !== null && $legacy !== $candidate) {
                throw new \InvalidArgumentException();
            }
            $legacy = $candidate;
        }

        return $legacy ?? ['public' => [], 'business' => []];
    }

    /**
     * @param array<string, mixed> $resyncBySymbol
     * @param array{public: list<string>, business: list<string>} $streamingQueues
     * @param array<string, mixed>|null $streamingQueueRef
     */
    private static function assertResyncQueueMirrorsMatchStreamingQueues(
        array $resyncBySymbol,
        array $streamingQueues,
        ?array $streamingQueueRef,
    ): void {
        foreach ($resyncBySymbol as $resync) {
            if (!\is_array($resync)
                || !array_key_exists('queued_public_frames', $resync)
                || !array_key_exists('queued_business_frames', $resync)
            ) {
                continue;
            }
            if ($streamingQueueRef !== null
                || $resync['queued_public_frames'] !== $streamingQueues['public']
                || $resync['queued_business_frames'] !== $streamingQueues['business']
            ) {
                throw new \InvalidArgumentException();
            }
        }
    }

    /**
     * @param array<string, mixed> $healthyStop
     * @return array{liveness_proven: bool, remaining_symbols: list<string>, requested: bool}
     */
    private static function healthyStop(array $healthyStop): array
    {
        self::assertExactKeys($healthyStop, [
            'liveness_proven',
            'remaining_symbols',
            'requested',
        ]);
        if (!\is_bool($healthyStop['liveness_proven'])
            || !\is_bool($healthyStop['requested'])
            || !\is_array($healthyStop['remaining_symbols'])
        ) {
            throw new \InvalidArgumentException();
        }

        return [
            'liveness_proven' => $healthyStop['liveness_proven'],
            'remaining_symbols' => self::orderedSymbols($healthyStop['remaining_symbols']),
            'requested' => $healthyStop['requested'],
        ];
    }

    /**
     * @param array<string, mixed> $reconnect
     * @return array{attempt: int, deadline_at: string|null, stable_since: string|null, accepted_events: int}
     */
    private static function reconnect(array $reconnect): array
    {
        self::assertExactKeys($reconnect, ['attempt', 'deadline_at', 'stable_since', 'accepted_events']);
        if (!\is_int($reconnect['attempt'])
            || $reconnect['attempt'] < 0
            || $reconnect['attempt'] > \count(OkxPaperLivePolicy::RECONNECT_DELAYS_SECONDS)
            || !\is_int($reconnect['accepted_events'])
            || $reconnect['accepted_events'] < 0
            || $reconnect['accepted_events'] > OkxPaperLivePolicy::RECONNECT_STABLE_ACCEPTED_EVENTS
        ) {
            throw new \InvalidArgumentException();
        }
        $deadline = self::optionalUtc($reconnect['deadline_at']);
        $stableSince = self::optionalUtc($reconnect['stable_since']);
        if ($deadline !== null && $stableSince !== null) {
            throw new \InvalidArgumentException();
        }
        if ($reconnect['attempt'] === 0
            && ($deadline !== null || $stableSince !== null || $reconnect['accepted_events'] !== 0)
        ) {
            throw new \InvalidArgumentException();
        }
        if ($reconnect['attempt'] > 0 && $deadline === null && $stableSince === null) {
            throw new \InvalidArgumentException();
        }
        if ($deadline !== null && $reconnect['accepted_events'] !== 0) {
            throw new \InvalidArgumentException();
        }

        return [
            'attempt' => $reconnect['attempt'],
            'deadline_at' => $deadline,
            'stable_since' => $stableSince,
            'accepted_events' => $reconnect['accepted_events'],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function resync(mixed $resync, string $symbol): ?array
    {
        if ($resync === null) {
            return null;
        }
        if (!\is_array($resync) || array_is_list($resync)) {
            throw new \InvalidArgumentException();
        }
        $keys = ['attempt', 'frontier', 'source_sequence', 'deadline_at', 'policy'];
        $hasBookSnapshot = array_key_exists('book_snapshot', $resync);
        $hasPublicQueueMirror = array_key_exists('queued_public_frames', $resync);
        $hasBusinessQueueMirror = array_key_exists('queued_business_frames', $resync);
        if ($hasPublicQueueMirror !== $hasBusinessQueueMirror
            || ($hasPublicQueueMirror && !$hasBookSnapshot)
        ) {
            throw new \InvalidArgumentException();
        }
        if ($hasBookSnapshot) {
            $keys[] = 'book_snapshot';
        }
        if ($hasPublicQueueMirror) {
            $keys[] = 'queued_public_frames';
            $keys[] = 'queued_business_frames';
        }
        self::assertExactKeys($resync, $keys);
        if (!\is_int($resync['attempt'])
            || $resync['attempt'] < 1
            || $resync['attempt'] > OkxPaperLivePolicy::MAX_RESYNC_ATTEMPTS
            || !\is_array($resync['frontier'])
            || array_is_list($resync['frontier'])
            || ($resync['source_sequence'] !== null && !\is_string($resync['source_sequence']))
            || !\is_string($resync['policy'])
        ) {
            throw new \InvalidArgumentException();
        }
        $frontier = OkxPaperStreamFrontier::fromArray($resync['frontier']);
        $channel = self::frontierChannel($frontier, $symbol);
        $sourceSequence = $resync['source_sequence'];
        if ($resync['policy'] === 'book_seq_overlap_v1') {
            if ($channel !== 'top_of_book'
                || !\is_string($sourceSequence)
                || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $sourceSequence) !== 1
                || ($hasBookSnapshot
                    && $resync['book_snapshot'] !== null
                    && !\is_array($resync['book_snapshot']))
            ) {
                throw new \InvalidArgumentException();
            }
        } elseif ($resync['policy'] === 'frontier_overlap_v1') {
            if ((!str_starts_with($channel, 'candle_') && $channel !== 'public_trade')
                || $sourceSequence !== null
                || $hasBookSnapshot
                || $hasPublicQueueMirror
            ) {
                throw new \InvalidArgumentException();
            }
        } else {
            throw new \InvalidArgumentException();
        }

        $validated = [
            'attempt' => $resync['attempt'],
            'frontier' => $frontier,
            'source_sequence' => $sourceSequence,
            'deadline_at' => self::requiredUtc($resync['deadline_at']),
            'policy' => $resync['policy'],
        ];
        if ($hasBookSnapshot) {
            $validated['book_snapshot'] = $resync['book_snapshot'];
        }
        if ($hasPublicQueueMirror) {
            foreach (['queued_public_frames', 'queued_business_frames'] as $field) {
                if (!array_is_list($resync[$field])
                    || \count($resync[$field]) > OkxPaperLivePolicy::MAX_QUEUED_FRAMES
                ) {
                    throw new \InvalidArgumentException();
                }
                $bytes = 0;
                foreach ($resync[$field] as $frame) {
                    if (!\is_string($frame)) {
                        throw new \InvalidArgumentException();
                    }
                    $bytes += \strlen($frame);
                }
                if ($bytes > OkxPaperLivePolicy::MAX_QUEUED_BYTES) {
                    throw new \InvalidArgumentException();
                }
            }
            $validated['queued_public_frames'] = $resync['queued_public_frames'];
            $validated['queued_business_frames'] = $resync['queued_business_frames'];
        }

        return $validated;
    }

    /** @return array<string, mixed>|null */
    private static function pagination(mixed $pagination, string $stream): ?array
    {
        if ($pagination === null) {
            return null;
        }
        if (!\is_array($pagination) || array_is_list($pagination)) {
            throw new \InvalidArgumentException();
        }
        $expectedKeys = [
            'endpoint',
            'pagination_type',
            'next_cursor',
            'pages_consumed',
            'pages_remaining',
            'target_frontier',
            'deadline_at',
        ];
        $hasRetainedRows = array_key_exists('retained_rows', $pagination);
        if ($hasRetainedRows) {
            $expectedKeys[] = 'retained_rows';
        }
        self::assertExactKeys($pagination, $expectedKeys);
        if (!\is_string($pagination['endpoint'])
            || ($pagination['pagination_type'] !== null && !\is_int($pagination['pagination_type']))
            || ($pagination['next_cursor'] !== null && !\is_string($pagination['next_cursor']))
            || !\is_int($pagination['pages_consumed'])
            || !\is_int($pagination['pages_remaining'])
            || !\is_array($pagination['target_frontier'])
            || array_is_list($pagination['target_frontier'])
            || $pagination['pages_consumed'] < 0
            || $pagination['pages_consumed'] > OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES
            || $pagination['pages_remaining'] < 0
            || !\in_array(
                $pagination['pages_consumed'] + $pagination['pages_remaining'],
                [
                    OkxPaperLivePolicy::LEGACY_MAX_OVERLAP_HISTORY_PAGES,
                    OkxPaperLivePolicy::MAX_OVERLAP_HISTORY_PAGES,
                ],
                true,
            )
            || ($hasRetainedRows && !\is_array($pagination['retained_rows']))
        ) {
            throw new \InvalidArgumentException();
        }
        $frontier = OkxPaperStreamFrontier::fromArray($pagination['target_frontier']);
        self::assertFrontierMatchesStream($frontier, $stream);
        $isTrade = str_ends_with($stream, '/public_trade');
        if ($isTrade) {
            if ($pagination['endpoint'] !== 'history_trades'
                || !\in_array($pagination['pagination_type'], [1, 2], true)
            ) {
                throw new \InvalidArgumentException();
            }
            if ($pagination['pagination_type'] === 2 && $pagination['pages_consumed'] !== 0) {
                throw new \InvalidArgumentException();
            }
            if ($pagination['pagination_type'] === 2
                && (!\is_string($pagination['next_cursor'])
                    || preg_match('/\A[1-9][0-9]{12}\z/D', $pagination['next_cursor']) !== 1)
            ) {
                throw new \InvalidArgumentException();
            }
            if ($pagination['pagination_type'] === 1
                && ($pagination['pages_consumed'] < 1 || $pagination['next_cursor'] === null)
            ) {
                throw new \InvalidArgumentException();
            }
        } elseif ($pagination['endpoint'] !== 'history_candles' || $pagination['pagination_type'] !== null) {
            throw new \InvalidArgumentException();
        }
        if ($pagination['next_cursor'] !== null
            && (\strlen($pagination['next_cursor']) > 128
                || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $pagination['next_cursor']) !== 1)
        ) {
            throw new \InvalidArgumentException();
        }
        if (!$isTrade
            && $pagination['next_cursor'] !== null
            && preg_match('/\A[1-9][0-9]{12}\z/D', $pagination['next_cursor']) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        $validated = [
            'endpoint' => $pagination['endpoint'],
            'pagination_type' => $pagination['pagination_type'],
            'next_cursor' => $pagination['next_cursor'],
            'pages_consumed' => $pagination['pages_consumed'],
            'pages_remaining' => $pagination['pages_remaining'],
            'target_frontier' => $frontier,
            'deadline_at' => self::requiredUtc($pagination['deadline_at']),
        ];
        if ($hasRetainedRows) {
            if (!array_is_list($pagination['retained_rows'])
                || \count($pagination['retained_rows'])
                    > OkxPaperLivePolicy::MAX_RETAINED_RECOVERY_ROWS
            ) {
                throw new \InvalidArgumentException();
            }
            foreach ($pagination['retained_rows'] as $row) {
                if ($isTrade) {
                    if (!\is_array($row) && !\is_string($row)) {
                        throw new \InvalidArgumentException();
                    }
                    OkxPaperRetainedTradeRow::expand($row);

                    continue;
                }
                if (!\is_array($row) && !\is_string($row)) {
                    throw new \InvalidArgumentException();
                }
                OkxPaperRetainedCandleRow::expand($row);
            }
            $validated['retained_rows'] = $pagination['retained_rows'];
        }

        return $validated;
    }

    private static function pendingEvent(mixed $pendingEvent): ?PaperMarketEvent
    {
        if ($pendingEvent === null) {
            return null;
        }
        if (!\is_array($pendingEvent) || array_is_list($pendingEvent)) {
            throw new \InvalidArgumentException();
        }
        $event = PaperMarketEvent::fromArray($pendingEvent);
        if ($event->sourceVenue->value !== 'okx' || !\in_array($event->symbol, self::SYMBOLS, true)) {
            throw new \InvalidArgumentException();
        }

        return $event;
    }

    /** @param array<string, mixed> $ordinalState */
    private static function assertOrdinalContainsPendingEvent(
        #[\SensitiveParameter] array $ordinalState,
        #[\SensitiveParameter] ?PaperMarketEvent $pendingEvent,
    ): void {
        if ($pendingEvent === null) {
            return;
        }
        $scope = implode('/', [
            $pendingEvent->sourceVenue->value,
            $pendingEvent->symbol,
            $pendingEvent->channel->value,
        ]);
        $latestEvent = $ordinalState['scopes'][$scope]['latest']['event'] ?? null;
        if (!\is_array($latestEvent)
            || CanonicalJson::encode($latestEvent) !== CanonicalJson::encode($pendingEvent->toArray())
        ) {
            throw new \InvalidArgumentException();
        }
    }

    /** @return array{stream: string, frontier: OkxPaperStreamFrontier}|null */
    private static function pendingFrontier(mixed $pendingFrontier, ?PaperMarketEvent $pendingEvent): ?array
    {
        if ($pendingFrontier === null) {
            return null;
        }
        if ($pendingEvent === null || !\is_array($pendingFrontier) || array_is_list($pendingFrontier)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys($pendingFrontier, ['stream', 'frontier']);
        if (!\is_string($pendingFrontier['stream'])
            || !\in_array($pendingFrontier['stream'], self::streamKeys(), true)
            || !\is_array($pendingFrontier['frontier'])
            || array_is_list($pendingFrontier['frontier'])
        ) {
            throw new \InvalidArgumentException();
        }
        $frontier = OkxPaperStreamFrontier::fromArray($pendingFrontier['frontier']);
        self::assertFrontierMatchesStream($frontier, $pendingFrontier['stream']);
        if ($frontier->toArray() !== OkxPaperStreamFrontier::fromEvent($pendingEvent)->toArray()) {
            throw new \InvalidArgumentException();
        }

        return ['stream' => $pendingFrontier['stream'], 'frontier' => $frontier];
    }

    private static function assertFrontierMatchesStream(OkxPaperStreamFrontier $frontier, string $stream): void
    {
        if (!\in_array($stream, self::streamKeys(), true)) {
            throw new \InvalidArgumentException();
        }
        [$symbol, , $streamChannel] = explode('/', $stream, 3);
        $expectedChannel = $streamChannel === 'candle_1H' ? 'candle_1h' : $streamChannel;
        $channel = self::frontierChannel($frontier, $symbol);
        if ($channel !== $expectedChannel || !self::validSourceIdentity($channel, $frontier->sourceIdentity)) {
            throw new \InvalidArgumentException();
        }
    }

    private static function validSourceIdentity(string $channel, string $sourceIdentity): bool
    {
        if ($channel === 'public_trade' || $channel === 'top_of_book') {
            return \strlen($sourceIdentity) <= 128
                && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $sourceIdentity) === 1;
        }
        if (str_starts_with($channel, 'candle_')) {
            $bar = substr($channel, \strlen('candle_'));

            return preg_match('/\A' . preg_quote($bar, '/') . '\|[1-9][0-9]{12}\z/D', $sourceIdentity) === 1;
        }
        if ($channel === 'connection_state') {
            return preg_match('/\A[1-9][0-9]*\|(connected|subscribed|reconnecting|stopped)\z/D', $sourceIdentity) === 1;
        }
        if ($channel === 'instrument_metadata') {
            return preg_match('/\A[1-9][0-9]*\|[a-f0-9]{64}\z/D', $sourceIdentity) === 1;
        }
        if ($channel === 'funding_rate') {
            return preg_match('/\A[1-9][0-9]*\|[1-9][0-9]{12}\|[1-9][0-9]{12}\z/D', $sourceIdentity) === 1;
        }
        if ($channel === 'snapshot_boundary') {
            return preg_match(
                '/\A[1-9][0-9]*\|(?:0|[1-9][0-9]*)\|(initial|reconnect|sequence_gap)\z/D',
                $sourceIdentity,
            ) === 1;
        }

        return false;
    }

    private static function frontierChannel(OkxPaperStreamFrontier $frontier, string $symbol): string
    {
        $parts = explode('|', $frontier->naturalIdentity, 4);
        $native = $symbol === 'BTCUSDT' ? 'BTC-USDT-SWAP' : 'ETH-USDT-SWAP';
        if (\count($parts) !== 4
            || $parts[0] !== 'okx'
            || $parts[1] !== $native
            || $parts[3] !== $frontier->sourceIdentity
        ) {
            throw new \InvalidArgumentException();
        }

        return $parts[2];
    }

    private static function optionalUtc(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return self::requiredUtc($timestamp);
    }

    private static function requiredUtc(mixed $timestamp): string
    {
        if (!\is_string($timestamp)) {
            throw new \InvalidArgumentException();
        }
        $parsed = \DateTimeImmutable::createFromFormat(
            '!' . self::TIMESTAMP_FORMAT,
            $timestamp,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($parsed === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format(self::TIMESTAMP_FORMAT) !== $timestamp
        ) {
            throw new \InvalidArgumentException();
        }

        return $timestamp;
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $expected
     */
    private static function assertExactMapKeys(array $value, array $expected): void
    {
        self::assertExactKeys($value, $expected);
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $expected
     */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException();
        }
    }
}
