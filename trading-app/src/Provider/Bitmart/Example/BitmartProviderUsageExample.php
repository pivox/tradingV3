<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\Example;

use App\Common\Enum\Timeframe;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Provider\MainProvider;
use App\Provider\Bitmart\Service\BitmartMigrationMain;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Exemple d'utilisation des nouveaux providers Bitmart
 */
final class BitmartProviderUsageExample
{
    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autoconfigure(service: 'app.provider.service')]
        private readonly MainProvider         $providerService,

        #[\Symfony\Component\DependencyInjection\Attribute\Autoconfigure(service: 'app.provider.bitmart.migration')]
        private readonly BitmartMigrationMain $migrationService
    ) {}

    /**
     * Exemple d'utilisation du provider de klines
     */
    public function exampleKlineUsage(): void
    {
        $klineProvider = $this->providerService->getKlineProvider();

        // Récupérer les klines pour BTCUSDT en 1H
        $klines = $klineProvider->getKlines('BTCUSDT', Timeframe::TF_1H, 100);

        // Vérifier les gaps
        $hasGaps = $klineProvider->hasGaps('BTCUSDT', Timeframe::TF_1H);

        // Récupérer la dernière kline
        $lastKline = $klineProvider->getLastKline('BTCUSDT', Timeframe::TF_1H);
    }

    /**
     * Exemple d'utilisation du provider de contrats
     */
    public function exampleContractUsage(): void
    {
        $contractProvider = $this->providerService->getContractProvider();

        // Récupérer tous les contrats
        $contracts = $contractProvider->getContracts();

        // Récupérer les détails d'un contrat spécifique
        $contractDetails = $contractProvider->getContractDetails('BTCUSDT');

        // Récupérer le dernier prix
        $lastPrice = $contractProvider->getLastPrice('BTCUSDT');

        // Récupérer le carnet d'ordres
        $orderBook = $contractProvider->getOrderBook('BTCUSDT', 50);
    }

    /**
     * Exemple d'utilisation du provider d'ordres
     */
    public function exampleOrderUsage(): void
    {
        $orderProvider = $this->providerService->getOrderProvider();

        // Placer un ordre
        $order = $orderProvider->placeOrder(
            symbol: 'BTCUSDT',
            side: OrderSide::BUY,
            type: OrderType::LIMIT,
            quantity: 0.001,
            price: 50000.0
        );

        // Récupérer les ordres ouverts
        $openOrders = $orderProvider->getOpenOrders('BTCUSDT');

        // Annuler un ordre
        if ($order) {
            $orderProvider->cancelOrder($order->symbol, $order->orderId);
        }
    }

    /**
     * Exemple d'utilisation du provider de compte
     */
    public function exampleAccountUsage(): void
    {
        $accountProvider = $this->providerService->getAccountProvider();

        // Récupérer les informations du compte
        $accountInfo = $accountProvider->getAccountInfo();

        // Récupérer le solde
        $balance = $accountProvider->getAccountBalance();

        // Récupérer les positions ouvertes
        $positions = $accountProvider->getOpenPositions('BTCUSDT');

        // Récupérer l'historique des trades
        $tradeHistory = $accountProvider->getTradeHistory('BTCUSDT', 50);
    }

    /**
     * Exemple d'utilisation du service de migration
     */
    public function exampleMigrationUsage(): void
    {
        // Utiliser directement les providers Bitmart
        $klineProvider = $this->migrationService->getKlineProvider();
        $contractProvider = $this->migrationService->getContractProvider();
        $orderProvider = $this->migrationService->getOrderProvider();
        $accountProvider = $this->migrationService->getAccountProvider();

        // Vérifier la santé de tous les providers
        $healthStatus = $this->migrationService->healthCheck();
    }

    /**
     * Exemple de vérification de santé
     */
    public function exampleHealthCheck(): void
    {
        $healthStatus = $this->providerService->healthCheck();

        foreach ($healthStatus as $provider => $isHealthy) {
            if ($isHealthy) {
                echo "Provider $provider: OK\n";
            } else {
                echo "Provider $provider: ERROR\n";
            }
        }
    }
}
