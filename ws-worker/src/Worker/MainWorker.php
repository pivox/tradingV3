<?php
namespace App\Worker;

use App\Infra\BitmartWsClient;
use App\Infra\AuthHandler;
use App\Order\OrderSignalDispatcher;
use App\Order\OrderSignalFactory;
use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MainWorker
{
    private bool $isRunning = false;
    private ?BitmartWsClient $publicWsClient = null;
    private ?BitmartWsClient $privateWsClient = null;
    private ?AuthHandler $authHandler = null;
    private ?KlineWorker $klineWorker = null;
    private ?OrderWorker $orderWorker = null;
    private ?PositionWorker $positionWorker = null;
    private ?OrderSignalDispatcher $orderSignalDispatcher = null;
    private ?OrderSignalFactory $orderSignalFactory = null;

    public function __construct(
        private string $publicWsUri,
        private string $privateWsUri,
        private ?string $apiKey = null,
        private ?string $apiSecret = null,
        private ?string $apiMemo = null,
        private int $subscribeBatch = 20,
        private int $subscribeDelayMs = 200,
        private int $pingIntervalS = 15,
        private int $reconnectDelayS = 5,
        private ?LoggerInterface $logger = null,
        ?OrderSignalDispatcher $orderSignalDispatcher = null,
        ?OrderSignalFactory $orderSignalFactory = null,
    ) {
        $this->logger = $this->logger ?? new NullLogger();
        $this->orderSignalDispatcher = $orderSignalDispatcher;
        $this->orderSignalFactory = $orderSignalFactory ?? new OrderSignalFactory();
    }

    public function run(): void
    {
        if ($this->isRunning) {
        $this->logger?->warning('Worker is already running', ['channel' => 'ws-main']);
        return;
        }

        $this->isRunning = true;
        $this->logger?->info('Starting BitMart WebSocket workers...', ['channel' => 'ws-main']);

        $this->initializeWorkers();
        $this->startWorkers();
        $this->setupReconnectionHandlers();
    }

    private function initializeWorkers(): void
    {
        // Client WebSocket public (pour les klines)
        $this->publicWsClient = new BitmartWsClient($this->publicWsUri, logger: $this->logger);
        
        // Client WebSocket privé (pour les ordres et positions)
        $this->privateWsClient = new BitmartWsClient(
            $this->privateWsUri,
            $this->apiKey,
            $this->apiSecret,
            $this->apiMemo,
            $this->logger
        );

        // Gestionnaire d'authentification
        $this->authHandler = new AuthHandler($this->privateWsClient, $this->logger);

        // Workers
        $this->klineWorker = new KlineWorker(
            $this->publicWsClient,
            $this->subscribeBatch,
            $this->subscribeDelayMs,
            $this->pingIntervalS,
            $this->logger
        );

        $this->orderWorker = new OrderWorker(
            $this->privateWsClient,
            $this->authHandler,
            $this->subscribeBatch,
            $this->subscribeDelayMs,
            $this->logger,
            $this->orderSignalDispatcher,
            $this->orderSignalFactory
        );

        $this->positionWorker = new PositionWorker(
            $this->privateWsClient,
            $this->authHandler,
            $this->subscribeBatch,
            $this->subscribeDelayMs,
            $this->logger
        );

        $this->logger?->info('Workers initialized', ['channel' => 'ws-main']);
    }

    private function startWorkers(): void
    {
        // Démarrer les connexions WebSocket
        $this->publicWsClient->connect();
        $this->privateWsClient->connect();

        // Démarrer les workers
        $this->klineWorker->run();
        $this->orderWorker->run();
        $this->positionWorker->run();

        // Authentification pour les canaux privés
        $this->privateWsClient->onOpen(function() {
        $this->logger?->info('Private WebSocket connected, authenticating...', ['channel' => 'ws-main']);
            $this->authHandler->authenticate();
        });

        $this->logger?->info('All workers started', ['channel' => 'ws-main']);
    }

    private function setupReconnectionHandlers(): void
    {
        // Reconnexion automatique pour le client public
        $this->publicWsClient->onClose(function() {
            $this->logger?->warning('Public WebSocket disconnected, reconnecting', ['channel' => 'ws-main', 'delay_s' => $this->reconnectDelayS]);
            Loop::addTimer($this->reconnectDelayS, function() {
                $this->publicWsClient->connect();
            });
        });

        // Reconnexion automatique pour le client privé
        $this->privateWsClient->onClose(function() {
            $this->logger?->warning('Private WebSocket disconnected, reconnecting', ['channel' => 'ws-main', 'delay_s' => $this->reconnectDelayS]);
            $this->authHandler->onConnectionLost();
            Loop::addTimer($this->reconnectDelayS, function() {
                $this->privateWsClient->connect();
            });
        });

        // Gestion des erreurs
        $this->publicWsClient->onError(function(\Throwable $e) {
            $this->logger?->error('Public WebSocket error', ['channel' => 'ws-main', 'error' => $e->getMessage()]);
        });

        $this->privateWsClient->onError(function(\Throwable $e) {
            $this->logger?->error('Private WebSocket error', ['channel' => 'ws-main', 'error' => $e->getMessage()]);
        });
    }

    // Méthodes publiques pour contrôler les workers
    public function subscribeToKlines(string $symbol, array $timeframes): void
    {
        if (!$this->klineWorker) {
            $this->logger?->error('KlineWorker not initialized', ['channel' => 'ws-main']);
            return;
        }

        $this->klineWorker->subscribe($symbol, $timeframes);
        $this->logger?->info('Subscribed to klines', ['channel' => 'ws-main', 'symbol' => $symbol, 'tfs' => $timeframes]);
    }

    public function unsubscribeFromKlines(string $symbol, array $timeframes): void
    {
        if (!$this->klineWorker) {
            $this->logger?->error('KlineWorker not initialized', ['channel' => 'ws-main']);
            return;
        }

        $this->klineWorker->unsubscribe($symbol, $timeframes);
        $this->logger?->info('Unsubscribed from klines', ['channel' => 'ws-main', 'symbol' => $symbol, 'tfs' => $timeframes]);
    }

    public function subscribeToOrders(): void
    {
        if (!$this->orderWorker) {
            $this->logger?->error('OrderWorker not initialized', ['channel' => 'ws-main']);
            return;
        }

        $this->orderWorker->subscribeToOrders();
        $this->logger?->info('Subscribed to orders', ['channel' => 'ws-main']);
    }

    public function unsubscribeFromOrders(): void
    {
        if (!$this->orderWorker) {
            $this->logger?->error('OrderWorker not initialized', ['channel' => 'ws-main']);
            return;
        }

        $this->orderWorker->unsubscribeFromOrders();
        $this->logger?->info('Unsubscribed from orders', ['channel' => 'ws-main']);
    }

    public function subscribeToPositions(): void
    {
        if (!$this->positionWorker) {
            $this->logger?->error('PositionWorker not initialized', ['channel' => 'ws-main']);
            return;
        }

        $this->positionWorker->subscribeToPositions();
        $this->logger?->info('Subscribed to positions', ['channel' => 'ws-main']);
    }

    public function unsubscribeFromPositions(): void
    {
        if (!$this->positionWorker) {
            $this->logger?->error('PositionWorker not initialized', ['channel' => 'ws-main']);
            return;
        }

        $this->positionWorker->unsubscribeFromPositions();
        $this->logger?->info('Unsubscribed from positions', ['channel' => 'ws-main']);
    }

    // Méthodes pour obtenir l'état des workers
    public function getStatus(): array
    {
        return [
            'is_running' => $this->isRunning,
            'public_ws_connected' => $this->publicWsClient?->isConnected() ?? false,
            'private_ws_connected' => $this->privateWsClient?->isConnected() ?? false,
            'authenticated' => $this->authHandler?->isAuthenticated() ?? false,
            'kline_channels' => $this->klineWorker?->getSubscribedChannels() ?? [],
            'order_subscribed' => $this->orderWorker?->isSubscribedToOrders() ?? false,
            'position_subscribed' => $this->positionWorker?->isSubscribedToPositions() ?? false,
            'positions' => $this->positionWorker?->getLastPositions() ?? []
        ];
    }

    public function stop(): void
    {
        if (!$this->isRunning) {
            return;
        }

        $this->isRunning = false;
        $this->logger?->info('Stopping workers...', ['channel' => 'ws-main']);

        // Fermer les connexions WebSocket
        if ($this->publicWsClient && $this->publicWsClient->isConnected()) {
            $this->publicWsClient->send(['action' => 'close']);
        }

        if ($this->privateWsClient && $this->privateWsClient->isConnected()) {
            $this->privateWsClient->send(['action' => 'close']);
        }

        $this->logger?->info('Workers stopped', ['channel' => 'ws-main']);
    }
}


