<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Enum\Timeframe;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;
use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use Brick\Math\BigDecimal;

final class BitmartWsClient
{
    private const WS_URL = 'wss://ws-manager-compress.bitmart.com/api?protocol=1.1';
    private const PING_INTERVAL = 25; // secondes
    private const RECONNECT_DELAY = 5; // secondes
    private const MAX_RECONNECT_ATTEMPTS = 3;

    private ?WebSocket $connection = null;
    private int $reconnectAttempts = 0;
    private bool $isConnected = false;
    private array $subscriptions = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ConnectorInterface $connector,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Se connecte au WebSocket BitMart
     */
    public function connect(): void
    {
        $this->logger->info('Connecting to BitMart WebSocket', ['url' => self::WS_URL]);

        $connector = new Connector($this->loop, $this->connector);
        
        $connector(self::WS_URL)
            ->then(function (WebSocket $conn) {
                $this->connection = $conn;
                $this->isConnected = true;
                $this->reconnectAttempts = 0;
                
                $this->logger->info('Connected to BitMart WebSocket');

                // Gérer les messages reçus
                $conn->on('message', function ($msg) {
                    $this->handleMessage($msg);
                });

                // Gérer la fermeture de connexion
                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('WebSocket connection closed', [
                        'code' => $code,
                        'reason' => $reason
                    ]);
                    $this->isConnected = false;
                    $this->scheduleReconnect();
                });

                // Gérer les erreurs
                $conn->on('error', function ($error) {
                    $this->logger->error('WebSocket error', ['error' => $error->getMessage()]);
                    $this->isConnected = false;
                    $this->scheduleReconnect();
                });

                // Démarrer le ping
                $this->startPing();

                // Resubscribe aux canaux précédents
                $this->resubscribe();

            }, function (\Exception $e) {
                $this->logger->error('Failed to connect to BitMart WebSocket', [
                    'error' => $e->getMessage()
                ]);
                $this->scheduleReconnect();
            });
    }

    /**
     * S'abonne aux klines d'un symbole et timeframe
     */
    public function subscribeKlines(string $symbol, Timeframe $timeframe): void
    {
        $channel = $this->getKlineChannel($symbol, $timeframe);
        $this->subscribe($channel);
    }

    /**
     * Se désabonne des klines d'un symbole et timeframe
     */
    public function unsubscribeKlines(string $symbol, Timeframe $timeframe): void
    {
        $channel = $this->getKlineChannel($symbol, $timeframe);
        $this->unsubscribe($channel);
    }

    /**
     * S'abonne à un canal
     */
    private function subscribe(string $channel): void
    {
        if (!$this->isConnected || !$this->connection) {
            $this->logger->warning('Cannot subscribe: WebSocket not connected', ['channel' => $channel]);
            return;
        }

        $message = [
            'action' => 'subscribe',
            'args' => [$channel]
        ];

        $this->connection->send(json_encode($message));
        $this->subscriptions[$channel] = true;
        
        $this->logger->info('Subscribed to channel', ['channel' => $channel]);
    }

    /**
     * Se désabonne d'un canal
     */
    private function unsubscribe(string $channel): void
    {
        if (!$this->isConnected || !$this->connection) {
            return;
        }

        $message = [
            'action' => 'unsubscribe',
            'args' => [$channel]
        ];

        $this->connection->send(json_encode($message));
        unset($this->subscriptions[$channel]);
        
        $this->logger->info('Unsubscribed from channel', ['channel' => $channel]);
    }

    /**
     * Gère les messages reçus du WebSocket
     */
    private function handleMessage($msg): void
    {
        try {
            $data = json_decode($msg->getPayload(), true);
            
            if (!isset($data['table'])) {
                return;
            }

            $table = $data['table'];
            $klineData = $data['data'] ?? [];

            if (str_contains($table, 'klineBin')) {
                $this->handleKlineMessage($table, $klineData);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error handling WebSocket message', [
                'error' => $e->getMessage(),
                'message' => $msg->getPayload()
            ]);
        }
    }

    /**
     * Gère les messages de klines
     */
    private function handleKlineMessage(string $table, array $klineData): void
    {
        foreach ($klineData as $kline) {
            try {
                $timeframe = $this->extractTimeframeFromTable($table);
                $symbol = $kline['symbol'] ?? '';
                
                if (!$timeframe || !$symbol) {
                    continue;
                }

                $klineDto = new KlineDto(
                    symbol: $symbol,
                    timeframe: $timeframe,
                    openTime: new \DateTimeImmutable('@' . ($kline['open_time'] / 1000)),
                    open: BigDecimal::of($kline['open']),
                    high: BigDecimal::of($kline['high']),
                    low: BigDecimal::of($kline['low']),
                    close: BigDecimal::of($kline['close']),
                    volume: BigDecimal::of($kline['volume']),
                    source: 'WEBSOCKET'
                );

                $this->logger->debug('Received kline from WebSocket', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe->value,
                    'open_time' => $klineDto->openTime->format('Y-m-d H:i:s')
                ]);

                // Ici, on pourrait émettre un événement ou appeler un callback
                // pour traiter la kline reçue

            } catch (\Exception $e) {
                $this->logger->error('Error processing kline message', [
                    'error' => $e->getMessage(),
                    'kline' => $kline
                ]);
            }
        }
    }

    /**
     * Extrait le timeframe du nom de la table
     */
    private function extractTimeframeFromTable(string $table): ?Timeframe
    {
        if (str_contains($table, 'klineBin1m')) {
            return Timeframe::TF_1M;
        } elseif (str_contains($table, 'klineBin5m')) {
            return Timeframe::TF_5M;
        } elseif (str_contains($table, 'klineBin15m')) {
            return Timeframe::TF_15M;
        } elseif (str_contains($table, 'klineBin1h')) {
            return Timeframe::TF_1H;
        } elseif (str_contains($table, 'klineBin4h')) {
            return Timeframe::TF_4H;
        }

        return null;
    }

    /**
     * Génère le nom du canal pour les klines
     */
    private function getKlineChannel(string $symbol, Timeframe $timeframe): string
    {
        $tfMap = [
            Timeframe::TF_1M => '1m',
            Timeframe::TF_5M => '5m',
            Timeframe::TF_15M => '15m',
            Timeframe::TF_1H => '1h',
            Timeframe::TF_4H => '4h'
        ];

        $tfString = $tfMap[$timeframe] ?? '1m';
        return "futures/klineBin{$tfString}:{$symbol}";
    }

    /**
     * Démarre le ping périodique
     */
    private function startPing(): void
    {
        $this->loop->addPeriodicTimer(self::PING_INTERVAL, function () {
            if ($this->isConnected && $this->connection) {
                $this->connection->send(json_encode(['action' => 'ping']));
                $this->logger->debug('Sent ping to WebSocket');
            }
        });
    }

    /**
     * Programme une reconnexion
     */
    private function scheduleReconnect(): void
    {
        if ($this->reconnectAttempts >= self::MAX_RECONNECT_ATTEMPTS) {
            $this->logger->error('Max reconnection attempts reached');
            return;
        }

        $this->reconnectAttempts++;
        $delay = self::RECONNECT_DELAY * $this->reconnectAttempts;

        $this->logger->info('Scheduling reconnection', [
            'attempt' => $this->reconnectAttempts,
            'delay' => $delay
        ]);

        $this->loop->addTimer($delay, function () {
            $this->connect();
        });
    }

    /**
     * Resubscribe aux canaux précédents
     */
    private function resubscribe(): void
    {
        foreach (array_keys($this->subscriptions) as $channel) {
            $this->subscribe($channel);
        }
    }

    /**
     * Ferme la connexion WebSocket
     */
    public function disconnect(): void
    {
        if ($this->connection) {
            $this->connection->close();
            $this->isConnected = false;
            $this->logger->info('Disconnected from BitMart WebSocket');
        }
    }

    /**
     * Vérifie si la connexion est active
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    /**
     * Retourne les abonnements actifs
     */
    public function getSubscriptions(): array
    {
        return array_keys($this->subscriptions);
    }
}




