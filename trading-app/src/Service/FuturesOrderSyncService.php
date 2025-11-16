<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Entity\FuturesPlanOrder;
use App\MtfRunner\Service\FuturesOrderSyncService as RunnerFuturesOrderSyncService;

/**
 * Alias de compatibilité pour FuturesOrderSyncService
 * 
 * @deprecated Utilisez App\MtfRunner\Service\FuturesOrderSyncService à la place
 */
final class FuturesOrderSyncService
{
    public function __construct(
        private readonly RunnerFuturesOrderSyncService $runnerService,
    ) {
    }

    public function syncOrderFromApi(array $orderData): ?FuturesOrder
    {
        return $this->runnerService->syncOrderFromApi($orderData);
    }

    public function syncPlanOrderFromApi(array $orderData): ?FuturesPlanOrder
    {
        return $this->runnerService->syncPlanOrderFromApi($orderData);
    }

    public function syncTradeFromApi(array $tradeData): ?FuturesOrderTrade
    {
        return $this->runnerService->syncTradeFromApi($tradeData);
    }

    public function syncOrderFromWebSocket(array $eventData): ?FuturesOrder
    {
        return $this->runnerService->syncOrderFromWebSocket($eventData);
    }
}
