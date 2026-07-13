<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Okx\OkxExchangeEventNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class OkxPrivateWebSocketSession
{
    /** @var list<string> */
    private const REQUIRED_CHANNELS = [
        'orders',
        'positions',
        'balance_and_position',
        'fills',
    ];

    private readonly OkxExchangeEventNormalizer $normalizer;
    private OkxPrivateWebSocketObservabilityStatus $status;
    private bool $positionsAcknowledged = false;
    private bool $balanceAndPositionAcknowledged = false;
    private bool $loginExpected = false;

    /** @var array<string, true> */
    private array $failedSubscriptions = [];

    public function __construct(
        OkxExchangeEventNormalizer $normalizer,
        DateTimeImmutable $now,
    ) {
        $this->normalizer = $normalizer;
        $this->status = OkxPrivateWebSocketObservabilityStatus::connecting(self::utc($now));
    }

    /**
     * @param array<mixed> $loginArgs
     */
    public function onConnected(
        array $loginArgs,
        DateTimeImmutable $now,
    ): OkxPrivateWebSocketSessionResult
    {
        $now = self::utc($now);
        $this->positionsAcknowledged = false;
        $this->balanceAndPositionAcknowledged = false;
        $this->loginExpected = true;
        $this->failedSubscriptions = [];
        $this->status = new OkxPrivateWebSocketObservabilityStatus(
            connected: true,
            authenticated: false,
            ordersStreamReady: false,
            fillsStreamReady: false,
            fillsSource: null,
            positionsStreamReady: false,
            initialSnapshotLoaded: false,
            reconciliationFresh: false,
            reconnecting: false,
            connectedAt: $now,
            lastHeartbeatAt: $now,
            lastEventAt: null,
            observedAt: $now,
            blockingErrors: [],
            warnings: [],
        );

        return new OkxPrivateWebSocketSessionResult(outgoingCommands: [[
            'op' => 'login',
            'args' => $loginArgs,
        ]]);
    }

    /** @param array<mixed> $message */
    public function onMessage(array $message, DateTimeImmutable $now): OkxPrivateWebSocketSessionResult
    {
        $now = self::utc($now);

        if (array_key_exists('event', $message)) {
            if (!is_string($message['event'])) {
                $this->rejectInvalidMessage($now);
            }

            return match ($message['event']) {
                'login' => $this->handleLogin($message, $now),
                'subscribe' => $this->handleSubscriptionAcknowledgement($message, $now),
                'error' => $this->handleProtocolError($message, $now),
                default => new OkxPrivateWebSocketSessionResult(),
            };
        }

        if (array_key_exists('data', $message)) {
            return $this->handleData($message, $now);
        }

        return new OkxPrivateWebSocketSessionResult();
    }

    public function applySnapshot(OkxPrivateRestSnapshot $snapshot, DateTimeImmutable $now): void
    {
        $now = self::utc($now);
        $this->assertAuthenticated($now);

        if ($snapshot->complete) {
            $this->replaceStatus(
                now: $now,
                updateHeartbeat: false,
                initialSnapshotLoaded: true,
                reconciliationFresh: true,
                blockingErrors: self::withoutCode(
                    $this->status->blockingErrors,
                    'okx_private_rest_snapshot_failed',
                ),
            );

            return;
        }

        $this->replaceStatus(
            now: $now,
            updateHeartbeat: false,
            initialSnapshotLoaded: false,
            reconciliationFresh: false,
            blockingErrors: self::withCode(
                $this->status->blockingErrors,
                'okx_private_rest_snapshot_failed',
            ),
        );
    }

    public function onDisconnected(DateTimeImmutable $now): void
    {
        $this->reset($now);
    }

    public function reset(DateTimeImmutable $now): void
    {
        $now = self::utc($now);
        $this->positionsAcknowledged = false;
        $this->balanceAndPositionAcknowledged = false;
        $this->loginExpected = false;
        $this->failedSubscriptions = [];
        $this->status = new OkxPrivateWebSocketObservabilityStatus(
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
            blockingErrors: ['okx_private_ws_connection_failed'],
            warnings: [],
        );
    }

    public function status(): OkxPrivateWebSocketObservabilityStatus
    {
        return $this->status;
    }

    public function onHeartbeat(DateTimeImmutable $now): void
    {
        if (!$this->status->connected) {
            return;
        }

        $this->replaceStatus(
            now: self::utc($now),
            updateHeartbeat: true,
        );
    }

    /** @param array<mixed> $message */
    private function handleLogin(array $message, DateTimeImmutable $now): OkxPrivateWebSocketSessionResult
    {
        if (!$this->status->connected || !$this->loginExpected || $this->status->authenticated) {
            $this->rejectInvalidMessage($now);
        }

        $code = $this->protocolCode($message, $now, required: true);
        $this->loginExpected = false;
        if ('0' !== $code) {
            $this->positionsAcknowledged = false;
            $this->balanceAndPositionAcknowledged = false;
            $this->failedSubscriptions = [];
            $this->replaceStatus(
                now: $now,
                updateHeartbeat: true,
                authenticated: false,
                ordersStreamReady: false,
                fillsStreamReady: false,
                clearFillsSource: true,
                positionsStreamReady: false,
                blockingErrors: self::withCode(
                    $this->status->blockingErrors,
                    'okx_private_ws_authentication_failed',
                ),
            );

            return new OkxPrivateWebSocketSessionResult();
        }

        $this->replaceStatus(
            now: $now,
            updateHeartbeat: true,
            authenticated: true,
            blockingErrors: self::withoutCode(
                $this->status->blockingErrors,
                'okx_private_ws_authentication_failed',
            ),
        );

        return new OkxPrivateWebSocketSessionResult(outgoingCommands: [[
            'op' => 'subscribe',
            'args' => [
                ['channel' => 'orders', 'instType' => 'SWAP'],
                ['channel' => 'positions', 'instType' => 'SWAP'],
                ['channel' => 'balance_and_position'],
                ['channel' => 'fills'],
            ],
        ]]);
    }

    /** @param array<mixed> $message */
    private function handleSubscriptionAcknowledgement(
        array $message,
        DateTimeImmutable $now,
    ): OkxPrivateWebSocketSessionResult {
        $this->assertAuthenticated($now);
        $channel = $this->requiredChannel($message, $now);
        $code = $this->protocolCode($message, $now, required: false);
        if (null !== $code && '0' !== $code) {
            $this->failSubscription($channel, $now);

            return new OkxPrivateWebSocketSessionResult();
        }

        $this->recoverSubscription($channel);
        $blockingErrors = $this->subscriptionBlockingErrors();
        match ($channel) {
            'orders' => $this->replaceStatus(
                now: $now,
                updateHeartbeat: true,
                ordersStreamReady: true,
                blockingErrors: $blockingErrors,
            ),
            'positions' => $this->acknowledgePositions($now, $blockingErrors),
            'balance_and_position' => $this->acknowledgeBalanceAndPosition($now, $blockingErrors),
            'fills' => $this->replaceStatus(
                now: $now,
                updateHeartbeat: true,
                fillsStreamReady: true,
                fillsSource: 'fills_channel',
                blockingErrors: $blockingErrors,
                warnings: self::withoutCode(
                    $this->status->warnings,
                    'okx_fills_channel_vip_unavailable',
                ),
            ),
            default => $this->rejectInvalidMessage($now),
        };

        return new OkxPrivateWebSocketSessionResult();
    }

    /** @param array<mixed> $message */
    private function handleProtocolError(array $message, DateTimeImmutable $now): OkxPrivateWebSocketSessionResult
    {
        $code = $this->protocolCode($message, $now, required: true);
        $arg = $message['arg'] ?? null;

        if (!$this->status->authenticated) {
            if (!$this->status->connected || !$this->loginExpected || null !== $arg) {
                $this->rejectInvalidMessage($now);
            }

            $this->loginExpected = false;
            $this->replaceStatus(
                now: $now,
                updateHeartbeat: true,
                authenticated: false,
                blockingErrors: self::withCode(
                    $this->status->blockingErrors,
                    'okx_private_ws_authentication_failed',
                ),
            );

            return new OkxPrivateWebSocketSessionResult();
        }

        $channel = $this->requiredChannel($message, $now);

        if ('fills' === $channel && '64003' === $code) {
            $this->recoverSubscription($channel);
            $this->replaceStatus(
                now: $now,
                updateHeartbeat: true,
                fillsStreamReady: true,
                fillsSource: 'orders_plus_rest',
                blockingErrors: $this->subscriptionBlockingErrors(),
                warnings: self::withCode(
                    $this->status->warnings,
                    'okx_fills_channel_vip_unavailable',
                ),
            );

            return new OkxPrivateWebSocketSessionResult();
        }

        $this->failSubscription($channel, $now);

        return new OkxPrivateWebSocketSessionResult();
    }

    /** @param array<mixed> $message */
    private function handleData(array $message, DateTimeImmutable $now): OkxPrivateWebSocketSessionResult
    {
        $this->assertAuthenticated($now);
        $arg = $message['arg'] ?? null;
        if (!is_array($arg) || !is_string($arg['channel'] ?? null)) {
            $this->rejectInvalidMessage($now);
        }

        $channel = $arg['channel'];
        if (!in_array($channel, self::REQUIRED_CHANNELS, true)) {
            return new OkxPrivateWebSocketSessionResult();
        }

        $data = $message['data'];
        if (!is_array($data)
            || !array_is_list($data)
            || array_filter($data, static fn (mixed $row): bool => !is_array($row)) !== []) {
            $this->rejectInvalidMessage($now);
        }

        $this->replaceStatus(
            now: $now,
            updateHeartbeat: true,
            lastEventAt: $now,
        );
        if ('balance_and_position' === $channel) {
            return new OkxPrivateWebSocketSessionResult();
        }

        return new OkxPrivateWebSocketSessionResult(
            normalizedEvents: $this->normalizer->normalize($message),
        );
    }

    /** @param list<string> $blockingErrors */
    private function acknowledgePositions(DateTimeImmutable $now, array $blockingErrors): void
    {
        $this->positionsAcknowledged = true;
        $this->replaceStatus(
            now: $now,
            updateHeartbeat: true,
            positionsStreamReady: $this->balanceAndPositionAcknowledged,
            blockingErrors: $blockingErrors,
        );
    }

    /** @param list<string> $blockingErrors */
    private function acknowledgeBalanceAndPosition(DateTimeImmutable $now, array $blockingErrors): void
    {
        $this->balanceAndPositionAcknowledged = true;
        $this->replaceStatus(
            now: $now,
            updateHeartbeat: true,
            positionsStreamReady: $this->positionsAcknowledged,
            blockingErrors: $blockingErrors,
        );
    }

    private function failSubscription(string $channel, DateTimeImmutable $now): void
    {
        $this->failedSubscriptions[$channel] = true;
        $arguments = [
            'now' => $now,
            'updateHeartbeat' => true,
            'blockingErrors' => $this->subscriptionBlockingErrors(),
        ];

        if ('orders' === $channel) {
            $arguments['ordersStreamReady'] = false;
        } elseif ('fills' === $channel) {
            $arguments['fillsStreamReady'] = false;
            $arguments['clearFillsSource'] = true;
        } elseif ('positions' === $channel) {
            $this->positionsAcknowledged = false;
            $arguments['positionsStreamReady'] = false;
        } elseif ('balance_and_position' === $channel) {
            $this->balanceAndPositionAcknowledged = false;
            $arguments['positionsStreamReady'] = false;
        }

        /** @var array{now: DateTimeImmutable, updateHeartbeat: bool, blockingErrors: list<string>, ordersStreamReady?: bool, fillsStreamReady?: bool, clearFillsSource?: bool, positionsStreamReady?: bool} $arguments */
        $this->replaceStatus(...$arguments);
    }

    /**
     * @param array<mixed> $message
     */
    private function requiredChannel(array $message, DateTimeImmutable $now): string
    {
        $arg = $message['arg'] ?? null;
        $channel = is_array($arg) ? ($arg['channel'] ?? null) : null;
        if (!is_string($channel) || !in_array($channel, self::REQUIRED_CHANNELS, true)) {
            $this->rejectInvalidMessage($now);
        }

        $actual = $arg;
        $expected = self::expectedSubscriptionArg($channel);
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            $this->rejectInvalidMessage($now);
        }

        return $channel;
    }

    /** @return array<string, string> */
    private static function expectedSubscriptionArg(string $channel): array
    {
        return \in_array($channel, ['balance_and_position', 'fills'], true)
            ? ['channel' => $channel]
            : ['channel' => $channel, 'instType' => 'SWAP'];
    }

    private function assertAuthenticated(DateTimeImmutable $now): void
    {
        if (!$this->status->connected || !$this->status->authenticated) {
            $this->rejectInvalidMessage($now);
        }
    }

    private function recoverSubscription(string $channel): void
    {
        unset($this->failedSubscriptions[$channel]);
    }

    /** @return list<string> */
    private function subscriptionBlockingErrors(): array
    {
        $blockingErrors = self::withoutCode(
            $this->status->blockingErrors,
            'okx_private_ws_subscription_failed',
        );

        return [] === $this->failedSubscriptions
            ? $blockingErrors
            : self::withCode($blockingErrors, 'okx_private_ws_subscription_failed');
    }

    /**
     * @param array<mixed> $message
     */
    private function protocolCode(
        array $message,
        DateTimeImmutable $now,
        bool $required,
    ): ?string {
        if (!array_key_exists('code', $message)) {
            if ($required) {
                $this->rejectInvalidMessage($now);
            }

            return null;
        }

        if (!is_string($message['code']) && !is_int($message['code'])) {
            $this->rejectInvalidMessage($now);
        }

        return (string) $message['code'];
    }

    private function rejectInvalidMessage(DateTimeImmutable $now): never
    {
        $this->replaceStatus(
            now: $now,
            updateHeartbeat: false,
            blockingErrors: self::withCode(
                $this->status->blockingErrors,
                'okx_private_ws_message_invalid',
            ),
        );

        throw new InvalidArgumentException('okx_private_ws_message_invalid');
    }

    /**
     * @param list<string>|null $blockingErrors
     * @param list<string>|null $warnings
     */
    private function replaceStatus(
        DateTimeImmutable $now,
        bool $updateHeartbeat,
        ?bool $authenticated = null,
        ?bool $ordersStreamReady = null,
        ?bool $fillsStreamReady = null,
        ?string $fillsSource = null,
        bool $clearFillsSource = false,
        ?bool $positionsStreamReady = null,
        ?bool $initialSnapshotLoaded = null,
        ?bool $reconciliationFresh = null,
        ?DateTimeImmutable $lastEventAt = null,
        ?array $blockingErrors = null,
        ?array $warnings = null,
    ): void {
        $this->status = new OkxPrivateWebSocketObservabilityStatus(
            connected: $this->status->connected,
            authenticated: $authenticated ?? $this->status->authenticated,
            ordersStreamReady: $ordersStreamReady ?? $this->status->ordersStreamReady,
            fillsStreamReady: $fillsStreamReady ?? $this->status->fillsStreamReady,
            fillsSource: $clearFillsSource ? null : ($fillsSource ?? $this->status->fillsSource),
            positionsStreamReady: $positionsStreamReady ?? $this->status->positionsStreamReady,
            initialSnapshotLoaded: $initialSnapshotLoaded ?? $this->status->initialSnapshotLoaded,
            reconciliationFresh: $reconciliationFresh ?? $this->status->reconciliationFresh,
            reconnecting: $this->status->reconnecting,
            connectedAt: $this->status->connectedAt,
            lastHeartbeatAt: $updateHeartbeat ? $now : $this->status->lastHeartbeatAt,
            lastEventAt: $lastEventAt ?? $this->status->lastEventAt,
            observedAt: $now,
            blockingErrors: $blockingErrors ?? $this->status->blockingErrors,
            warnings: $warnings ?? $this->status->warnings,
        );
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private static function withCode(array $codes, string $code): array
    {
        if (!in_array($code, $codes, true)) {
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private static function withoutCode(array $codes, string $code): array
    {
        return array_values(array_filter(
            $codes,
            static fn (string $candidate): bool => $candidate !== $code,
        ));
    }

    private static function utc(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->setTimezone(new DateTimeZone('UTC'));
    }
}
