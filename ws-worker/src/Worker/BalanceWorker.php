<?php
namespace App\Worker;

use App\Infra\BitmartWsClient;
use App\Infra\AuthHandler;
use App\Balance\BalanceSignalDispatcher;
use App\Balance\BalanceSignalFactory;
use React\EventLoop\Loop;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class BalanceWorker
{
    private array $subscribedChannels = [];
    private array $pendingSubscriptions = [];
    private array $pendingUnsubscriptions = [];
    private ?array $lastBalance = null;
    private LoggerInterface $logger;

    public function __construct(
        private BitmartWsClient $wsClient,
        private AuthHandler $authHandler,
        private int $subscribeBatch = 10,
        private int $subscribeDelayMs = 200,
        ?LoggerInterface $logger = null,
        private ?BalanceSignalDispatcher $balanceSignalDispatcher = null,
        private BalanceSignalFactory $balanceSignalFactory = new BalanceSignalFactory(),
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function subscribeToBalance(): void
    {
        $channel = 'futures/asset:USDT';
        
        if (in_array($channel, $this->subscribedChannels)) {
            $this->logger->info('Already subscribed to balance channel', ['channel' => 'ws-balance']);
            return;
        }

        $this->pendingSubscriptions[] = $channel;
        $this->logger->info('Queued subscription to balance channel', ['channel' => 'ws-balance', 'asset' => 'USDT']);
    }

    public function unsubscribeFromBalance(): void
    {
        $channel = 'futures/asset:USDT';
        
        if (!in_array($channel, $this->subscribedChannels)) {
            $this->logger->info('Not subscribed to balance channel', ['channel' => 'ws-balance']);
            return;
        }

        $this->pendingUnsubscriptions[] = $channel;
        $this->logger->info('Queued unsubscription from balance channel', ['channel' => 'ws-balance', 'asset' => 'USDT']);
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
            $this->logger->info('Subscribed to balance channels', ['channel' => 'ws-balance', 'channels' => $batch]);
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
            $this->logger->info('Unsubscribed from balance channels', ['channel' => 'ws-balance', 'channels' => $batch]);
        }
    }

    private function handleMessage(string $rawMessage): void
    {
        $data = json_decode($rawMessage, true);
        if (!is_array($data)) {
            return;
        }

        // Traitement des messages d'asset
        if (isset($data['group']) && str_starts_with($data['group'], 'futures/asset')) {
            $this->processBalanceData($data);
        }
    }

    private function processBalanceData(array $data): void
    {
        if (!isset($data['data']) || !is_array($data['data'])) {
            return;
        }

        // Le message peut contenir un seul asset ou un tableau d'assets
        $assets = $data['data'];
        if (!isset($assets[0])) {
            $assets = [$assets];
        }

        foreach ($assets as $assetData) {
            $this->processBalanceUpdate($assetData);
        }
    }

    private function processBalanceUpdate(array $assetData): void
    {
        $currency = strtoupper((string)($assetData['currency'] ?? ''));
        
        // Filtrer uniquement USDT
        if ($currency !== 'USDT') {
            return;
        }

        $availableBalance = $assetData['available_balance'] ?? '0';
        $frozenBalance = $assetData['frozen_balance'] ?? '0';
        $equity = $assetData['equity'] ?? '0';
        $unrealizedPnl = $assetData['unrealized_value'] ?? '0';
        $positionDeposit = $assetData['position_deposit'] ?? '0';
        $bonus = $assetData['bonus'] ?? '0';

        // Détecter les changements de balance
        $hasChanged = false;
        if ($this->lastBalance === null || 
            $this->lastBalance['available_balance'] !== $availableBalance ||
            $this->lastBalance['frozen_balance'] !== $frozenBalance ||
            $this->lastBalance['equity'] !== $equity) {
            $hasChanged = true;
        }

        if ($hasChanged) {
            $this->logger->info('[BALANCE]', [
                'channel' => 'ws-balance',
                'currency' => $currency,
                'available_balance' => $availableBalance,
                'frozen_balance' => $frozenBalance,
                'equity' => $equity,
                'unrealized_pnl' => $unrealizedPnl,
                'position_deposit' => $positionDeposit,
                'bonus' => $bonus,
            ]);

            // Mettre à jour le cache
            $this->lastBalance = [
                'currency' => $currency,
                'available_balance' => $availableBalance,
                'frozen_balance' => $frozenBalance,
                'equity' => $equity,
                'unrealized_pnl' => $unrealizedPnl,
                'position_deposit' => $positionDeposit,
                'bonus' => $bonus,
            ];

            // Dispatcher le signal vers trading-app si configuré
            if ($this->balanceSignalDispatcher !== null) {
                $signal = $this->balanceSignalFactory->createFromBitmartEvent($assetData);
                if ($signal !== null) {
                    $this->balanceSignalDispatcher->dispatch($signal);
                }
            }
        }
    }

    private function handleConnectionOpened(): void
    {
        $this->logger->info('Balance WS connection opened', ['channel' => 'ws-balance']);
        // Les souscriptions seront traitées automatiquement par le timer
    }

    private function handleConnectionLost(): void
    {
        $this->logger->warning('Balance WS connection lost, clearing subscriptions', ['channel' => 'ws-balance']);
        $this->subscribedChannels = [];
        $this->authHandler->onConnectionLost();
    }

    public function getSubscribedChannels(): array
    {
        return $this->subscribedChannels;
    }

    public function isSubscribedToBalance(): bool
    {
        return in_array('futures/asset:USDT', $this->subscribedChannels);
    }

    public function getLastBalance(): ?array
    {
        return $this->lastBalance;
    }
}

