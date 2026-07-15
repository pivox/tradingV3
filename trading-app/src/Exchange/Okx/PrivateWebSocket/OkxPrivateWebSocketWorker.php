<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class OkxPrivateWebSocketWorker
{
    private const PRIVATE_ENDPOINT = 'private';
    private const BUSINESS_ENDPOINT = 'business';
    private const RECONNECT_DELAYS = [1.0, 2.0, 4.0, 8.0, 15.0, 15.0];
    private const PING_INTERVAL_SECONDS = 5.0;
    private const PONG_TIMEOUT_SECONDS = 4.0;
    private const LOGIN_TIMEOUT_SECONDS = 5.0;
    private const READINESS_TIMEOUT_SECONDS = 10.0;

    private readonly LoopInterface $loop;
    private readonly OkxPrivateWebSocketSession $session;
    private string $endpointId = OkxPrivateWebSocketObservabilityStatus::ENDPOINT_ID;
    private bool $started = false;
    private ?int $lastStatusSaveTimestamp = null;
    private ?string $privateUri = null;
    private ?string $businessUri = null;
    private int $reconnectAttempt = 0;
    private bool $reconnectScheduled = false;
    private bool $stopping = false;
    /** @var array<string, int> */
    private array $pingGenerations = [self::PRIVATE_ENDPOINT => 0, self::BUSINESS_ENDPOINT => 0];
    /** @var array<string, bool> */
    private array $awaitingPongs = [self::PRIVATE_ENDPOINT => false, self::BUSINESS_ENDPOINT => false];
    /** @var array<string, bool> */
    private array $connectedEndpoints = [self::PRIVATE_ENDPOINT => false, self::BUSINESS_ENDPOINT => false];
    private ?DateTimeImmutable $businessConnectedAt = null;
    private ?DateTimeImmutable $privateHeartbeatAt = null;
    private ?DateTimeImmutable $businessHeartbeatAt = null;
    private bool $businessAuthenticated = false;
    private bool $businessSubscribed = false;
    private int $connectionGeneration = 0;
    private ?int $maxCycles = null;
    private int $connectionAttempts = 0;
    /** @var array<int, TimerInterface> */
    private array $timers = [];
    private ?TimerInterface $loginDeadlineTimer = null;
    private ?TimerInterface $readinessDeadlineTimer = null;
    private ?\Closure $sigtermHandler = null;
    private ?\Closure $sigintHandler = null;

    public function __construct(
        private readonly OkxPrivateWebSocketTransportInterface $transport,
        private readonly OkxPrivateWebSocketTransportInterface $businessTransport,
        private readonly OkxConfig $config,
        private readonly OkxPrivateWebSocketEndpointGuard $endpointGuard,
        private readonly OkxPrivateWebSocketLoginSigner $loginSigner,
        private readonly OkxPrivateRestSnapshotProbe $snapshotProbe,
        private readonly OkxPrivateRestSnapshotReconciler $snapshotReconciler,
        private readonly OkxPrivateWebSocketStatusStoreInterface $statusStore,
        OkxExchangeEventNormalizer $normalizer,
        private readonly ExchangeEventBus $eventBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        ?LoopInterface $loop = null,
    ) {
        $this->loop = $loop ?? Loop::get();
        $this->session = new OkxPrivateWebSocketSession($normalizer, $clock->now());
    }

    public function run(?int $maxCycles = null): void
    {
        if (null !== $maxCycles && $maxCycles < 1) {
            throw new \InvalidArgumentException('okx_private_ws_max_cycles_invalid');
        }
        $this->maxCycles = $maxCycles;
        $this->start();
        $this->loop->run();
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        [$privateUri, $businessUri] = $this->assertConfiguration();
        $this->privateUri = $privateUri;
        $this->businessUri = $businessUri;
        $this->started = true;
        $this->sigtermHandler = function (): void {
            $this->stop();
        };
        $this->sigintHandler = function (): void {
            $this->stop();
        };
        $this->loop->addSignal(\SIGTERM, $this->sigtermHandler);
        $this->loop->addSignal(\SIGINT, $this->sigintHandler);
        $this->trackTimer($this->loop->addPeriodicTimer(1.0, function (): void {
            if ($this->stopping) {
                return;
            }
            $this->publishStatus($this->status());
        }));
        $this->trackTimer($this->loop->addPeriodicTimer(self::PING_INTERVAL_SECONDS, function (): void {
            if ($this->stopping) {
                return;
            }
            foreach ([self::PRIVATE_ENDPOINT, self::BUSINESS_ENDPOINT] as $endpoint) {
                $this->sendPing($endpoint);
            }
        }));
        if (!$this->publishStatus($this->status(), true)) {
            return;
        }
        $this->connect($privateUri, $businessUri);
    }

    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        ++$this->connectionGeneration;
        $this->resetEndpointState();
        $now = $this->clock->now();
        $status = new OkxPrivateWebSocketObservabilityStatus(
            connected: false,
            authenticated: false,
            ordersStreamReady: false,
            fillsStreamReady: false,
            fillsSource: null,
            positionsStreamReady: false,
            initialSnapshotLoaded: false,
            reconciliationFresh: false,
            reconnecting: false,
            connectedAt: null,
            lastHeartbeatAt: $now,
            lastEventAt: null,
            observedAt: $now,
            blockingErrors: ['okx_private_ws_worker_stopping'],
            warnings: [],
        );
        $this->publishStatus($status, true);
        $this->logger->info('okx_private_ws.state', $this->logContext(
            'worker_stopping',
            'worker',
            'okx_private_ws_worker_stopping',
        ));
        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->timers = [];
        $this->loginDeadlineTimer = null;
        $this->readinessDeadlineTimer = null;
        if (null !== $this->sigtermHandler) {
            $this->loop->removeSignal(\SIGTERM, $this->sigtermHandler);
            $this->sigtermHandler = null;
        }
        if (null !== $this->sigintHandler) {
            $this->loop->removeSignal(\SIGINT, $this->sigintHandler);
            $this->sigintHandler = null;
        }
        $this->transport->close();
        $this->businessTransport->close();
        $this->loop->stop();
    }

    private function trackTimer(TimerInterface $timer): void
    {
        $this->timers[spl_object_id($timer)] = $timer;
    }

    private function addOneShotTimer(float $interval, \Closure $callback): TimerInterface
    {
        $timer = null;
        $timer = $this->loop->addTimer($interval, function () use (&$timer, $callback): void {
            if ($timer instanceof TimerInterface) {
                unset($this->timers[spl_object_id($timer)]);
            }
            $callback();
        });
        $this->trackTimer($timer);

        return $timer;
    }

    private function connect(string $privateUri, string $businessUri): void
    {
        if (null !== $this->maxCycles && $this->connectionAttempts >= $this->maxCycles) {
            $this->stop();

            return;
        }
        ++$this->connectionAttempts;
        $generation = ++$this->connectionGeneration;
        $this->resetEndpointState();
        $this->connectEndpoint(self::PRIVATE_ENDPOINT, $this->transport, $privateUri, $generation);
        $this->connectEndpoint(self::BUSINESS_ENDPOINT, $this->businessTransport, $businessUri, $generation);
        $this->armLoginDeadline($generation);
    }

    private function connectEndpoint(
        string $endpoint,
        OkxPrivateWebSocketTransportInterface $transport,
        string $uri,
        int $generation,
    ): void {
        $transport->connect(
            $uri,
            function () use ($endpoint, $transport, $generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                $this->reconnectScheduled = false;
                $this->connectedEndpoints[$endpoint] = true;
                $this->awaitingPongs[$endpoint] = false;
                ++$this->pingGenerations[$endpoint];
                if (self::BUSINESS_ENDPOINT === $endpoint) {
                    $this->businessConnectedAt = self::utc($this->clock->now());
                    $this->businessHeartbeatAt = $this->businessConnectedAt;
                } else {
                    $this->privateHeartbeatAt = self::utc($this->clock->now());
                }
                try {
                    $loginCommand = $this->loginCommand();
                    if (self::PRIVATE_ENDPOINT === $endpoint) {
                        $result = $this->session->onConnected($loginCommand['args'], $this->clock->now());
                        $this->sendSessionCommands($result->outgoingCommands);
                    } else {
                        $transport->send($loginCommand);
                    }
                } catch (\Throwable) {
                    $this->failClosed('login', 'okx_private_ws_connection_failed');

                    return;
                }
                $this->logger->info('okx_private_ws.state', $this->logContext('login_sent', $endpoint));
            },
            function (string $message) use ($endpoint, $generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                try {
                    $this->onMessage($message, $generation, $endpoint);
                } catch (\Throwable) {
                    $this->failClosed('message', 'okx_private_ws_message_invalid');
                }
            },
            function (?int $code) use ($endpoint, $generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                $this->handleConnectionFailure('okx_private_ws_connection_closed', $code, $endpoint);
            },
            function (\Throwable $error) use ($generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                $this->failClosed('connection', 'okx_private_ws_connection_failed');
            },
        );
    }

    /** @return array{op: string, args: list<array<string, string>>} */
    private function loginCommand(): array
    {
        return [
            'op' => 'login',
            'args' => [$this->loginSigner->buildLoginArgs(
                $this->config->apiKey,
                $this->config->apiSecret,
                $this->config->apiPassphrase,
                (string) $this->clock->now()->getTimestamp(),
            )],
        ];
    }

    private function failClosed(string $channel, string $code): void
    {
        $this->logger->error('okx_private_ws.state', $this->logContext('not_ready', $channel, $code));
        $this->handleConnectionFailure($code);
    }

    private function isCurrentConnection(int $generation): bool
    {
        return !$this->stopping && $generation === $this->connectionGeneration;
    }

    private function onMessage(string $payload, int $generation, string $endpoint): void
    {
        if ('pong' === $payload) {
            $previousHeartbeatAt = $this->status()->lastHeartbeatAt;
            $this->awaitingPongs[$endpoint] = false;
            ++$this->pingGenerations[$endpoint];
            if (self::BUSINESS_ENDPOINT === $endpoint) {
                $this->businessHeartbeatAt = self::utc($this->clock->now());
            } else {
                $this->privateHeartbeatAt = self::utc($this->clock->now());
                $this->session->onHeartbeat($this->clock->now());
            }
            $status = $this->status();
            $this->publishStatus($status, $status->lastHeartbeatAt > $previousHeartbeatAt);

            return;
        }

        $message = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($message) || array_is_list($message)) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
        if (self::BUSINESS_ENDPOINT === $endpoint) {
            $this->businessHeartbeatAt = self::utc($this->clock->now());
        } else {
            $this->privateHeartbeatAt = self::utc($this->clock->now());
        }

        if (self::BUSINESS_ENDPOINT === $endpoint && 'login' === ($message['event'] ?? null)) {
            $this->handleBusinessLogin($message);
            $this->afterMessage($generation);

            return;
        }
        if (self::BUSINESS_ENDPOINT === $endpoint
            && !$this->businessAuthenticated
            && 'error' === ($message['event'] ?? null)
            && !array_key_exists('arg', $message)) {
            $this->failClosed('login', 'okx_private_ws_authentication_failed');

            return;
        }
        if (self::BUSINESS_ENDPOINT === $endpoint && !$this->businessAuthenticated) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }

        $this->assertEndpointMessage($message, $endpoint);

        $wasAuthenticated = $this->session->status()->authenticated;
        $result = $this->session->onMessage($message, $this->clock->now());
        $this->sendSessionCommands($result->outgoingCommands);
        $this->eventBus->publishMany($result->normalizedEvents);

        $status = $this->session->status();
        if (\in_array('okx_private_ws_authentication_failed', $status->blockingErrors, true)) {
            $this->failClosed('login', 'okx_private_ws_authentication_failed');

            return;
        }
        if (\in_array('okx_private_ws_subscription_failed', $status->blockingErrors, true)) {
            $this->failClosed('subscription', 'okx_private_ws_subscription_failed');

            return;
        }

        if (!$wasAuthenticated && $status->authenticated) {
            $this->subscribeBusinessIfReady();
            if ($this->allAuthenticated()) {
                $this->cancelDeadline($this->loginDeadlineTimer);
            }
            $readinessStartedAt = $this->clock->now();
            $this->armReadinessDeadline($generation);
            try {
                $snapshot = $this->snapshotProbe->probe($readinessStartedAt);
            } catch (\Throwable) {
                $this->failClosed('snapshot', 'okx_private_rest_snapshot_failed');

                return;
            }
            $readinessElapsed = $this->clock->now()->getTimestamp() - $readinessStartedAt->getTimestamp();
            if ($readinessElapsed >= self::READINESS_TIMEOUT_SECONDS) {
                $this->failClosed('snapshot', 'okx_private_rest_snapshot_failed');

                return;
            }
            try {
                $this->snapshotReconciler->reconcile($snapshot);
                $readinessElapsed = $this->clock->now()->getTimestamp() - $readinessStartedAt->getTimestamp();
                if ($readinessElapsed >= self::READINESS_TIMEOUT_SECONDS) {
                    throw new \RuntimeException('okx_private_rest_snapshot_failed');
                }
                $this->session->applySnapshot($snapshot, $this->clock->now());
            } catch (\Throwable) {
                $this->failClosed('snapshot', 'okx_private_rest_snapshot_failed');

                return;
            }
            if (!$this->session->status()->initialSnapshotLoaded) {
                $this->failClosed('snapshot', 'okx_private_rest_snapshot_failed');

                return;
            }
        }

        $this->afterMessage($generation);
    }

    /** @param array<string, mixed> $message */
    private function handleBusinessLogin(array $message): void
    {
        if (!$this->connectedEndpoints[self::BUSINESS_ENDPOINT] || $this->businessAuthenticated) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
        if (array_key_exists('arg', $message) || array_key_exists('data', $message)) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
        $code = $message['code'] ?? null;
        if ((!is_string($code) && !is_int($code)) || '0' !== (string) $code) {
            $this->failClosed('login', 'okx_private_ws_authentication_failed');

            return;
        }
        $this->businessAuthenticated = true;
        $this->subscribeBusinessIfReady();
        if ($this->allAuthenticated()) {
            $this->cancelDeadline($this->loginDeadlineTimer);
        }
    }

    /** @param array<string, mixed> $message */
    private function assertEndpointMessage(array $message, string $endpoint): void
    {
        $arg = $message['arg'] ?? null;
        if (!is_array($arg) || !is_string($arg['channel'] ?? null)) {
            if (array_key_exists('data', $message)
                || \in_array($message['event'] ?? null, ['subscribe', 'error'], true)) {
                throw new \InvalidArgumentException('okx_private_ws_message_invalid');
            }

            return;
        }

        $channel = $arg['channel'];
        if ((self::BUSINESS_ENDPOINT === $endpoint) !== ('orders-algo' === $channel)) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }
    }

    /** @param list<array<string, mixed>> $commands */
    private function sendSessionCommands(array $commands): void
    {
        foreach ($commands as $command) {
            if ('subscribe' !== ($command['op'] ?? null)) {
                $this->transport->send($command);

                continue;
            }
            $args = $command['args'] ?? [];
            if (!is_array($args)) {
                throw new \InvalidArgumentException('okx_private_ws_message_invalid');
            }
            $privateArgs = array_values(array_filter(
                $args,
                static fn (mixed $arg): bool => is_array($arg) && 'orders-algo' !== ($arg['channel'] ?? null),
            ));
            if ([] !== $privateArgs) {
                $this->transport->send(['op' => 'subscribe', 'args' => $privateArgs]);
            }
        }
    }

    private function subscribeBusinessIfReady(): void
    {
        if ($this->businessSubscribed || !$this->businessAuthenticated || !$this->session->status()->authenticated) {
            return;
        }
        $this->businessTransport->send([
            'op' => 'subscribe',
            'args' => [['channel' => 'orders-algo', 'instType' => 'SWAP']],
        ]);
        $this->businessSubscribed = true;
    }

    private function afterMessage(int $generation): void
    {
        if (!$this->isCurrentConnection($generation)) {
            return;
        }
        if ($this->isReady()) {
            $this->cancelDeadline($this->readinessDeadlineTimer);
            $this->reconnectAttempt = 0;
        }
        $this->publishStatus($this->status());
    }

    private function sendPing(string $endpoint): void
    {
        if (!$this->connectedEndpoints[$endpoint] || $this->awaitingPongs[$endpoint]) {
            return;
        }
        $generation = ++$this->pingGenerations[$endpoint];
        $this->awaitingPongs[$endpoint] = true;
        try {
            $this->transportFor($endpoint)->send(['op' => 'ping']);
        } catch (\Throwable) {
            $this->awaitingPongs[$endpoint] = false;
            $this->failClosed('heartbeat', 'okx_private_ws_connection_failed');

            return;
        }
        $this->armPongDeadline($endpoint, $generation);
        $this->logger->info('okx_private_ws.state', $this->logContext('ping_sent', $endpoint));
    }

    private function armPongDeadline(string $endpoint, int $generation): void
    {
        if (!$this->awaitingPongs[$endpoint]) {
            return;
        }
        $this->addOneShotTimer(self::PONG_TIMEOUT_SECONDS, function () use ($endpoint, $generation): void {
            if ($generation !== $this->pingGenerations[$endpoint]
                || !$this->awaitingPongs[$endpoint]
                || $this->stopping) {
                return;
            }
            $this->awaitingPongs[$endpoint] = false;
            $this->failClosed('heartbeat', 'okx_private_ws_connection_failed');
        });
    }

    private function transportFor(string $endpoint): OkxPrivateWebSocketTransportInterface
    {
        return self::PRIVATE_ENDPOINT === $endpoint ? $this->transport : $this->businessTransport;
    }

    private function allAuthenticated(): bool
    {
        return $this->session->status()->authenticated && $this->businessAuthenticated;
    }

    private function allConnected(): bool
    {
        return $this->connectedEndpoints[self::PRIVATE_ENDPOINT]
            && $this->connectedEndpoints[self::BUSINESS_ENDPOINT];
    }

    private function resetEndpointState(): void
    {
        foreach ([self::PRIVATE_ENDPOINT, self::BUSINESS_ENDPOINT] as $endpoint) {
            $this->connectedEndpoints[$endpoint] = false;
            $this->awaitingPongs[$endpoint] = false;
            ++$this->pingGenerations[$endpoint];
        }
        $this->businessAuthenticated = false;
        $this->businessSubscribed = false;
        $this->businessConnectedAt = null;
        $this->privateHeartbeatAt = null;
        $this->businessHeartbeatAt = null;
    }

    private function status(): OkxPrivateWebSocketObservabilityStatus
    {
        $status = $this->session->status();
        $connectedAt = $status->connectedAt;
        if (null !== $connectedAt && null !== $this->businessConnectedAt
            && $this->businessConnectedAt > $connectedAt) {
            $connectedAt = $this->businessConnectedAt;
        }
        $lastHeartbeatAt = $this->privateHeartbeatAt ?? $status->lastHeartbeatAt;
        if (null !== $this->businessHeartbeatAt && $this->businessHeartbeatAt < $lastHeartbeatAt) {
            $lastHeartbeatAt = $this->businessHeartbeatAt;
        }
        $allConnected = $this->allConnected();
        $allAuthenticated = $this->allAuthenticated();

        return new OkxPrivateWebSocketObservabilityStatus(
            connected: $allConnected,
            authenticated: $allAuthenticated,
            ordersStreamReady: $allAuthenticated && $status->ordersStreamReady,
            fillsStreamReady: $status->fillsStreamReady,
            fillsSource: $status->fillsSource,
            positionsStreamReady: $status->positionsStreamReady,
            initialSnapshotLoaded: $status->initialSnapshotLoaded,
            reconciliationFresh: $status->reconciliationFresh,
            reconnecting: $status->reconnecting || !$allConnected,
            connectedAt: $allConnected ? $connectedAt : null,
            lastHeartbeatAt: $lastHeartbeatAt,
            lastEventAt: $status->lastEventAt,
            observedAt: self::utc($this->clock->now()),
            blockingErrors: $status->blockingErrors,
            warnings: $status->warnings,
        );
    }

    private static function utc(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->setTimezone(new DateTimeZone('UTC'));
    }

    private function armLoginDeadline(int $generation): void
    {
        $this->cancelDeadline($this->loginDeadlineTimer);
        $this->loginDeadlineTimer = $this->addOneShotTimer(
            self::LOGIN_TIMEOUT_SECONDS,
            function () use ($generation): void {
                if (!$this->isCurrentConnection($generation)
                    || $this->allAuthenticated()) {
                    return;
                }
                $this->failClosed('login', 'okx_private_ws_authentication_failed');
            },
        );
    }

    private function armReadinessDeadline(int $generation): void
    {
        $this->cancelDeadline($this->readinessDeadlineTimer);
        $this->readinessDeadlineTimer = $this->addOneShotTimer(
            self::READINESS_TIMEOUT_SECONDS,
            function () use ($generation): void {
                if (!$this->isCurrentConnection($generation) || $this->isReady()) {
                    return;
                }
                $this->failClosed('subscription', 'okx_private_ws_subscription_failed');
            },
        );
    }

    private function isReady(): bool
    {
        $status = $this->session->status();

        return $this->allAuthenticated()
            && $status->ordersStreamReady
            && $status->fillsStreamReady
            && $status->positionsStreamReady
            && $status->initialSnapshotLoaded
            && $status->reconciliationFresh;
    }

    private function cancelDeadline(?TimerInterface &$timer): void
    {
        if (null === $timer) {
            return;
        }
        $this->loop->cancelTimer($timer);
        unset($this->timers[spl_object_id($timer)]);
        $timer = null;
    }

    private function handleConnectionFailure(
        string $code,
        ?int $closeCode = null,
        ?string $failedEndpoint = null,
    ): void
    {
        if ($this->stopping || $this->reconnectScheduled) {
            return;
        }

        ++$this->connectionGeneration;
        $this->cancelDeadline($this->loginDeadlineTimer);
        $this->cancelDeadline($this->readinessDeadlineTimer);
        $this->resetEndpointState();
        $this->session->onDisconnected($this->clock->now());
        if (!$this->publishStatus($this->status(), true)) {
            return;
        }
        $this->logger->warning('okx_private_ws.state', $this->logContext(
            'reconnecting',
            'connection',
            $code,
        ));

        $this->scheduleReconnect();
        if (self::PRIVATE_ENDPOINT !== $failedEndpoint) {
            $this->transport->close();
        }
        if (self::BUSINESS_ENDPOINT !== $failedEndpoint) {
            $this->businessTransport->close();
        }
    }

    private function scheduleReconnect(): void
    {
        if ($this->stopping || $this->reconnectScheduled) {
            return;
        }

        $delayIndex = min($this->reconnectAttempt, count(self::RECONNECT_DELAYS) - 1);
        $delay = self::RECONNECT_DELAYS[$delayIndex];
        ++$this->reconnectAttempt;
        $this->reconnectScheduled = true;
        $this->addOneShotTimer($delay, function (): void {
            $this->reconnectScheduled = false;
            if (!$this->stopping
                && null !== $this->privateUri
                && null !== $this->businessUri
                && $this->publishStatus($this->status(), true)) {
                $this->connect($this->privateUri, $this->businessUri);
            }
        });
    }

    /** @return array{string, string} */
    private function assertConfiguration(): array
    {
        if ('demo' !== $this->config->environment) {
            throw new \RuntimeException('okx_private_ws_environment_invalid');
        }
        if (!$this->config->simulatedTrading) {
            throw new \RuntimeException('okx_private_ws_simulated_trading_required');
        }
        if ($this->config->liveEnabled) {
            throw new \RuntimeException('okx_private_ws_live_enabled');
        }
        if ('' === trim($this->config->apiKey)
            || '' === trim($this->config->apiSecret)
            || '' === trim($this->config->apiPassphrase)) {
            throw new \RuntimeException('okx_private_ws_credentials_missing');
        }

        $privateUri = $this->config->wsPrivateUri();
        $businessUri = $this->config->wsBusinessUri();
        $privateEndpointId = $this->endpointGuard->assertAllowed($privateUri);
        $businessEndpointId = $this->endpointGuard->assertAllowed($businessUri);
        $this->endpointId = $privateEndpointId . '+' . $businessEndpointId;

        return [$privateUri, $businessUri];
    }

    private function publishStatus(OkxPrivateWebSocketObservabilityStatus $status, bool $force = false): bool
    {
        $timestamp = $this->clock->now()->getTimestamp();
        if (!$force
            && null !== $this->lastStatusSaveTimestamp
            && $timestamp - $this->lastStatusSaveTimestamp < 3) {
            return true;
        }

        try {
            $this->statusStore->save($status);
        } catch (\Throwable) {
            $this->logger->error('okx_private_ws.state', $this->logContext(
                'not_ready',
                'status_store',
                'okx_private_ws_status_store_failed',
            ));
            if (!$this->stopping) {
                ++$this->connectionGeneration;
                $this->cancelDeadline($this->loginDeadlineTimer);
                $this->cancelDeadline($this->readinessDeadlineTimer);
                $this->session->onDisconnected($this->clock->now());
                $this->resetEndpointState();
                $this->scheduleReconnect();
                $this->transport->close();
                $this->businessTransport->close();
            }

            return false;
        }
        $this->lastStatusSaveTimestamp = $timestamp;

        return true;
    }

    /** @return array{endpoint_id: string, channel: string, state: string, code: string} */
    private function logContext(string $state, string $channel = 'worker', string $code = 'ok'): array
    {
        return [
            'endpoint_id' => $this->endpointId,
            'channel' => $channel,
            'state' => $state,
            'code' => $code,
        ];
    }
}
