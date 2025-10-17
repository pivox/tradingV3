<?php
namespace App\Worker;

use App\Infra\BitmartWsClient;
use App\Infra\AuthHandler;
use React\EventLoop\Loop;

final class MainWorker
{
    private bool $isRunning = false;
    private ?BitmartWsClient $publicWsClient = null;
    private ?BitmartWsClient $privateWsClient = null;
    private ?AuthHandler $authHandler = null;
    private ?KlineWorker $klineWorker = null;
    private ?OrderWorker $orderWorker = null;
    private ?PositionWorker $positionWorker = null;

    public function __construct(
        private string $publicWsUri,
        private string $privateWsUri,
        private ?string $apiKey = null,
        private ?string $apiSecret = null,
        private ?string $apiMemo = null,
        private int $subscribeBatch = 20,
        private int $subscribeDelayMs = 200,
        private int $pingIntervalS = 15,
        private int $reconnectDelayS = 5
    ) {}

    public function run(): void
    {
        if ($this->isRunning) {
            fwrite(STDERR, "[MAIN] Worker is already running\n");
            return;
        }

        $this->isRunning = true;
        fwrite(STDOUT, "[MAIN] Starting BitMart WebSocket workers...\n");

        $this->initializeWorkers();
        $this->startWorkers();
        $this->setupReconnectionHandlers();
    }

    private function initializeWorkers(): void
    {
        // Client WebSocket public (pour les klines)
        $this->publicWsClient = new BitmartWsClient($this->publicWsUri);
        
        // Client WebSocket privé (pour les ordres et positions)
        $this->privateWsClient = new BitmartWsClient(
            $this->privateWsUri,
            $this->apiKey,
            $this->apiSecret,
            $this->apiMemo
        );

        // Gestionnaire d'authentification
        $this->authHandler = new AuthHandler($this->privateWsClient);

        // Workers
        $this->klineWorker = new KlineWorker(
            $this->publicWsClient,
            $this->subscribeBatch,
            $this->subscribeDelayMs,
            $this->pingIntervalS
        );

        $this->orderWorker = new OrderWorker(
            $this->privateWsClient,
            $this->authHandler,
            $this->subscribeBatch,
            $this->subscribeDelayMs
        );

        $this->positionWorker = new PositionWorker(
            $this->privateWsClient,
            $this->authHandler,
            $this->subscribeBatch,
            $this->subscribeDelayMs
        );

        fwrite(STDOUT, "[MAIN] Workers initialized\n");
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
            fwrite(STDOUT, "[MAIN] Private WebSocket connected, authenticating...\n");
            $this->authHandler->authenticate();
        });

        fwrite(STDOUT, "[MAIN] All workers started\n");
    }

    private function setupReconnectionHandlers(): void
    {
        // Reconnexion automatique pour le client public
        $this->publicWsClient->onClose(function() {
            fwrite(STDOUT, "[MAIN] Public WebSocket disconnected, reconnecting in {$this->reconnectDelayS}s...\n");
            Loop::addTimer($this->reconnectDelayS, function() {
                $this->publicWsClient->connect();
            });
        });

        // Reconnexion automatique pour le client privé
        $this->privateWsClient->onClose(function() {
            fwrite(STDOUT, "[MAIN] Private WebSocket disconnected, reconnecting in {$this->reconnectDelayS}s...\n");
            $this->authHandler->onConnectionLost();
            Loop::addTimer($this->reconnectDelayS, function() {
                $this->privateWsClient->connect();
            });
        });

        // Gestion des erreurs
        $this->publicWsClient->onError(function(\Throwable $e) {
            fwrite(STDERR, "[MAIN] Public WebSocket error: " . $e->getMessage() . "\n");
        });

        $this->privateWsClient->onError(function(\Throwable $e) {
            fwrite(STDERR, "[MAIN] Private WebSocket error: " . $e->getMessage() . "\n");
        });
    }

    // Méthodes publiques pour contrôler les workers
    public function subscribeToKlines(string $symbol, array $timeframes): void
    {
        if (!$this->klineWorker) {
            fwrite(STDERR, "[MAIN] KlineWorker not initialized\n");
            return;
        }

        $this->klineWorker->subscribe($symbol, $timeframes);
        fwrite(STDOUT, "[MAIN] Subscribed to klines: {$symbol} " . implode(',', $timeframes) . "\n");
    }

    public function unsubscribeFromKlines(string $symbol, array $timeframes): void
    {
        if (!$this->klineWorker) {
            fwrite(STDERR, "[MAIN] KlineWorker not initialized\n");
            return;
        }

        $this->klineWorker->unsubscribe($symbol, $timeframes);
        fwrite(STDOUT, "[MAIN] Unsubscribed from klines: {$symbol} " . implode(',', $timeframes) . "\n");
    }

    public function subscribeToOrders(): void
    {
        if (!$this->orderWorker) {
            fwrite(STDERR, "[MAIN] OrderWorker not initialized\n");
            return;
        }

        $this->orderWorker->subscribeToOrders();
        fwrite(STDOUT, "[MAIN] Subscribed to orders\n");
    }

    public function unsubscribeFromOrders(): void
    {
        if (!$this->orderWorker) {
            fwrite(STDERR, "[MAIN] OrderWorker not initialized\n");
            return;
        }

        $this->orderWorker->unsubscribeFromOrders();
        fwrite(STDOUT, "[MAIN] Unsubscribed from orders\n");
    }

    public function subscribeToPositions(): void
    {
        if (!$this->positionWorker) {
            fwrite(STDERR, "[MAIN] PositionWorker not initialized\n");
            return;
        }

        $this->positionWorker->subscribeToPositions();
        fwrite(STDOUT, "[MAIN] Subscribed to positions\n");
    }

    public function unsubscribeFromPositions(): void
    {
        if (!$this->positionWorker) {
            fwrite(STDERR, "[MAIN] PositionWorker not initialized\n");
            return;
        }

        $this->positionWorker->unsubscribeFromPositions();
        fwrite(STDOUT, "[MAIN] Unsubscribed from positions\n");
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
        fwrite(STDOUT, "[MAIN] Stopping workers...\n");

        // Fermer les connexions WebSocket
        if ($this->publicWsClient && $this->publicWsClient->isConnected()) {
            $this->publicWsClient->send(['action' => 'close']);
        }

        if ($this->privateWsClient && $this->privateWsClient->isConnected()) {
            $this->privateWsClient->send(['action' => 'close']);
        }

        fwrite(STDOUT, "[MAIN] Workers stopped\n");
    }
}





