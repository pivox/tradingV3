<?php
namespace App\Worker;

use App\Infra\BitmartWsClient;
use App\Infra\AuthHandler;
use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PositionWorker
{
    private array $subscribedChannels = [];
    private array $pendingSubscriptions = [];
    private array $pendingUnsubscriptions = [];
    private array $lastPositions = [];
    private LoggerInterface $logger;

    public function __construct(
        private BitmartWsClient $wsClient,
        private AuthHandler $authHandler,
        private int $subscribeBatch = 10,
        private int $subscribeDelayMs = 200,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function subscribeToPositions(): void
    {
        $channel = 'futures/position';
        
        if (in_array($channel, $this->subscribedChannels)) {
            $this->logger->info('Already subscribed to positions channel', ['channel' => 'ws-position']);
            return;
        }

        $this->pendingSubscriptions[] = $channel;
        $this->logger->info('Queued subscription to positions channel', ['channel' => 'ws-position']);
    }

    public function unsubscribeFromPositions(): void
    {
        $channel = 'futures/position';
        
        if (!in_array($channel, $this->subscribedChannels)) {
            $this->logger->info('Not subscribed to positions channel', ['channel' => 'ws-position']);
            return;
        }

        $this->pendingUnsubscriptions[] = $channel;
        $this->logger->info('Queued unsubscription from positions channel', ['channel' => 'ws-position']);
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
            $this->logger->info('Subscribed to position channels', ['channel' => 'ws-position', 'channels' => $batch]);
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
            $this->logger->info('Unsubscribed from position channels', ['channel' => 'ws-position', 'channels' => $batch]);
        }
    }

    private function handleMessage(string $rawMessage): void
    {
        $data = json_decode($rawMessage, true);
        if (!is_array($data)) {
            return;
        }

        // Traitement des messages de position
        if (isset($data['group']) && $data['group'] === 'futures/position') {
            $this->processPositionData($data);
        }
    }

    private function processPositionData(array $data): void
    {
        if (!isset($data['data']) || !is_array($data['data'])) {
            return;
        }

        foreach ($data['data'] as $positionData) {
            $this->processPositionUpdate($positionData);
        }
    }

    private function processPositionUpdate(array $positionData): void
    {
        $symbol = $positionData['symbol'] ?? 'unknown';
        $holdVolume = $positionData['hold_volume'] ?? '0';
        $positionType = $positionData['position_type'] ?? null;
        $openType = $positionData['open_type'] ?? null;
        $frozenVolume = $positionData['frozen_volume'] ?? '0';
        $closeVolume = $positionData['close_volume'] ?? '0';
        $holdAvgPrice = $positionData['hold_avg_price'] ?? '0';
        $closeAvgPrice = $positionData['close_avg_price'] ?? '0';
        $openAvgPrice = $positionData['open_avg_price'] ?? '0';
        $liquidatePrice = $positionData['liquidate_price'] ?? '0';
        $createTime = $positionData['create_time'] ?? 0;
        $updateTime = $positionData['update_time'] ?? 0;
        $positionMode = $positionData['position_mode'] ?? 'unknown';

        $positionTypeText = match($positionType) {
            1 => 'LONG',
            2 => 'SHORT',
            default => 'UNKNOWN'
        };

        $openTypeText = match($openType) {
            1 => 'ISOLATED',
            2 => 'CROSS',
            default => 'UNKNOWN'
        };

        // Détecter les changements de position
        $positionKey = $symbol . '_' . $positionType;
        $lastPosition = $this->lastPositions[$positionKey] ?? null;
        
        $hasChanged = false;
        if ($lastPosition === null || 
            $lastPosition['hold_volume'] !== $holdVolume ||
            $lastPosition['hold_avg_price'] !== $holdAvgPrice ||
            $lastPosition['liquidate_price'] !== $liquidatePrice) {
            $hasChanged = true;
        }

        if ($hasChanged) {
            $this->logger->info('[POSITION]', [
                'channel' => 'ws-position',
                'symbol' => $symbol,
                'position_type' => $positionTypeText,
                'open_type' => $openTypeText,
                'hold_volume' => $holdVolume,
                'hold_avg_price' => $holdAvgPrice,
                'liquidate_price' => $liquidatePrice,
                'position_mode' => $positionMode,
                'frozen_volume' => $frozenVolume,
                'close_volume' => $closeVolume,
            ]);

            // Mettre à jour le cache des positions
            $this->lastPositions[$positionKey] = [
                'symbol' => $symbol,
                'hold_volume' => $holdVolume,
                'hold_avg_price' => $holdAvgPrice,
                'liquidate_price' => $liquidatePrice,
                'position_type' => $positionType,
                'open_type' => $openType,
                'update_time' => $updateTime
            ];
        }

        // Ici vous pouvez ajouter votre logique de persistance ou de notification
        // Par exemple : envoyer vers une base de données, un message queue, etc.
    }

    private function handleConnectionOpened(): void
    {
        $this->logger->info('Position WS connection opened', ['channel' => 'ws-position']);
        // Les souscriptions seront traitées automatiquement par le timer
    }

    private function handleConnectionLost(): void
    {
        $this->logger->warning('Position WS connection lost, clearing subscriptions', ['channel' => 'ws-position']);
        $this->subscribedChannels = [];
        $this->authHandler->onConnectionLost();
    }

    public function getSubscribedChannels(): array
    {
        return $this->subscribedChannels;
    }

    public function isSubscribedToPositions(): bool
    {
        return in_array('futures/position', $this->subscribedChannels);
    }

    public function getLastPositions(): array
    {
        return $this->lastPositions;
    }

    public function getPositionForSymbol(string $symbol): ?array
    {
        foreach ($this->lastPositions as $position) {
            if ($position['symbol'] === $symbol) {
                return $position;
            }
        }
        return null;
    }
}




