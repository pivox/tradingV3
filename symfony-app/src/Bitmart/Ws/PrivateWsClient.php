<?php

declare(strict_types=1);

namespace App\Bitmart\Ws;

use App\Service\Config\BitmartWsConfig;
use Psr\Log\LoggerInterface;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

use function Ratchet\Client\connect;

final class PrivateWsClient
{
    /** Intervalle de ping (keepalive) en secondes. */
    private const PING_INTERVAL_SEC = 25;

    /** Backoff de reconnexion (ms). */
    private const RECONNECT_BASE_MS = 1_000;
    private const RECONNECT_MAX_MS  = 30_000;

    /** @var TimerInterface|null */
    private ?TimerInterface $pingTimer = null;

    /** @var array<string, list<callable>> */
    private array $groupHandlers = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly BitmartWsConfig $config,
    ) {}

    public function run(array $topics): void
    {
        $this->connectAndRun($topics, 0);
        // Démarre la boucle d’événements et garde le process vivant
        $this->loop->run();
    }

    private function connectAndRun(array $topics, int $attempt): void
    {
        $url = $this->config->getWsBaseUrl();
        $this->logger->info('[BitMart WS] Connecting…', ['url' => $url, 'attempt' => $attempt]);

        connect($url)->then(
            function (WebSocket $conn) use ($topics) {
                $this->logger->info('[BitMart WS] Connected, sending login…');

                // --- LOGIN ---
                $tsMs = (string) (int) \floor(\microtime(true) * 1000);
                $payloadToSign = $tsMs . '#' . $this->config->getApiMemo() . '#bitmart.WebSocket';
                $sign = \hash_hmac('sha256', $payloadToSign, $this->config->getSecretKey());

                $login = [
                    'action' => 'access',
                    'args'   => [
                        $this->config->getApiKey(),
                        $tsMs,
                        $sign,
                        $this->config->getDevice(),
                    ],
                ];

                $conn->send(\json_encode($login, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

                // Keepalive
                $this->installPing($conn);

                // Handlers
                $conn->on('message', function ($msg) use ($conn, $topics) {
                    $text = (string) $msg;

                    try {
                        $decoded = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);

                        if (isset($decoded['data']['items']) && is_array($decoded['data']['items'])) {
                            foreach ($decoded['data']['items'] as &$item) {
                                if (isset($item['ts'])) {
                                    $tz = new \DateTimeZone('Europe/Paris');
                                    $datetime = new \DateTime('@' . $item['ts']);
                                    $datetime->setTimezone($tz);
                                    $item['ts'] = $datetime->format('H:i:s');
                                }
                            }
                        }

                        $text = json_encode($decoded, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);
                    } catch (\Exception $e) {
                        // En cas d'erreur (ex: JSON invalide), on continue avec le texte original.
                    }

                    dump($text);
                    $this->logger->debug('[BitMart WS] << ' . $text);

                    $data = null;
                    try {
                        /** @var array<string,mixed> $data */
                        $data = \json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        $this->logger->warning('[BitMart WS] JSON decode failed', ['error' => $e->getMessage()]);
                        return;
                    }

                    // Login confirmations -> subscribe
                    if (isset($data['event']) && \in_array($data['event'], ['access', 'login', 'login_success', 'access:success'], true)) {
                        $this->logger->info('[BitMart WS] Login confirmé, abonnement aux topics…', ['topics' => $topics]);
                        $this->subscribe($conn, $topics);
                        return;
                    }
                    if (isset($data['action']) && $data['action'] === 'access' && ($data['success'] ?? false) === true) {
                        $this->logger->info('[BitMart WS] Login success=true, abonnement…', ['topics' => $topics]);
                        $this->subscribe($conn, $topics);
                        return;
                    }

                    // Ping/Pong
                    if (($data['action'] ?? null) === 'ping') {
                        $conn->send(\json_encode(['action' => 'pong'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
                        return;
                    }

                    // --- Routage par groupe ---
                    $group = (string)($data['group'] ?? '');
                    if ($group !== '' && isset($this->groupHandlers[$group])) {
                        foreach ($this->groupHandlers[$group] as $handler) {
                            try {
                                $handler($data);
                            } catch (\Throwable $e) {
                                $this->logger->error('[BitMart WS] group handler failed', ['group' => $group, 'error' => $e->getMessage()]);
                            }
                        }
                    }
                });

                $conn->on('close', function ($code = null, $reason = null) use ($topics) {
                    $this->logger->warning('[BitMart WS] Connection closed', ['code' => $code, 'reason' => $reason]);
                    $this->clearPing();
                    // Reconnect with backoff
                    $this->scheduleReconnect($topics, 1);
                });

                $conn->on('error', function (\Throwable $e) use ($topics) {
                    $this->logger->error('[BitMart WS] Connection error', ['error' => $e->getMessage()]);
                    $this->clearPing();
                    $this->scheduleReconnect($topics, 1);
                });
            },
            function (\Throwable $e) use ($topics, $attempt) {
                $this->logger->error('[BitMart WS] Initial connect failed', ['error' => $e->getMessage()]);
                $this->scheduleReconnect($topics, $attempt + 1);
            }
        );
    }

    /**
     * Abonne le client aux topics fournis.
     * Adaptez la forme du message selon les topics privés BitMart que vous ciblez.
     */
    private function subscribe(WebSocket $conn, array $topics): void
    {
        if ($topics === []) {
            $this->logger->info('[BitMart WS] Aucun topic à souscrire.');
            return;
        }

        $msg = [
            'action' => 'subscribe',
            'args'   => \array_values($topics),
        ];

        try {
            $conn->send(\json_encode($msg, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
            $this->logger->info('[BitMart WS] >> subscribe', ['args' => $msg['args']]);
        } catch (\Throwable $e) {
            $this->logger->error('[BitMart WS] Subscribe send failed', ['error' => $e->getMessage()]);
        }
    }

    /** Permet d’enregistrer un callback pour un groupe WS (ex: "futures/position"). */
    public function onGroup(string $group, callable $handler): void
    {
        $this->groupHandlers[$group] ??= [];
        $this->groupHandlers[$group][] = $handler;
    }

    private function installPing(WebSocket $conn): void
    {
        $this->clearPing();
        $this->pingTimer = $this->loop->addPeriodicTimer(self::PING_INTERVAL_SEC, function () use ($conn) {
            try {
                $conn->send(\json_encode(['action' => 'ping'], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
                $this->logger->debug('[BitMart WS] >> ping');
            } catch (\Throwable $e) {
                $this->logger->warning('[BitMart WS] Ping failed', ['error' => $e->getMessage()]);
            }
        });
    }

    private function clearPing(): void
    {
        if ($this->pingTimer !== null) {
            $this->loop->cancelTimer($this->pingTimer);
            $this->pingTimer = null;
        }
    }

    private function scheduleReconnect(array $topics, int $attempt): void
    {
        $delayMs = (int) \min(self::RECONNECT_MAX_MS, self::RECONNECT_BASE_MS * (2 ** \max(0, $attempt - 1)));
        $this->logger->info('[BitMart WS] Reconnecting…', ['attempt' => $attempt, 'delay_ms' => $delayMs]);

        $this->loop->addTimer($delayMs / 1000, function () use ($topics, $attempt) {
            $this->connectAndRun($topics, $attempt);
        });
    }
}
