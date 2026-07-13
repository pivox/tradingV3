<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxExchangeEventNormalizer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class OkxPrivateWebSocketWorker
{
    private const array RECONNECT_DELAYS = [1.0, 2.0, 4.0, 8.0, 15.0, 15.0];
    private const float PING_INTERVAL_SECONDS = 5.0;
    private const float PONG_TIMEOUT_SECONDS = 4.0;
    private const LOGIN_TIMEOUT_SECONDS = 5.0;
    private const READINESS_TIMEOUT_SECONDS = 10.0;

    private readonly LoopInterface $loop;
    private readonly OkxPrivateWebSocketSession $session;
    private string $endpointId = OkxPrivateWebSocketObservabilityStatus::ENDPOINT_ID;
    private bool $started = false;
    private ?int $lastStatusSaveTimestamp = null;
    private ?string $uri = null;
    private int $reconnectAttempt = 0;
    private bool $reconnectScheduled = false;
    private bool $stopping = false;
    private int $pingGeneration = 0;
    private bool $awaitingPong = false;
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

        $uri = $this->assertConfiguration();
        $this->uri = $uri;
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
            $this->publishStatus($this->session->status());
        }));
        $this->trackTimer($this->loop->addPeriodicTimer(self::PING_INTERVAL_SECONDS, function (): void {
            if (!$this->stopping && $this->session->status()->connected && !$this->awaitingPong) {
                try {
                    $this->transport->send(['op' => 'ping']);
                } catch (\Throwable) {
                    $this->failClosed('heartbeat', 'okx_private_ws_connection_failed');

                    return;
                }
                $this->awaitingPong = true;
                $generation = ++$this->pingGeneration;
                $this->addOneShotTimer(self::PONG_TIMEOUT_SECONDS, function () use ($generation): void {
                    if ($generation !== $this->pingGeneration || !$this->awaitingPong || $this->stopping) {
                        return;
                    }
                    $this->awaitingPong = false;
                    $this->failClosed('heartbeat', 'okx_private_ws_connection_failed');
                });
                $this->logger->info('okx_private_ws.state', $this->logContext('ping_sent', 'heartbeat'));
            }
        }));
        if (!$this->publishStatus($this->session->status(), true)) {
            return;
        }
        $this->connect($uri);
    }

    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }

        $this->stopping = true;
        ++$this->connectionGeneration;
        ++$this->pingGeneration;
        $this->awaitingPong = false;
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

    private function connect(string $uri): void
    {
        if (null !== $this->maxCycles && $this->connectionAttempts >= $this->maxCycles) {
            $this->stop();

            return;
        }
        ++$this->connectionAttempts;
        $generation = ++$this->connectionGeneration;
        $this->transport->connect(
            $uri,
            function () use ($generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                $this->reconnectScheduled = false;
                $this->awaitingPong = false;
                ++$this->pingGeneration;
                try {
                    $result = $this->session->onConnected(
                        [$this->loginSigner->buildLoginArgs(
                            $this->config->apiKey,
                            $this->config->apiSecret,
                            $this->config->apiPassphrase,
                            (string) $this->clock->now()->getTimestamp(),
                        )],
                        $this->clock->now(),
                    );
                    foreach ($result->outgoingCommands as $command) {
                        $this->transport->send($command);
                    }
                } catch (\Throwable) {
                    $this->failClosed('login', 'okx_private_ws_connection_failed');

                    return;
                }
                $this->armLoginDeadline($generation);
                $this->logger->info('okx_private_ws.state', $this->logContext('login_sent'));
            },
            function (string $message) use ($generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                try {
                    $this->onMessage($message, $generation);
                } catch (\Throwable) {
                    $this->failClosed('message', 'okx_private_ws_message_invalid');
                }
            },
            function (?int $code) use ($generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                $this->handleConnectionFailure('okx_private_ws_connection_closed', $code);
            },
            function (\Throwable $error) use ($generation): void {
                if (!$this->isCurrentConnection($generation)) {
                    return;
                }
                $this->failClosed('connection', 'okx_private_ws_connection_failed');
            },
        );
    }

    private function failClosed(string $channel, string $code): void
    {
        $this->logger->error('okx_private_ws.state', $this->logContext('not_ready', $channel, $code));
        $this->handleConnectionFailure($code);
        $this->transport->close();
    }

    private function isCurrentConnection(int $generation): bool
    {
        return !$this->stopping && $generation === $this->connectionGeneration;
    }

    private function onMessage(string $payload, int $generation): void
    {
        if ('pong' === $payload) {
            $this->awaitingPong = false;
            ++$this->pingGeneration;
            $this->session->onHeartbeat($this->clock->now());
            $this->publishStatus($this->session->status());

            return;
        }

        $message = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($message) || array_is_list($message)) {
            throw new \InvalidArgumentException('okx_private_ws_message_invalid');
        }

        $wasAuthenticated = $this->session->status()->authenticated;
        $result = $this->session->onMessage($message, $this->clock->now());
        foreach ($result->outgoingCommands as $command) {
            $this->transport->send($command);
        }
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
            $this->cancelDeadline($this->loginDeadlineTimer);
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

        if ($this->isReady()) {
            $this->cancelDeadline($this->readinessDeadlineTimer);
            $this->reconnectAttempt = 0;
        }

        $this->publishStatus($this->session->status());
    }

    private function armLoginDeadline(int $generation): void
    {
        $this->cancelDeadline($this->loginDeadlineTimer);
        $this->loginDeadlineTimer = $this->addOneShotTimer(
            self::LOGIN_TIMEOUT_SECONDS,
            function () use ($generation): void {
                if (!$this->isCurrentConnection($generation)
                    || $this->session->status()->authenticated) {
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

        return $status->authenticated
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

    private function handleConnectionFailure(string $code, ?int $closeCode = null): void
    {
        if ($this->stopping || $this->reconnectScheduled) {
            return;
        }

        ++$this->connectionGeneration;
        $this->cancelDeadline($this->loginDeadlineTimer);
        $this->cancelDeadline($this->readinessDeadlineTimer);
        $this->awaitingPong = false;
        ++$this->pingGeneration;
        $this->session->onDisconnected($this->clock->now());
        if (!$this->publishStatus($this->session->status(), true)) {
            return;
        }
        $this->logger->warning('okx_private_ws.state', $this->logContext(
            'reconnecting',
            'connection',
            $code,
        ));

        $this->scheduleReconnect();
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
                && null !== $this->uri
                && $this->publishStatus($this->session->status(), true)) {
                $this->connect($this->uri);
            }
        });
    }

    private function assertConfiguration(): string
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

        $uri = $this->config->wsPrivateUri();
        $this->endpointId = $this->endpointGuard->assertAllowed($uri);

        return $uri;
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
                $this->scheduleReconnect();
                $this->transport->close();
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
