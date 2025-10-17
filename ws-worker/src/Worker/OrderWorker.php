<?php
namespace App\Worker;

use App\Infra\BitmartWsClient;
use App\Infra\AuthHandler;
use React\EventLoop\Loop;

final class OrderWorker
{
    private array $subscribedChannels = [];
    private array $pendingSubscriptions = [];
    private array $pendingUnsubscriptions = [];

    public function __construct(
        private BitmartWsClient $wsClient,
        private AuthHandler $authHandler,
        private int $subscribeBatch = 10,
        private int $subscribeDelayMs = 200
    ) {}

    public function subscribeToOrders(): void
    {
        $channel = 'futures/order';
        
        if (in_array($channel, $this->subscribedChannels)) {
            fwrite(STDOUT, "[ORDER] Already subscribed to orders channel\n");
            return;
        }

        $this->pendingSubscriptions[] = $channel;
        fwrite(STDOUT, "[ORDER] Queued subscription to orders channel\n");
    }

    public function unsubscribeFromOrders(): void
    {
        $channel = 'futures/order';
        
        if (!in_array($channel, $this->subscribedChannels)) {
            fwrite(STDOUT, "[ORDER] Not subscribed to orders channel\n");
            return;
        }

        $this->pendingUnsubscriptions[] = $channel;
        fwrite(STDOUT, "[ORDER] Queued unsubscription from orders channel\n");
    }

    public function run(): void
    {
        // Gestion des souscriptions par lots
        Loop::addPeriodicTimer($this->subscribeDelayMs / 1000, function() {
            $this->processPendingSubscriptions();
            $this->processPendingUnsubscriptions();
        });

        // Gestion des messages WebSocket
        $this->wsClient->onMessage(function(string $rawMessage) {
            $this->handleMessage($rawMessage);
        });

        // Gestion de la reconnexion
        $this->wsClient->onClose(function() {
            $this->handleConnectionLost();
        });

        $this->wsClient->onOpen(function() {
            $this->handleConnectionOpened();
        });
    }

    private function processPendingSubscriptions(): void
    {
        if (empty($this->pendingSubscriptions) || !$this->wsClient->isConnected()) {
            return;
        }

        if (!$this->authHandler->isAuthenticated()) {
            return; // Attendre l'authentification
        }

        $batch = array_splice($this->pendingSubscriptions, 0, $this->subscribeBatch);
        if (!empty($batch)) {
            $this->wsClient->subscribe($batch);
            $this->subscribedChannels = array_merge($this->subscribedChannels, $batch);
            fwrite(STDOUT, "[ORDER] Subscribed to channels: " . implode(', ', $batch) . "\n");
        }
    }

    private function processPendingUnsubscriptions(): void
    {
        if (empty($this->pendingUnsubscriptions) || !$this->wsClient->isConnected()) {
            return;
        }

        $batch = array_splice($this->pendingUnsubscriptions, 0, $this->subscribeBatch);
        if (!empty($batch)) {
            $this->wsClient->unsubscribe($batch);
            $this->subscribedChannels = array_diff($this->subscribedChannels, $batch);
            fwrite(STDOUT, "[ORDER] Unsubscribed from channels: " . implode(', ', $batch) . "\n");
        }
    }

    private function handleMessage(string $rawMessage): void
    {
        $data = json_decode($rawMessage, true);
        if (!is_array($data)) {
            return;
        }

        // Traitement des messages d'ordre
        if (isset($data['group']) && $data['group'] === 'futures/order') {
            $this->processOrderData($data);
        }
    }

    private function processOrderData(array $data): void
    {
        if (!isset($data['data']) || !is_array($data['data'])) {
            return;
        }

        foreach ($data['data'] as $orderData) {
            $this->processOrderUpdate($orderData);
        }
    }

    private function processOrderUpdate(array $orderData): void
    {
        $action = $orderData['action'] ?? null;
        $order = $orderData['order'] ?? null;

        if (!$order) {
            return;
        }

        $orderId = $order['order_id'] ?? 'unknown';
        $symbol = $order['symbol'] ?? 'unknown';
        $state = $order['state'] ?? null;
        $side = $order['side'] ?? null;
        $type = $order['type'] ?? 'unknown';
        $price = $order['price'] ?? '0';
        $size = $order['size'] ?? '0';
        $dealSize = $order['deal_size'] ?? '0';
        $dealAvgPrice = $order['deal_avg_price'] ?? '0';

        $actionText = match($action) {
            1 => 'MATCH_DEAL',
            2 => 'SUBMIT_ORDER',
            3 => 'CANCEL_ORDER',
            4 => 'LIQUIDATE_CANCEL_ORDER',
            5 => 'ADL_CANCEL_ORDER',
            6 => 'PART_LIQUIDATE',
            7 => 'BANKRUPTCY_ORDER',
            8 => 'PASSIVE_ADL_MATCH_DEAL',
            9 => 'ACTIVE_ADL_MATCH_DEAL',
            default => 'UNKNOWN'
        };

        $stateText = match($state) {
            1 => 'APPROVAL',
            2 => 'CHECK',
            4 => 'FINISH',
            default => 'UNKNOWN'
        };

        fwrite(STDOUT, sprintf(
            "[ORDER] %s | %s | %s | %s | Price: %s | Size: %s | Deal: %s@%s | State: %s\n",
            $actionText,
            $orderId,
            $symbol,
            $type,
            $price,
            $size,
            $dealSize,
            $dealAvgPrice,
            $stateText
        ));

        // Ici vous pouvez ajouter votre logique de persistance ou de notification
        // Par exemple : envoyer vers une base de données, un message queue, etc.
    }

    private function handleConnectionOpened(): void
    {
        fwrite(STDOUT, "[ORDER] WebSocket connection opened\n");
        // Les souscriptions seront traitées automatiquement par le timer
    }

    private function handleConnectionLost(): void
    {
        fwrite(STDOUT, "[ORDER] WebSocket connection lost, clearing subscriptions\n");
        $this->subscribedChannels = [];
        $this->authHandler->onConnectionLost();
    }

    public function getSubscribedChannels(): array
    {
        return $this->subscribedChannels;
    }

    public function isSubscribedToOrders(): bool
    {
        return in_array('futures/order', $this->subscribedChannels);
    }
}





