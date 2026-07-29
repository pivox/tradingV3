<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Dataset\PaperDatasetManifest;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidCandle;
use App\Trading\Paper\Hyperliquid\Normalization\HyperliquidPaperSourceOrdinal;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketEvent;

final readonly class HyperliquidPaperLiveCheckpoint
{
    public const SCHEMA_VERSION = 2;
    public const POLICY_VERSION = 1;
    public const MAXIMUM_BYTES = 1_048_576;
    public const MAXIMUM_ACKNOWLEDGED_IDENTITIES = 4_096;
    public const MAXIMUM_TRADE_IDENTITIES = 4_096;

    /** @var list<string> */
    private const PHASES = [
        'fresh',
        'connecting',
        'subscribing',
        'streaming',
        'reconnecting',
        'stopping',
        'complete',
        'failed',
    ];

    /**
     * @param list<array{method: string, subscription: array<string, string>}> $subscriptions
     * @param array<string, mixed> $ordinalState
     * @param array<string, mixed>|null $pendingContinuation
     * @param array<string, array<string, mixed>> $currentCandles
     * @param array<string, int> $finalizedCandleFrontiers
     * @param list<string> $acknowledgedIdentities
     * @param list<array{identity_hash: string, assignment_digest: string}> $tradeIdentityHistory
     * @param array{last_received_at: string|null, last_ping_at: string|null, pong_deadline_at: string|null} $heartbeat
     * @param array{requested: bool} $healthyStop
     */
    private function __construct(
        public int $schemaVersion,
        public int $policyVersion,
        public string $datasetId,
        public PaperMarketDataNetwork $network,
        public string $configurationSha256,
        public string $phase,
        public ?string $failureReason,
        public bool $continuity,
        public int $connectionEpoch,
        public int $sourceEpoch,
        public array $subscriptions,
        public array $ordinalState,
        public ?PaperMarketEvent $pendingEvent,
        public ?array $pendingContinuation,
        public array $currentCandles,
        public array $finalizedCandleFrontiers,
        public array $acknowledgedIdentities,
        public array $tradeIdentityHistory,
        public int $reconnectAttempt,
        public array $heartbeat,
        public array $healthyStop,
    ) {
    }

    public static function fresh(
        string $datasetId,
        PaperMarketDataNetwork $network,
        string $configurationSha256,
    ): self {
        return self::fromArray([
            'schema_version' => self::SCHEMA_VERSION,
            'policy_version' => self::POLICY_VERSION,
            'dataset_id' => $datasetId,
            'network' => $network->value,
            'configuration_sha256' => $configurationSha256,
            'phase' => 'fresh',
            'failure_reason' => null,
            'continuity' => true,
            'connection_epoch' => 1,
            'source_epoch' => 1,
            'subscriptions' => (new HyperliquidPaperPublicSubscriptionSet())->subscriptions(),
            'ordinal_state' => (new HyperliquidPaperSourceOrdinal())->snapshot(),
            'pending_event' => null,
            'pending_continuation' => null,
            'current_candles' => [],
            'finalized_candle_frontiers' => [],
            'acknowledged_identities' => [],
            'trade_identity_history' => [],
            'reconnect_attempt' => 0,
            'heartbeat' => [
                'last_received_at' => null,
                'last_ping_at' => null,
                'pong_deadline_at' => null,
            ],
            'healthy_stop' => ['requested' => false],
        ]);
    }

    /** @param array<string, mixed> $state */
    public static function fromArray(#[\SensitiveParameter] array $state): self
    {
        try {
            self::assertExactKeys($state, [
                'schema_version',
                'policy_version',
                'dataset_id',
                'network',
                'configuration_sha256',
                'phase',
                'failure_reason',
                'continuity',
                'connection_epoch',
                'source_epoch',
                'subscriptions',
                'ordinal_state',
                'pending_event',
                'pending_continuation',
                'current_candles',
                'finalized_candle_frontiers',
                'acknowledged_identities',
                'trade_identity_history',
                'reconnect_attempt',
                'heartbeat',
                'healthy_stop',
            ]);
            if ($state['schema_version'] !== self::SCHEMA_VERSION
                || $state['policy_version'] !== self::POLICY_VERSION
                || !\is_string($state['dataset_id'])
                || !\is_string($state['network'])
                || !\is_string($state['configuration_sha256'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $state['configuration_sha256']) !== 1
                || !\is_string($state['phase'])
                || !\in_array($state['phase'], self::PHASES, true)
                || !\is_bool($state['continuity'])
                || !\is_int($state['connection_epoch'])
                || $state['connection_epoch'] < 1
                || !\is_int($state['source_epoch'])
                || $state['source_epoch'] < 1
                || !\is_array($state['subscriptions'])
                || !\is_array($state['ordinal_state'])
                || !\is_array($state['current_candles'])
                || !\is_array($state['finalized_candle_frontiers'])
                || !\is_array($state['acknowledged_identities'])
                || !\is_array($state['trade_identity_history'])
                || !\is_int($state['reconnect_attempt'])
                || $state['reconnect_attempt'] < 0
                || $state['reconnect_attempt'] > 6
                || !\is_array($state['heartbeat'])
                || !\is_array($state['healthy_stop'])
            ) {
                throw new \InvalidArgumentException();
            }
            PaperDatasetManifest::assertDatasetId($state['dataset_id']);
            $network = PaperMarketDataNetwork::tryFrom($state['network']);
            if ($network === null || !$network->isCertifiable()) {
                throw new \InvalidArgumentException();
            }
            $failureReason = self::failureReason($state['failure_reason']);
            if (($state['phase'] === 'failed' && $failureReason === null)
                || ($failureReason !== null
                    && $state['phase'] !== 'failed'
                    && $state['continuity'])
            ) {
                throw new \InvalidArgumentException();
            }

            $subscriptions = self::subscriptions($state['subscriptions']);
            $ordinalState = HyperliquidPaperSourceOrdinal::restore(
                $state['ordinal_state'],
            )->snapshot();
            $pendingEvent = self::pendingEvent($state['pending_event'], $network);
            $pendingContinuation = self::pendingContinuation(
                $state['pending_continuation'],
            );
            if (($pendingEvent === null) !== ($pendingContinuation === null)) {
                throw new \InvalidArgumentException();
            }
            $currentCandles = self::currentCandles($state['current_candles']);
            $frontiers = self::frontiers($state['finalized_candle_frontiers']);
            $acknowledged = self::acknowledged($state['acknowledged_identities']);
            $tradeIdentityHistory = self::tradeIdentityHistory(
                $state['trade_identity_history'],
            );
            if ($pendingEvent !== null
                && \in_array($pendingEvent->eventId, $acknowledged, true)
            ) {
                throw new \InvalidArgumentException();
            }
            $heartbeat = self::heartbeat($state['heartbeat']);
            $healthyStop = self::healthyStop($state['healthy_stop']);
            if ($state['phase'] === 'complete'
                && (!$healthyStop['requested']
                    || $pendingEvent !== null
                    || $currentCandles !== []
                    || !$state['continuity'])
            ) {
                throw new \InvalidArgumentException();
            }

            $checkpoint = new self(
                schemaVersion: self::SCHEMA_VERSION,
                policyVersion: self::POLICY_VERSION,
                datasetId: $state['dataset_id'],
                network: $network,
                configurationSha256: $state['configuration_sha256'],
                phase: $state['phase'],
                failureReason: $failureReason,
                continuity: $state['continuity'],
                connectionEpoch: $state['connection_epoch'],
                sourceEpoch: $state['source_epoch'],
                subscriptions: $subscriptions,
                ordinalState: $ordinalState,
                pendingEvent: $pendingEvent,
                pendingContinuation: $pendingContinuation,
                currentCandles: $currentCandles,
                finalizedCandleFrontiers: $frontiers,
                acknowledgedIdentities: $acknowledged,
                tradeIdentityHistory: $tradeIdentityHistory,
                reconnectAttempt: $state['reconnect_attempt'],
                heartbeat: $heartbeat,
                healthyStop: $healthyStop,
            );
            if (\strlen(CanonicalJson::encode($checkpoint->toArray())) > self::MAXIMUM_BYTES) {
                throw new \InvalidArgumentException();
            }

            return $checkpoint;
        } catch (\Throwable) {
            throw self::invalid();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'policy_version' => $this->policyVersion,
            'dataset_id' => $this->datasetId,
            'network' => $this->network->value,
            'configuration_sha256' => $this->configurationSha256,
            'phase' => $this->phase,
            'failure_reason' => $this->failureReason,
            'continuity' => $this->continuity,
            'connection_epoch' => $this->connectionEpoch,
            'source_epoch' => $this->sourceEpoch,
            'subscriptions' => $this->subscriptions,
            'ordinal_state' => $this->ordinalState,
            'pending_event' => $this->pendingEvent?->toArray(),
            'pending_continuation' => $this->pendingContinuation,
            'current_candles' => $this->currentCandles,
            'finalized_candle_frontiers' => $this->finalizedCandleFrontiers,
            'acknowledged_identities' => $this->acknowledgedIdentities,
            'trade_identity_history' => $this->tradeIdentityHistory,
            'reconnect_attempt' => $this->reconnectAttempt,
            'heartbeat' => $this->heartbeat,
            'healthy_stop' => $this->healthyStop,
        ];
    }

    /** @param array<string, mixed> $continuation */
    public function withPending(
        PaperMarketEvent $event,
        #[\SensitiveParameter] array $continuation,
    ): self {
        if ($this->pendingEvent !== null) {
            if ($this->pendingEvent->toArray() === $event->toArray()
                && $this->pendingContinuation === $continuation
            ) {
                return $this;
            }

            throw self::invalid();
        }
        if ($event->sourceNetwork !== $this->network) {
            throw self::invalid();
        }

        return $this->with([
            'pending_event' => $event->toArray(),
            'pending_continuation' => $continuation,
        ]);
    }

    public function acknowledge(string $eventId): self
    {
        if ($this->pendingEvent === null
            || !hash_equals($this->pendingEvent->eventId, $eventId)
        ) {
            throw self::invalid();
        }
        $acknowledged = $this->acknowledgedIdentities;
        if (!\in_array($eventId, $acknowledged, true)) {
            $acknowledged[] = $eventId;
        }
        if (\count($acknowledged) > self::MAXIMUM_ACKNOWLEDGED_IDENTITIES) {
            array_shift($acknowledged);
        }

        return $this->with([
            'pending_event' => null,
            'pending_continuation' => null,
            'acknowledged_identities' => $acknowledged,
        ]);
    }

    public function rememberTradeIdentity(
        string $identityHash,
        string $assignmentDigest,
    ): self {
        self::assertSha256($identityHash);
        self::assertSha256($assignmentDigest);
        $history = $this->tradeIdentityHistory;
        foreach ($history as $entry) {
            if (!hash_equals($entry['identity_hash'], $identityHash)) {
                continue;
            }
            if (!hash_equals($entry['assignment_digest'], $assignmentDigest)) {
                throw new \RuntimeException(
                    'hyperliquid_paper_natural_identity_conflict',
                );
            }

            return $this;
        }
        $history[] = [
            'identity_hash' => $identityHash,
            'assignment_digest' => $assignmentDigest,
        ];
        if (\count($history) > self::MAXIMUM_TRADE_IDENTITIES) {
            array_shift($history);
        }

        return $this->with(['trade_identity_history' => $history]);
    }

    /** @param array<string, mixed> $candle */
    public function withCurrentCandle(
        string $stream,
        #[\SensitiveParameter] array $candle,
    ): self {
        [$coin, $interval] = self::stream($stream);
        $parsed = HyperliquidCandle::fromApiRow($candle, $coin, $interval);
        $current = $this->currentCandles[$stream] ?? null;
        if ($current !== null) {
            $previous = HyperliquidCandle::fromApiRow($current, $coin, $interval);
            if ($parsed->startTime < $previous->startTime) {
                throw self::invalid();
            }
        }
        $candles = $this->currentCandles;
        $candles[$stream] = $candle;
        ksort($candles, \SORT_STRING);

        return $this->with(['current_candles' => $candles]);
    }

    public function finalizeCandle(string $stream, int $startTime): self
    {
        self::stream($stream);
        if ($startTime < 0
            || (($this->finalizedCandleFrontiers[$stream] ?? -1) > $startTime)
        ) {
            throw self::invalid();
        }
        $frontiers = $this->finalizedCandleFrontiers;
        $frontiers[$stream] = $startTime;
        ksort($frontiers, \SORT_STRING);
        $candles = $this->currentCandles;
        if (isset($candles[$stream])
            && ($candles[$stream]['t'] ?? null) === $startTime
        ) {
            unset($candles[$stream]);
        }

        return $this->with([
            'current_candles' => $candles,
            'finalized_candle_frontiers' => $frontiers,
        ]);
    }

    public function loseContinuity(string $reason): self
    {
        $reason = self::failureReason($reason)
            ?? throw self::invalid();

        return $this->with([
            'continuity' => false,
            'failure_reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $ordinalState */
    public function withOrdinalState(array $ordinalState): self
    {
        return $this->with(['ordinal_state' => $ordinalState]);
    }

    public function withPhase(string $phase): self
    {
        return $this->with([
            'phase' => $phase,
            'failure_reason' => $phase === 'failed'
                ? ($this->failureReason ?? 'hyperliquid_paper_public_protocol_error')
                : $this->failureReason,
        ]);
    }

    public function fail(string $reason): self
    {
        $reason = self::failureReason($reason)
            ?? throw self::invalid();

        return $this->with([
            'phase' => 'failed',
            'failure_reason' => $reason,
            'pending_event' => null,
            'pending_continuation' => null,
        ]);
    }

    public function requestHealthyStop(): self
    {
        return $this->with([
            'phase' => 'stopping',
            'healthy_stop' => ['requested' => true],
        ]);
    }

    public function completeHealthyStop(): self
    {
        return $this->with([
            'phase' => 'complete',
            'current_candles' => [],
        ]);
    }

    public function beginReconnect(string $reason): self
    {
        $reason = self::failureReason($reason)
            ?? throw self::invalid();
        if ($this->reconnectAttempt >= \count(
            HyperliquidPaperLivePolicy::RECONNECT_DELAYS_SECONDS,
        )) {
            return $this->fail('hyperliquid_paper_public_reconnect_exhausted');
        }

        return $this->with([
            'phase' => 'reconnecting',
            'failure_reason' => $reason,
            'continuity' => false,
            'connection_epoch' => $this->connectionEpoch + 1,
            'source_epoch' => $this->sourceEpoch + 1,
            'reconnect_attempt' => $this->reconnectAttempt + 1,
            'heartbeat' => [
                'last_received_at' => null,
                'last_ping_at' => null,
                'pong_deadline_at' => null,
            ],
        ]);
    }

    public function withHeartbeat(
        ?string $lastReceivedAt,
        ?string $lastPingAt,
        ?string $pongDeadlineAt,
    ): self {
        return $this->with([
            'heartbeat' => [
                'last_received_at' => $lastReceivedAt,
                'last_ping_at' => $lastPingAt,
                'pong_deadline_at' => $pongDeadlineAt,
            ],
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function with(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }

    /** @return list<array{method: string, subscription: array<string, string>}> */
    private static function subscriptions(mixed $value): array
    {
        $expected = (new HyperliquidPaperPublicSubscriptionSet())->subscriptions();
        if (!\is_array($value)
            || CanonicalJson::encode($value) !== CanonicalJson::encode($expected)
        ) {
            throw new \InvalidArgumentException();
        }

        return $expected;
    }

    private static function pendingEvent(
        mixed $value,
        PaperMarketDataNetwork $network,
    ): ?PaperMarketEvent {
        if ($value === null) {
            return null;
        }
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException();
        }
        $event = PaperMarketEvent::fromArray($value);
        if ($event->sourceNetwork !== $network) {
            throw new \InvalidArgumentException();
        }

        return $event;
    }

    /** @return array<string, mixed>|null */
    private static function pendingContinuation(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!\is_array($value) || array_is_list($value) || \count($value) > 32) {
            throw new \InvalidArgumentException();
        }
        CanonicalJson::encode($value);

        return $value;
    }

    /** @return array<string, array<string, mixed>> */
    private static function currentCandles(mixed $value): array
    {
        if (!\is_array($value) || (array_is_list($value) && $value !== []) || \count($value) > 8) {
            throw new \InvalidArgumentException();
        }
        $result = [];
        foreach ($value as $stream => $row) {
            if (!\is_string($stream) || !\is_array($row) || array_is_list($row)) {
                throw new \InvalidArgumentException();
            }
            [$coin, $interval] = self::stream($stream);
            HyperliquidCandle::fromApiRow($row, $coin, $interval);
            $result[$stream] = $row;
        }
        ksort($result, \SORT_STRING);

        return $result;
    }

    /** @return array<string, int> */
    private static function frontiers(mixed $value): array
    {
        if (!\is_array($value) || (array_is_list($value) && $value !== []) || \count($value) > 8) {
            throw new \InvalidArgumentException();
        }
        $result = [];
        foreach ($value as $stream => $frontier) {
            if (!\is_string($stream) || !\is_int($frontier) || $frontier < 0) {
                throw new \InvalidArgumentException();
            }
            self::stream($stream);
            $result[$stream] = $frontier;
        }
        ksort($result, \SORT_STRING);

        return $result;
    }

    /** @return list<string> */
    private static function acknowledged(mixed $value): array
    {
        if (!\is_array($value)
            || !array_is_list($value)
            || \count($value) > self::MAXIMUM_ACKNOWLEDGED_IDENTITIES
        ) {
            throw new \InvalidArgumentException();
        }
        $unique = [];
        foreach ($value as $eventId) {
            if (!\is_string($eventId)
                || preg_match('/\A[a-f0-9]{64}\z/D', $eventId) !== 1
                || isset($unique[$eventId])
            ) {
                throw new \InvalidArgumentException();
            }
            $unique[$eventId] = true;
        }

        return array_keys($unique);
    }

    /** @return list<array{identity_hash: string, assignment_digest: string}> */
    private static function tradeIdentityHistory(mixed $value): array
    {
        if (!\is_array($value)
            || !array_is_list($value)
            || \count($value) > self::MAXIMUM_TRADE_IDENTITIES
        ) {
            throw new \InvalidArgumentException();
        }
        $history = [];
        $identities = [];
        foreach ($value as $entry) {
            if (!\is_array($entry) || array_is_list($entry)) {
                throw new \InvalidArgumentException();
            }
            self::assertExactKeys($entry, [
                'identity_hash',
                'assignment_digest',
            ]);
            $identityHash = $entry['identity_hash'] ?? null;
            $assignmentDigest = $entry['assignment_digest'] ?? null;
            if (!\is_string($identityHash)
                || !\is_string($assignmentDigest)
            ) {
                throw new \InvalidArgumentException();
            }
            self::assertSha256($identityHash);
            self::assertSha256($assignmentDigest);
            if (isset($identities[$identityHash])) {
                throw new \InvalidArgumentException();
            }
            $identities[$identityHash] = true;
            $history[] = [
                'identity_hash' => $identityHash,
                'assignment_digest' => $assignmentDigest,
            ];
        }

        return $history;
    }

    /** @return array{last_received_at: string|null, last_ping_at: string|null, pong_deadline_at: string|null} */
    private static function heartbeat(mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys($value, [
            'last_received_at',
            'last_ping_at',
            'pong_deadline_at',
        ]);
        foreach ($value as $timestamp) {
            if ($timestamp !== null
                && (!\is_string($timestamp)
                    || \DateTimeImmutable::createFromFormat(
                        '!Y-m-d\TH:i:s.u\Z',
                        $timestamp,
                        new \DateTimeZone('UTC'),
                    ) === false)
            ) {
                throw new \InvalidArgumentException();
            }
        }

        /** @var array{last_received_at: string|null, last_ping_at: string|null, pong_deadline_at: string|null} $value */
        return $value;
    }

    /** @return array{requested: bool} */
    private static function healthyStop(mixed $value): array
    {
        if (!\is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException();
        }
        self::assertExactKeys($value, ['requested']);
        if (!\is_bool($value['requested'] ?? null)) {
            throw new \InvalidArgumentException();
        }

        return ['requested' => $value['requested']];
    }

    /** @return array{string, string} */
    private static function stream(string $stream): array
    {
        if (preg_match('/\A(BTC|ETH)\/(1m|5m|15m|1h)\z/D', $stream, $matches) !== 1) {
            throw new \InvalidArgumentException();
        }

        return [$matches[1], $matches[2]];
    }

    private static function failureReason(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)
            || preg_match('/\A[a-z][a-z0-9_]{2,127}\z/D', $value) !== 1
        ) {
            throw new \InvalidArgumentException();
        }

        return $value;
    }

    /** @param array<string, mixed> $value
     *  @param list<string> $keys
     */
    private static function assertExactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, \SORT_STRING);
        sort($keys, \SORT_STRING);
        if ($actual !== $keys) {
            throw new \InvalidArgumentException();
        }
    }

    private static function assertSha256(string $value): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \InvalidArgumentException();
        }
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('hyperliquid_paper_live_checkpoint_invalid');
    }
}
