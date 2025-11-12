<?php

declare(strict_types=1);

namespace App\WebSocket\Service;

use App\Provider\Bitmart\WebSocket\BitmartWebsocketPublic;
use Psr\Log\LoggerInterface;
use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as SocketConnector;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Service pour gérer les connexions WebSocket publiques BitMart (klines)
 * Connexion à la demande, pas de persistance, dump des messages
 */
final class WsPublicKlinesService
{
    // URL WebSocket publique BitMart (en dur pour le moment)
    private const WS_PUBLIC_URL = 'wss://openapi-ws-v2.bitmart.com/api?protocol=1.1';

    private ?WebSocket $wsConnection = null;
    private ?LoopInterface $loop = null;
    private array $subscribedTopics = []; // Liste des topics actuellement souscrits
    private bool $isConnecting = false; // Flag pour éviter les connexions multiples simultanées

    public function __construct(
        private readonly BitmartWebsocketPublic $wsBuilder,
        #[Autowire(service: 'monolog.logger.bitmart')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Connecte au WebSocket public si pas déjà connecté
     * Démarre la boucle d'événements pour établir la connexion
     */
    private function ensureConnected(): void
    {
        if ($this->wsConnection !== null) {
            return;
        }

        if ($this->isConnecting) {
            // Attendre que la connexion en cours se termine
            $this->waitForConnection(5);
            return;
        }

        $this->isConnecting = true;
        $this->loop = \React\EventLoop\Loop::get();
        $this->logger->info('[WsPublicKlines] Connecting to public WebSocket', [
            'url' => self::WS_PUBLIC_URL,
        ]);

        $connector = new Connector(
            $this->loop,
            new SocketConnector($this->loop)
        );

        $connectionPromise = $connector(self::WS_PUBLIC_URL)
            ->then(function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->isConnecting = false;
                $this->logger->info('[WsPublicKlines] WebSocket connected');

                // Gérer les messages reçus
                $conn->on('message', function ($msg) {
                    $this->handleMessage($msg);
                });

                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('[WsPublicKlines] WebSocket closed', [
                        'code' => $code,
                        'reason' => $reason,
                    ]);
                    $this->wsConnection = null;
                    $this->subscribedTopics = [];
                    $this->isConnecting = false;
                });

                $conn->on('error', function ($error) {
                    $this->logger->error('[WsPublicKlines] WebSocket error', [
                        'error' => $error->getMessage(),
                    ]);
                    $this->isConnecting = false;
                });

                // Ping toutes les 30 secondes pour maintenir la connexion
                $this->loop->addPeriodicTimer(30, function () use ($conn) {
                    if ($this->wsConnection !== null) {
                        $ping = $this->wsBuilder->buildPing();
                        $conn->send(json_encode($ping));
                    }
                });
            }, function (\Exception $e) {
                $this->logger->error('[WsPublicKlines] Connection failed', [
                    'error' => $e->getMessage(),
                ]);
                $this->wsConnection = null;
                $this->isConnecting = false;
                $this->loop->stop();
            });

        // Timer pour arrêter la boucle après un timeout (5 secondes) si pas connecté
        $timeout = 5;
        $connectionEstablished = false;
        $this->loop->addTimer($timeout, function () use (&$connectionEstablished) {
            if ($this->wsConnection === null && !$connectionEstablished) {
                $this->logger->error('[WsPublicKlines] Connection timeout');
                $this->isConnecting = false;
                $this->loop->stop();
            }
        });

        // Marquer la connexion comme établie dans le callback
        $connectionPromise->then(function () use (&$connectionEstablished) {
            $connectionEstablished = true;
            // Arrêter temporairement la boucle pour permettre à subscribe() de continuer
            // La commande la relancera ensuite pour recevoir les messages
            $this->loop->stop();
        });

        // Démarrer la boucle (bloquant jusqu'à connexion ou timeout)
        $this->loop->run();
    }

    /**
     * Attend que la connexion soit établie (avec timeout)
     */
    private function waitForConnection(int $timeoutSeconds): void
    {
        if ($this->loop === null) {
            return;
        }

        $startTime = microtime(true);
        $connectionEstablished = false;

        // Timer pour timeout
        $this->loop->addTimer($timeoutSeconds, function () use (&$connectionEstablished) {
            if (!$connectionEstablished) {
                $this->loop->stop();
            }
        });

        // Vérifier périodiquement si connecté
        $this->loop->addPeriodicTimer(0.1, function () use (&$connectionEstablished, $startTime, $timeoutSeconds) {
            if ($this->wsConnection !== null) {
                $connectionEstablished = true;
                $this->loop->stop();
            } elseif ((microtime(true) - $startTime) >= $timeoutSeconds) {
                $this->loop->stop();
            }
        });

        $this->loop->run();
    }

    /**
     * Souscrit à des klines pour un symbole et des timeframes
     */
    public function subscribe(string $symbol, array $timeframes, ?\App\Provider\Context\ExchangeContext $context = null): void
    {
        $this->ensureConnected();

        if ($this->wsConnection === null) {
            $this->logger->error('[WsPublicKlines] Cannot subscribe: not connected');
            return;
        }

        // Construire le payload de souscription
        // Note: Spot WS topics non implémentés—fallback futures en attendant
        $payload = $this->wsBuilder->buildSubscribeKlines($symbol, $timeframes);
        $topics = $payload['args'] ?? [];

        // Envoyer la souscription
        $this->wsConnection->send(json_encode($payload));

        // Ajouter aux topics souscrits
        foreach ($topics as $topic) {
            if (!in_array($topic, $this->subscribedTopics, true)) {
                $this->subscribedTopics[] = $topic;
            }
        }

        $this->logger->info('[WsPublicKlines] Subscribed', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
            'topics' => $topics,
            'exchange' => $context?->exchange->value ?? 'bitmart',
            'market_type' => $context?->marketType->value ?? 'perpetual',
        ]);
    }

    /**
     * Désabonne des klines pour un symbole et des timeframes
     */
    public function unsubscribe(string $symbol, array $timeframes, ?\App\Provider\Context\ExchangeContext $context = null): void
    {
        if ($this->wsConnection === null) {
            $this->logger->warning('[WsPublicKlines] Cannot unsubscribe: not connected');
            return;
        }

        // Construire les topics à désabonner
        $topics = [];
        foreach ($timeframes as $tf) {
            $topic = $this->wsBuilder->getKlineTopic($symbol, $tf);
            $topics[] = $topic;
        }

        // Construire le payload de désabonnement
        $payload = $this->wsBuilder->buildUnsubscribe($topics);

        // Envoyer le désabonnement
        $this->wsConnection->send(json_encode($payload));

        // Retirer des topics souscrits
        $this->subscribedTopics = array_diff($this->subscribedTopics, $topics);

        $this->logger->info('[WsPublicKlines] Unsubscribed', [
            'symbol' => $symbol,
            'timeframes' => $timeframes,
            'topics' => $topics,
            'exchange' => $context?->exchange->value ?? 'bitmart',
            'market_type' => $context?->marketType->value ?? 'perpetual',
        ]);
    }

    /**
     * Gère les messages reçus du WebSocket
     */
    private function handleMessage($msg): void
    {
        try {
            // Convertir le message en string
            if (is_string($msg)) {
                $messageStr = $msg;
            } elseif (is_object($msg) && method_exists($msg, 'getPayload')) {
                $messageStr = $msg->getPayload();
            } else {
                $messageStr = (string) $msg;
            }

            $data = json_decode($messageStr, true, 512, \JSON_THROW_ON_ERROR);

            // Dump du message complet pour debug (affichage direct + logs)
            echo "\n";
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "[WsPublicKlines] MESSAGE RECEIVED\n";
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "Raw JSON:\n";
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            echo "───────────────────────────────────────────────────────────────\n";
            
            // Dump structuré
            if (isset($data['event'])) {
                echo "Event: " . $data['event'] . "\n";
            }
            if (isset($data['table'])) {
                echo "Table: " . $data['table'] . "\n";
            }
            if (isset($data['topic'])) {
                echo "Topic: " . $data['topic'] . "\n";
            }
            if (isset($data['errorCode'])) {
                echo "Error Code: " . $data['errorCode'] . "\n";
            }
            if (isset($data['errorMessage'])) {
                echo "Error Message: " . $data['errorMessage'] . "\n";
            }
            if (isset($data['data']) && is_array($data['data'])) {
                echo "Data Count: " . count($data['data']) . "\n";
                if (count($data['data']) > 0) {
                    echo "First Item:\n";
                    echo json_encode($data['data'][0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
            echo "═══════════════════════════════════════════════════════════════\n";
            echo "\n";

            // Logs détaillés
            $this->logger->info('[WsPublicKlines] Message received', [
                'raw_message' => $messageStr,
                'parsed_data' => $data,
                'event' => $data['event'] ?? null,
                'table' => $data['table'] ?? null,
                'topic' => $data['topic'] ?? null,
            ]);

            // Gérer les réponses de souscription
            if (isset($data['event']) && $data['event'] === 'subscribe') {
                echo "✅ Subscribe response received\n";
                $this->logger->info('[WsPublicKlines] Subscribe response', [
                    'error_code' => $data['errorCode'] ?? null,
                    'error_message' => $data['errorMessage'] ?? null,
                    'args' => $data['args'] ?? [],
                ]);
                return;
            }

            // Gérer les données de klines
            if (isset($data['table']) && str_starts_with($data['table'], 'futures/klineBin')) {
                echo "📊 Kline data received for table: " . $data['table'] . "\n";
                if (isset($data['data']) && is_array($data['data'])) {
                    foreach ($data['data'] as $index => $kline) {
                        echo "  Kline #" . ($index + 1) . ":\n";
                        if (isset($kline['symbol'])) {
                            echo "    Symbol: " . $kline['symbol'] . "\n";
                        }
                        if (isset($kline['candle'])) {
                            $candle = $kline['candle'];
                            echo "    Candle: " . json_encode($candle, JSON_UNESCAPED_SLASHES) . "\n";
                        }
                    }
                }
                $this->logger->info('[WsPublicKlines] Kline data received', [
                    'table' => $data['table'] ?? null,
                    'data_count' => isset($data['data']) && is_array($data['data']) ? count($data['data']) : 0,
                    'data' => $data['data'] ?? null,
                ]);
                // TODO: Parser et sauvegarder les klines (pas de persistance pour le moment)
            }
        } catch (\Throwable $e) {
            echo "\n❌ ERROR handling message: " . $e->getMessage() . "\n";
            $this->logger->error('[WsPublicKlines] Error handling message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Déconnecte du WebSocket
     */
    public function disconnect(): void
    {
        if ($this->wsConnection !== null) {
            $this->wsConnection->close();
            $this->wsConnection = null;
            $this->subscribedTopics = [];
            $this->logger->info('[WsPublicKlines] Disconnected');
        }
    }

    /**
     * Retourne la liste des topics actuellement souscrits
     */
    public function getSubscribedTopics(): array
    {
        return $this->subscribedTopics;
    }

    /**
     * Vérifie si le service est connecté
     */
    public function isConnected(): bool
    {
        return $this->wsConnection !== null;
    }
}
