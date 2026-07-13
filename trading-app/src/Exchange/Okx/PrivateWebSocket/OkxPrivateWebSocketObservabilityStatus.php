<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class OkxPrivateWebSocketObservabilityStatus
{
    public const SCHEMA_VERSION = 1;
    public const EXCHANGE = 'okx';
    public const ENVIRONMENT = 'demo';
    public const ENDPOINT_ID = 'okx_demo_private_v1';

    /** @var list<string> */
    public const BLOCKING_ERROR_CODES = [
        'okx_private_ws_connection_failed',
        'okx_private_ws_authentication_failed',
        'okx_private_ws_subscription_failed',
        'okx_private_ws_message_invalid',
        'okx_private_rest_snapshot_failed',
        'okx_private_ws_worker_stopping',
    ];

    /** @var list<string> */
    public const WARNING_CODES = [
        'okx_fills_channel_vip_unavailable',
    ];

    private const MAX_CODES = 16;

    public readonly int $schemaVersion;
    public readonly string $exchange;
    public readonly string $environment;
    public readonly string $endpointId;

    /**
     * @param list<string> $blockingErrors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly bool $connected,
        public readonly bool $authenticated,
        public readonly bool $ordersStreamReady,
        public readonly bool $fillsStreamReady,
        public readonly ?string $fillsSource,
        public readonly bool $positionsStreamReady,
        public readonly bool $initialSnapshotLoaded,
        public readonly bool $reconciliationFresh,
        public readonly bool $reconnecting,
        public readonly ?DateTimeImmutable $connectedAt,
        public readonly DateTimeImmutable $lastHeartbeatAt,
        public readonly ?DateTimeImmutable $lastEventAt,
        public readonly DateTimeImmutable $observedAt,
        public readonly array $blockingErrors,
        public readonly array $warnings,
    ) {
        $fillsSourceValid = $fillsStreamReady
            ? in_array($fillsSource, ['fills_channel', 'orders_plus_rest'], true)
            : null === $fillsSource;
        if (!$fillsSourceValid) {
            throw new InvalidArgumentException('okx_private_ws_status_fills_source_invalid');
        }

        self::assertUtc($connectedAt);
        self::assertUtc($lastHeartbeatAt);
        self::assertUtc($lastEventAt);
        self::assertUtc($observedAt);
        self::assertCodes($blockingErrors, self::BLOCKING_ERROR_CODES);
        self::assertCodes($warnings, self::WARNING_CODES);

        $this->schemaVersion = self::SCHEMA_VERSION;
        $this->exchange = self::EXCHANGE;
        $this->environment = self::ENVIRONMENT;
        $this->endpointId = self::ENDPOINT_ID;
    }

    public static function connecting(DateTimeImmutable $now): self
    {
        return new self(
            connected: false,
            authenticated: false,
            ordersStreamReady: false,
            fillsStreamReady: false,
            fillsSource: null,
            positionsStreamReady: false,
            initialSnapshotLoaded: false,
            reconciliationFresh: false,
            reconnecting: true,
            connectedAt: null,
            lastHeartbeatAt: $now,
            lastEventAt: null,
            observedAt: $now,
            blockingErrors: [],
            warnings: [],
        );
    }

    /**
     * @return array{
     *     schema_version: int,
     *     exchange: string,
     *     environment: string,
     *     endpoint_id: string,
     *     connected: bool,
     *     authenticated: bool,
     *     orders_stream_ready: bool,
     *     fills_stream_ready: bool,
     *     fills_source: ?string,
     *     positions_stream_ready: bool,
     *     initial_snapshot_loaded: bool,
     *     reconciliation_fresh: bool,
     *     reconnecting: bool,
     *     connected_at: ?string,
     *     last_heartbeat_at: string,
     *     last_event_at: ?string,
     *     observed_at: string,
     *     blocking_errors: list<string>,
     *     warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'exchange' => $this->exchange,
            'environment' => $this->environment,
            'endpoint_id' => $this->endpointId,
            'connected' => $this->connected,
            'authenticated' => $this->authenticated,
            'orders_stream_ready' => $this->ordersStreamReady,
            'fills_stream_ready' => $this->fillsStreamReady,
            'fills_source' => $this->fillsSource,
            'positions_stream_ready' => $this->positionsStreamReady,
            'initial_snapshot_loaded' => $this->initialSnapshotLoaded,
            'reconciliation_fresh' => $this->reconciliationFresh,
            'reconnecting' => $this->reconnecting,
            'connected_at' => $this->connectedAt?->format(DATE_ATOM),
            'last_heartbeat_at' => $this->lastHeartbeatAt->format(DATE_ATOM),
            'last_event_at' => $this->lastEventAt?->format(DATE_ATOM),
            'observed_at' => $this->observedAt->format(DATE_ATOM),
            'blocking_errors' => $this->blockingErrors,
            'warnings' => $this->warnings,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $expectedFields = [
            'schema_version',
            'exchange',
            'environment',
            'endpoint_id',
            'connected',
            'authenticated',
            'orders_stream_ready',
            'fills_stream_ready',
            'fills_source',
            'positions_stream_ready',
            'initial_snapshot_loaded',
            'reconciliation_fresh',
            'reconnecting',
            'connected_at',
            'last_heartbeat_at',
            'last_event_at',
            'observed_at',
            'blocking_errors',
            'warnings',
        ];

        if ([] !== array_diff($expectedFields, array_keys($data))
            || [] !== array_diff(array_keys($data), $expectedFields)) {
            throw new InvalidArgumentException('okx_private_ws_status_schema_invalid');
        }
        if (!is_int($data['schema_version']) || self::SCHEMA_VERSION !== $data['schema_version']) {
            throw new InvalidArgumentException('okx_private_ws_status_version_unsupported');
        }
        if (self::EXCHANGE !== $data['exchange']
            || self::ENVIRONMENT !== $data['environment']
            || self::ENDPOINT_ID !== $data['endpoint_id']) {
            throw new InvalidArgumentException('okx_private_ws_status_target_invalid');
        }

        foreach ([
            'connected',
            'authenticated',
            'orders_stream_ready',
            'fills_stream_ready',
            'positions_stream_ready',
            'initial_snapshot_loaded',
            'reconciliation_fresh',
            'reconnecting',
        ] as $booleanField) {
            if (!is_bool($data[$booleanField])) {
                throw new InvalidArgumentException('okx_private_ws_status_field_invalid');
            }
        }
        if (null !== $data['fills_source'] && !is_string($data['fills_source'])) {
            throw new InvalidArgumentException('okx_private_ws_status_field_invalid');
        }
        if (!is_array($data['blocking_errors']) || !is_array($data['warnings'])) {
            throw new InvalidArgumentException('okx_private_ws_status_field_invalid');
        }

        return new self(
            connected: $data['connected'],
            authenticated: $data['authenticated'],
            ordersStreamReady: $data['orders_stream_ready'],
            fillsStreamReady: $data['fills_stream_ready'],
            fillsSource: $data['fills_source'],
            positionsStreamReady: $data['positions_stream_ready'],
            initialSnapshotLoaded: $data['initial_snapshot_loaded'],
            reconciliationFresh: $data['reconciliation_fresh'],
            reconnecting: $data['reconnecting'],
            connectedAt: self::parseTimestamp($data['connected_at'], true),
            lastHeartbeatAt: self::parseTimestamp($data['last_heartbeat_at'], false),
            lastEventAt: self::parseTimestamp($data['last_event_at'], true),
            observedAt: self::parseTimestamp($data['observed_at'], false),
            blockingErrors: $data['blocking_errors'],
            warnings: $data['warnings'],
        );
    }

    private static function assertUtc(?DateTimeImmutable $timestamp): void
    {
        if (null !== $timestamp && 0 !== $timestamp->getOffset()) {
            throw new InvalidArgumentException('okx_private_ws_status_timestamp_invalid');
        }
    }

    /**
     * @param array<mixed> $codes
     * @param list<string> $allowedCodes
     */
    private static function assertCodes(array $codes, array $allowedCodes): void
    {
        if (!array_is_list($codes)
            || count($codes) > self::MAX_CODES) {
            throw new InvalidArgumentException('okx_private_ws_status_codes_invalid');
        }

        foreach ($codes as $code) {
            if (!is_string($code) || !in_array($code, $allowedCodes, true)) {
                throw new InvalidArgumentException('okx_private_ws_status_codes_invalid');
            }
        }

        if (count($codes) !== count(array_unique($codes, SORT_STRING))) {
            throw new InvalidArgumentException('okx_private_ws_status_codes_invalid');
        }
    }

    private static function parseTimestamp(mixed $value, bool $nullable): ?DateTimeImmutable
    {
        if ($nullable && null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('okx_private_ws_status_timestamp_invalid');
        }

        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $timestamp
            || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))
            || $timestamp->format(DATE_ATOM) !== $value
            || 0 !== $timestamp->getOffset()) {
            throw new InvalidArgumentException('okx_private_ws_status_timestamp_invalid');
        }

        return $timestamp;
    }
}
