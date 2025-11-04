<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\Provider\OrderProviderInterface;
use App\Provider\Bitmart\WebSocket\BitmartWebsocketPrivate;
use App\Repository\FuturesOrderRepository;
use App\Repository\OrderIntentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector as SocketConnector;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WsAgentService
{
    private ?WebSocket $wsConnection = null;
    private ?LoopInterface $loop = null;
    private bool $isAuthenticated = false;
    private bool $isSubscribed = false;
    private array $trackedOrders = []; // tracking_key => ['order_id', 'client_order_id', 'symbol', 'status']
    private array $trackedOrderAliases = []; // alias => tracking_key
    private ?string $wsUrl = null;

    public function __construct(
        private readonly BitmartWebsocketPrivate $wsBuilder,
        private readonly FuturesOrderRepository $futuresOrderRepository,
        private readonly OrderIntentRepository $orderIntentRepository,
        private readonly FuturesOrderSyncService $syncService,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly OrderProviderInterface $orderProvider,
        #[Autowire(service: 'monolog.logger.ws_agent')]
        private readonly LoggerInterface $logger,
        #[Autowire('%env(BITMART_WS_PRIVATE_URL)%')]
        private readonly string $bitmartWsPrivateUrl,
        #[Autowire('%env(TRADING_APP_BASE_URI)%')]
        private readonly string $tradingAppBaseUri,
        #[Autowire('%env(TRADING_APP_ORDER_SUBMITTED_PATH)%')]
        private readonly string $orderSubmittedPath,
        #[Autowire('%env(WS_TOPICS)%')]
        private readonly string $wsTopics,
    ) {
        $this->wsUrl = $this->bitmartWsPrivateUrl;
    }

    public function run(): void
    {
        $this->loop = Loop::get();
        
        // Démarrer le serveur HTTP interne pour recevoir les notifications
        $this->startHttpServer();
        
        // Connecter au WebSocket
        $this->connect();

        // Gérer les signaux pour arrêt propre
        if (function_exists('pcntl_signal')) {
            $this->loop->addSignal(SIGTERM, function () {
                $this->logger->info('[WsAgent] SIGTERM received, shutting down');
                $this->disconnect();
                $this->loop->stop();
            });
            $this->loop->addSignal(SIGINT, function () {
                $this->logger->info('[WsAgent] SIGINT received, shutting down');
                $this->disconnect();
                $this->loop->stop();
            });
        }

        $this->loop->run();
    }

    /**
     * Démarre le serveur HTTP interne pour recevoir les notifications de tracking
     */
    private function startHttpServer(): void
    {
        $httpServer = new HttpServer($this->loop, function (ServerRequestInterface $request) {
            $path = $request->getUri()->getPath();
            $method = $request->getMethod();

            // Endpoint pour tracker un ordre
            if ($path === '/internal/track-order' && $method === 'POST') {
                $body = json_decode($request->getBody()->getContents(), true);
                if (!is_array($body)) {
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'error',
                        'reason' => 'invalid_json',
                    ]));
                }

                $clientOrderId = $body['client_order_id'] ?? null;
                $orderId = $body['order_id'] ?? null;
                $symbol = $body['symbol'] ?? null;

                if (!$clientOrderId && !$orderId) {
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'error',
                        'reason' => 'missing_client_order_id_or_order_id',
                    ]));
                }

                $this->trackOrder($clientOrderId, $orderId, $symbol ?? '');

                return new Response(202, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'ok',
                    'message' => 'order_tracked',
                ]));
            }

            // Endpoint pour synchroniser les ordres ouverts
            if ($path === '/internal/sync-orders' && $method === 'POST') {
                $body = json_decode($request->getBody()->getContents(), true);
                if (!is_array($body)) {
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'error',
                        'reason' => 'invalid_json',
                    ]));
                }

                $symbol = $body['symbol'] ?? null;
                if (!$symbol) {
                    return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'error',
                        'reason' => 'missing_symbol',
                    ]));
                }

                try {
                    $count = $this->syncOpenOrders($symbol);
                    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'ok',
                        'symbol' => $symbol,
                        'synced_count' => $count,
                    ]));
                } catch (\Throwable $e) {
                    $this->logger->error('[WsAgent] Error syncing orders', [
                        'symbol' => $symbol,
                        'error' => $e->getMessage(),
                    ]);
                    return new Response(500, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'error',
                        'reason' => 'sync_failed',
                        'error' => $e->getMessage(),
                    ]));
                }
            }

            // Endpoint pour récupérer les ordres
            if ($path === '/orders' && $method === 'GET') {
                $queryParams = $request->getQueryParams();
                $symbol = $queryParams['symbol'] ?? null;
                $status = $queryParams['status'] ?? null;
                $kind = $queryParams['kind'] ?? null; // 'main', 'sl', 'tp'
                $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 100;
                $limit = max(1, min(500, $limit));

                try {
                    $orders = $this->futuresOrderRepository->findWithFilters($symbol, $status, $kind, $limit);
                    $data = array_map(function ($order) {
                        return $this->serializeOrder($order);
                    }, $orders);

                    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'ok',
                        'count' => count($data),
                        'data' => $data,
                    ]));
                } catch (\Throwable $e) {
                    $this->logger->error('[WsAgent] Error fetching orders', [
                        'error' => $e->getMessage(),
                    ]);
                    return new Response(500, ['Content-Type' => 'application/json'], json_encode([
                        'status' => 'error',
                        'reason' => 'fetch_failed',
                        'error' => $e->getMessage(),
                    ]));
                }
            }

            // Health check
            if ($path === '/health' && $method === 'GET') {
                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'status' => 'ok',
                    'authenticated' => $this->isAuthenticated,
                    'subscribed' => $this->isSubscribed,
                    'tracked_orders_count' => count($this->trackedOrders),
                ]));
            }

            return new Response(404, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'error',
                'reason' => 'not_found',
            ]));
        });

        $socket = new SocketServer('0.0.0.0:8090', [], $this->loop);
        $httpServer->listen($socket);

        $this->logger->info('[WsAgent] HTTP server started on port 8090');
    }

    private function connect(): void
    {
        $this->logger->info('[WsAgent] Connecting to WebSocket', ['url' => $this->wsUrl]);

        $connector = new Connector(
            $this->loop,
            new SocketConnector($this->loop)
        );

        $connector($this->wsUrl)
            ->then(function (WebSocket $conn) {
                $this->wsConnection = $conn;
                $this->logger->info('[WsAgent] WebSocket connected');

                // Authentification
                $loginMsg = $this->wsBuilder->buildLogin();
                $conn->send(json_encode($loginMsg));

                // Gérer les messages
                $conn->on('message', function ($msg) {
                    // Convertir le message Ratchet en string
                    // Ratchet passe un objet MessageInterface qui implémente __toString()
                    try {
                        if (is_string($msg)) {
                            $messageStr = $msg;
                        } elseif (is_object($msg) && method_exists($msg, 'getPayload')) {
                            $messageStr = $msg->getPayload();
                        } else {
                            $messageStr = (string) $msg;
                        }
                        $this->handleMessage($messageStr);
                    } catch (\Throwable $e) {
                        $this->logger->error('[WsAgent] Error processing message', [
                            'error' => $e->getMessage(),
                            'msg_type' => get_class($msg),
                        ]);
                    }
                });

                $conn->on('close', function ($code = null, $reason = null) {
                    $this->logger->warning('[WsAgent] WebSocket closed', [
                        'code' => $code,
                        'reason' => $reason,
                    ]);
                    $this->isAuthenticated = false;
                    $this->isSubscribed = false;
                    $this->wsConnection = null;

                    // Reconnexion après 5 secondes
                    $this->loop->addTimer(5, function () {
                        $this->connect();
                    });
                });

                $conn->on('error', function ($error) {
                    $this->logger->error('[WsAgent] WebSocket error', [
                        'error' => $error->getMessage(),
                    ]);
                });

                // Ping toutes les 30 secondes
                $this->loop->addPeriodicTimer(30, function () use ($conn) {
                    if ($this->isAuthenticated) {
                        $ping = $this->wsBuilder->buildPing();
                        $conn->send(json_encode($ping));
                    }
                });
            }, function (\Exception $e) {
                $this->logger->error('[WsAgent] Connection failed', [
                    'error' => $e->getMessage(),
                ]);

                // Reconnexion après 5 secondes
                $this->loop->addTimer(5, function () {
                    $this->connect();
                });
            });
    }

    private function handleMessage(string $msg): void
    {
        try {
            $data = json_decode($msg, true, 512, \JSON_THROW_ON_ERROR);

            // Gérer les réponses d'authentification
            if (isset($data['event']) && $data['event'] === 'login') {
                if (isset($data['errorCode']) && (int)$data['errorCode'] === 0) {
                    $this->isAuthenticated = true;
                    $this->logger->info('[WsAgent] Authenticated');
                    $this->subscribeToTopics();
                } else {
                    $this->logger->error('[WsAgent] Authentication failed', [
                        'error_code' => $data['errorCode'] ?? null,
                        'error_message' => $data['errorMessage'] ?? null,
                    ]);
                }
                return;
            }

            // Gérer les réponses de souscription
            if (isset($data['event']) && $data['event'] === 'subscribe') {
                if (isset($data['errorCode']) && (int)$data['errorCode'] === 0) {
                    $this->isSubscribed = true;
                    $this->logger->info('[WsAgent] Subscribed to topics', [
                        'topics' => $data['args'] ?? [],
                    ]);
                }
                return;
            }

            // Gérer les données d'ordres
            if (isset($data['group']) && $data['group'] === 'futures/order') {
                $this->handleOrderUpdate($data);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[WsAgent] Error handling message', [
                'error' => $e->getMessage(),
                'message' => $msg,
            ]);
        }
    }

    private function subscribeToTopics(): void
    {
        if (!$this->isAuthenticated || $this->isSubscribed) {
            return;
        }

        $topics = array_filter(array_map('trim', explode(',', $this->wsTopics)));
        if (empty($topics)) {
            $topics = ['futures/order'];
        }

        $subscribeMsg = $this->wsBuilder->buildSubscribeMultiple($topics);
        $this->wsConnection->send(json_encode($subscribeMsg));

        $this->logger->info('[WsAgent] Subscribing to topics', ['topics' => $topics]);
    }

    private function handleOrderUpdate(array $data): void
    {
        if (!isset($data['data']) || !is_array($data['data'])) {
            return;
        }

        foreach ($data['data'] as $orderData) {
            $action = $orderData['action'] ?? null;
            $order = $orderData['order'] ?? [];

            $orderId = $order['order_id'] ?? null;
            $clientOrderId = $order['client_order_id'] ?? null;
            $symbol = $order['symbol'] ?? null;
            $state = $order['state'] ?? null;

            if (!$orderId && !$clientOrderId) {
                continue;
            }

            // Synchroniser l'ordre dans la BDD
            if ($this->syncService) {
                try {
                    $normalized = [
                        'order_id' => $orderId,
                        'client_order_id' => $clientOrderId,
                        'symbol' => $symbol,
                        'side' => $order['side'] ?? null,
                        'type' => $order['type'] ?? null,
                        'state' => $state,
                        'price' => $order['price'] ?? null,
                        'size' => $order['size'] ?? null,
                        'deal_size' => $order['deal_size'] ?? null,
                        'deal_avg_price' => $order['deal_avg_price'] ?? null,
                        'leverage' => $order['leverage'] ?? null,
                        'open_type' => $order['open_type'] ?? null,
                        'position_mode' => $order['position_mode'] ?? null,
                        'update_time_ms' => $order['update_time'] ?? null,
                    ];
                    $this->syncService->syncOrderFromWebSocket($normalized);
                } catch (\Throwable $e) {
                    $this->logger->error('[WsAgent] Error syncing order', [
                        'order_id' => $orderId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Si l'ordre est soumis (action=2, SUBMIT_ORDER) et qu'on le track
            // State 2 = CHECK (en vérification), mais on considère que c'est soumis
            if ($action === 2 && ($orderId || $clientOrderId)) {
                $this->notifyOrderSubmitted($orderId, $clientOrderId, $symbol);
            }
        }
    }

    /**
     * Notifie trading-app-php qu'un ordre a été soumis
     */
    private function notifyOrderSubmitted(?string $orderId, ?string $clientOrderId, ?string $symbol): void
    {
        // Vérifier si on track cet ordre
        $trackingKey = $this->resolveTrackingKey($clientOrderId, $orderId);
        if ($trackingKey === null) {
            return;
        }

        $tracked = $this->trackedOrders[$trackingKey];
        if ($tracked['status'] === 'submitted') {
            return; // Déjà notifié
        }

        try {
            $url = rtrim($this->tradingAppBaseUri, '/') . $this->orderSubmittedPath;
            $payload = [
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
                'symbol' => $symbol,
                'submitted_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            ];

            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'timeout' => 5.0,
            ]);

            if ($response->getStatusCode() === 200 || $response->getStatusCode() === 202) {
                $this->trackedOrders[$trackingKey]['status'] = 'submitted';
                $this->logger->info('[WsAgent] Notified order submitted', [
                    'order_id' => $orderId,
                    'client_order_id' => $clientOrderId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[WsAgent] Failed to notify order submitted', [
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ajoute un ordre à tracker (appelé depuis l'endpoint HTTP)
     */
    public function trackOrder(?string $clientOrderId, ?string $orderId, string $symbol): void
    {
        $clientOrderId = $this->sanitizeTrackingKey($clientOrderId);
        $orderId = $this->sanitizeTrackingKey($orderId);

        $primaryKey = $clientOrderId ?? $orderId;
        if ($primaryKey === null) {
            $this->logger->warning('[WsAgent] Attempted to track order without identifiers');

            return;
        }

        $this->trackedOrders[$primaryKey] = [
            'order_id' => $orderId,
            'client_order_id' => $clientOrderId,
            'symbol' => $symbol,
            'status' => 'pending',
        ];

        foreach (array_unique(array_filter([$clientOrderId, $orderId], static fn ($value) => $value !== null)) as $alias) {
            $this->trackedOrderAliases[$alias] = $primaryKey;
        }

        // S'assurer que la connexion est établie et qu'on est abonné
        if (!$this->isSubscribed) {
            $this->subscribeToTopics();
        }

        $this->logger->info('[WsAgent] Tracking order', [
            'client_order_id' => $clientOrderId,
            'order_id' => $orderId,
            'symbol' => $symbol,
        ]);
    }

    private function resolveTrackingKey(?string $clientOrderId, ?string $orderId): ?string
    {
        $candidates = array_filter([
            $this->sanitizeTrackingKey($clientOrderId),
            $this->sanitizeTrackingKey($orderId),
        ], static fn ($value) => $value !== null);

        foreach ($candidates as $candidate) {
            if (isset($this->trackedOrders[$candidate])) {
                return $candidate;
            }

            if (isset($this->trackedOrderAliases[$candidate])) {
                $primary = $this->trackedOrderAliases[$candidate];

                if (isset($this->trackedOrders[$primary])) {
                    return $primary;
                }
            }
        }

        return null;
    }

    private function sanitizeTrackingKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $normalized = trim($key);

        return $normalized !== '' ? $normalized : null;
    }

    private function disconnect(): void
    {
        if ($this->wsConnection) {
            $this->wsConnection->close();
            $this->wsConnection = null;
        }
        $this->isAuthenticated = false;
        $this->isSubscribed = false;
    }

    /**
     * Synchronise les ordres ouverts depuis l'API BitMart pour un symbole donné
     * @return int Nombre d'ordres synchronisés
     */
    public function syncOpenOrders(string $symbol): int
    {
        try {
            // getOpenOrders() synchronise déjà les ordres via syncService dans BitmartOrderProvider
            $orders = $this->orderProvider->getOpenOrders($symbol);
            $count = count($orders);
            
            $this->logger->info('[WsAgent] Synced open orders', [
                'symbol' => $symbol,
                'count' => $count,
            ]);

            return $count;
        } catch (\Throwable $e) {
            $this->logger->error('[WsAgent] Error syncing open orders', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Sérialise un ordre pour l'API
     * @param \App\Entity\FuturesOrder $order
     * @return array<string,mixed>
     */
    private function serializeOrder(\App\Entity\FuturesOrder $order): array
    {
        $rawData = $order->getRawData();
        $kind = $this->detectOrderKind($rawData);

        return [
            'id' => $order->getId(),
            'order_id' => $order->getOrderId(),
            'client_order_id' => $order->getClientOrderId(),
            'symbol' => $order->getSymbol(),
            'kind' => $kind,
            'side' => $order->getSide(),
            'type' => $order->getType(),
            'status' => $order->getStatus(),
            'price' => $order->getPrice(),
            'size' => $order->getSize(),
            'filled_size' => $order->getFilledSize(),
            'filled_notional' => $order->getFilledNotional(),
            'open_type' => $order->getOpenType(),
            'position_mode' => $order->getPositionMode(),
            'leverage' => $order->getLeverage(),
            'fee' => $order->getFee(),
            'fee_currency' => $order->getFeeCurrency(),
            'account' => $order->getAccount(),
            'created_time' => $order->getCreatedTime(),
            'updated_time' => $order->getUpdatedTime(),
            'created_at' => $order->getCreatedAt()->format('c'),
            'updated_at' => $order->getUpdatedAt()->format('c'),
        ];
    }

    /**
     * Détecte le type d'ordre (main, sl, tp) depuis les données brutes
     * @param array<string,mixed> $rawData
     * @return string 'main', 'sl', 'tp', ou 'unknown'
     */
    private function detectOrderKind(array $rawData): string
    {
        // Si l'ordre a preset_take_profit_price dans rawData, c'est un TP
        if (isset($rawData['preset_take_profit_price']) && $rawData['preset_take_profit_price'] !== null) {
            return 'tp';
        }

        // Si l'ordre a preset_stop_loss_price dans rawData, c'est un SL
        if (isset($rawData['preset_stop_loss_price']) && $rawData['preset_stop_loss_price'] !== null) {
            return 'sl';
        }

        // Sinon, c'est probablement un ordre principal
        return 'main';
    }
}

