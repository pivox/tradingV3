<?php

declare(strict_types=1);

namespace App\Trading\Sync;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\OrderProviderInterface;
use App\Trading\Dto\OrderDto as TradingOrderDto;
use App\Trading\Dto\PositionDto as TradingPositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;
use App\Trading\Event\OrderStateChangedEvent;
use App\Trading\Event\PositionClosedEvent;
use App\Trading\Event\PositionOpenedEvent;
use App\Trading\Storage\OrderStateRepositoryInterface;
use App\Trading\Storage\PositionStateRepositoryInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

final class TradingStateSyncRunner
{
    private ?AccountProviderInterface $accountProvider = null;
    private ?OrderProviderInterface $orderProvider = null;

    public function __construct(
        private readonly ?MainProviderInterface $mainProvider,
        private readonly PositionStateRepositoryInterface $positionStateRepository,
        private readonly OrderStateRepositoryInterface $orderStateRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // Récupérer les providers depuis MainProvider si disponible
        if ($this->mainProvider !== null) {
            try {
                $this->accountProvider = $this->mainProvider->getAccountProvider();
            } catch (\Throwable $e) {
                $this->logger?->warning('[TradingStateSync] AccountProvider not available', [
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $this->orderProvider = $this->mainProvider->getOrderProvider();
            } catch (\Throwable $e) {
                $this->logger?->warning('[TradingStateSync] OrderProvider not available', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Synchronise les positions et ordres depuis l'exchange vers la BDD
     * 
     * @param string $context Contexte de la synchronisation (pour logging)
     * @param string[]|null $symbols Symboles à synchroniser (null = tous)
     */
    public function syncAndDispatch(string $context, ?array $symbols = null): void
    {
        $this->logger?->info('[TradingStateSync] Starting sync', [
            'context' => $context,
            'symbols' => $symbols,
        ]);

        try {
            // 1. Synchroniser les positions ouvertes
            if ($this->accountProvider !== null) {
                $this->syncPositions($symbols);
            }

            // 2. Synchroniser les ordres ouverts
            if ($this->orderProvider !== null) {
                $this->syncOrders($symbols);
            }

            $this->logger?->info('[TradingStateSync] Sync completed', [
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('[TradingStateSync] Sync failed', [
                'context' => $context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Synchronise les positions depuis l'exchange
     * 
     * @param string[]|null $symbols
     */
    private function syncPositions(?array $symbols = null): void
    {
        if ($this->accountProvider === null) {
            return;
        }

        try {
            // Récupérer les positions ouvertes depuis l'exchange
            $openPositions = $this->accountProvider->getOpenPositions();

            // Filtrer par symboles si spécifié
            if ($symbols !== null && $symbols !== []) {
                $openPositions = array_filter(
                    $openPositions,
                    fn($p) => in_array(strtoupper($p->symbol), array_map('strtoupper', $symbols), true)
                );
            }

            $this->logger?->info('[TradingStateSync] Syncing positions', [
                'count' => count($openPositions),
            ]);

            // Récupérer les positions actuellement en BDD pour détecter les nouvelles/fermetures
            $localOpenPositions = $this->positionStateRepository->findLocalOpenPositions($symbols);
            $localPositionsMap = [];
            foreach ($localOpenPositions as $localPos) {
                $key = strtoupper($localPos->symbol) . '_' . strtoupper($localPos->side->value);
                $localPositionsMap[$key] = $localPos;
            }

            $syncedSymbols = [];
            $newPositionsMap = [];

            // Sauvegarder les positions ouvertes depuis l'exchange
            foreach ($openPositions as $providerPosition) {
                try {
                    $tradingPosition = TradingPositionDto::fromProviderDto($providerPosition);
                    $key = strtoupper($tradingPosition->symbol) . '_' . strtoupper($tradingPosition->side->value);
                    
                    // Vérifier si c'est une nouvelle position (pas présente en BDD)
                    $isNewPosition = !isset($localPositionsMap[$key]);
                    
                    $this->positionStateRepository->saveOpenPosition($tradingPosition);
                    $syncedSymbols[] = $tradingPosition->symbol;
                    
                    // Dispatcher l'événement pour les nouvelles positions
                    if ($isNewPosition && $this->eventDispatcher !== null) {
                        $this->eventDispatcher->dispatch(new PositionOpenedEvent(
                            position: $tradingPosition,
                            runId: null,
                            exchange: null,
                            accountId: null,
                            extra: []
                        ));
                    }
                } catch (\Throwable $e) {
                    $this->logger?->error('[TradingStateSync] Failed to sync position', [
                        'symbol' => $providerPosition->symbol ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Détecter les positions fermées (présentes en BDD mais absentes de l'exchange)
            foreach ($localPositionsMap as $key => $localPos) {
                $found = false;
                foreach ($openPositions as $providerPosition) {
                    $providerKey = strtoupper($providerPosition->symbol) . '_' . strtoupper($providerPosition->side->value);
                    if ($providerKey === $key) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    // Position fermée : récupérer les données réelles depuis l'API BitMart
                    try {
                        // 1. Récupérer les trades (fills) pour ce symbole
                        // 2. Récupérer le transaction history avec flow_type=2 (realized PnL)
                        // 3. Optionnellement récupérer l'order history pour les métadonnées
                        $history = $this->createHistoryFromApiData($localPos);
                        
                        if ($history !== null) {
                            $this->positionStateRepository->saveClosedPosition($history);

                            // Déterminer le reasonCode basé sur le PnL
                            $realizedPnlFloat = (float)$history->realizedPnl->__toString();
                            $reasonCode = $realizedPnlFloat < 0.0 ? 'loss_or_stop'
                                : ($realizedPnlFloat > 0.0 ? 'profit_or_tp' : 'closed_flat');

                            // Dispatcher l'événement enrichi
                            if ($this->eventDispatcher !== null) {
                                $this->eventDispatcher->dispatch(new PositionClosedEvent(
                                    positionHistory: $history,
                                    runId: null,
                                    exchange: null,
                                    accountId: null,
                                    reasonCode: $reasonCode,
                                    extra: []
                                ));
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->logger?->warning('[TradingStateSync] Failed to process closed position', [
                            'symbol' => $localPos->symbol,
                            'side' => $localPos->side->value,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->error('[TradingStateSync] Failed to sync positions', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Synchronise les ordres depuis l'exchange
     * 
     * @param string[]|null $symbols
     */
    private function syncOrders(?array $symbols = null): void
    {
        if ($this->orderProvider === null) {
            return;
        }

        try {
            // Récupérer les ordres ouverts depuis l'exchange
            $openOrders = $this->orderProvider->getOpenOrders();

            // Filtrer par symboles si spécifié
            if ($symbols !== null && $symbols !== []) {
                $openOrders = array_filter(
                    $openOrders,
                    fn($o) => in_array(strtoupper($o->symbol), array_map('strtoupper', $symbols), true)
                );
            }

            $this->logger?->info('[TradingStateSync] Syncing orders', [
                'count' => count($openOrders),
            ]);

            // Récupérer les ordres locaux pour détecter les changements de statut
            $localOpenOrders = $this->orderStateRepository->findLocalOpenOrders($symbols);
            $localOrdersMap = [];
            foreach ($localOpenOrders as $localOrder) {
                $localOrdersMap[$localOrder->orderId] = $localOrder;
            }

            // Sauvegarder les ordres ouverts et détecter les changements de statut
            foreach ($openOrders as $providerOrder) {
                try {
                    $tradingOrder = TradingOrderDto::fromProviderDto($providerOrder);
                    $previousOrder = $localOrdersMap[$tradingOrder->orderId] ?? null;
                    
                    // Sauvegarder l'ordre
                    $this->orderStateRepository->saveOrder($tradingOrder);
                    
                    // Dispatcher l'événement si le statut a changé
                    if ($previousOrder !== null && $this->eventDispatcher !== null) {
                        $previousStatus = strtoupper($previousOrder->status->value);
                        $newStatus = strtoupper($tradingOrder->status->value);
                        
                        if ($previousStatus !== $newStatus) {
                            $this->eventDispatcher->dispatch(new OrderStateChangedEvent(
                                order: $tradingOrder,
                                previousStatus: $previousStatus,
                                newStatus: $newStatus,
                                runId: null,
                                exchange: null,
                                accountId: null,
                                extra: []
                            ));
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger?->error('[TradingStateSync] Failed to sync order', [
                        'order_id' => $providerOrder->orderId ?? 'unknown',
                        'symbol' => $providerOrder->symbol ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->error('[TradingStateSync] Failed to sync orders', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Crée un PositionHistoryEntryDto depuis les données API BitMart
     * Utilise GET /contract/private/trades et GET /contract/private/transaction-history
     */
    private function createHistoryFromApiData(TradingPositionDto $localPos): ?PositionHistoryEntryDto
    {
        try {
            if ($this->accountProvider === null) {
                $this->logger?->warning('[TradingStateSync] Cannot create history: AccountProvider not available');
                return null;
            }

            $symbol = $localPos->symbol;
            
            // 1. Récupérer les trades (fills) pour ce symbole
            // Limiter aux 7 derniers jours pour éviter trop de données
            $endTime = time();
            $startTime = $endTime - (7 * 24 * 60 * 60); // 7 jours
            $trades = $this->accountProvider->getTrades($symbol, 200, $startTime, $endTime);
            
            // 2. Récupérer le transaction history avec flow_type=2 (realized PnL)
            $transactions = $this->accountProvider->getTransactionHistory($symbol, 2, 200, $startTime, $endTime);
            
            // 3. Calculer les données agrégées depuis les trades
            $totalFilledSize = \Brick\Math\BigDecimal::zero();
            $totalFilledNotional = \Brick\Math\BigDecimal::zero();
            $lastTradeTime = null;
            $totalFees = \Brick\Math\BigDecimal::zero();
            
            // Filtrer les trades pour ce side (long/short)
            $sideFilter = strtoupper($localPos->side->value);
            foreach ($trades as $trade) {
                // Vérifier si le trade correspond au side de la position
                // BitMart utilise open_type: 1=open_long, 2=close_long, 3=close_short, 4=open_short
                $openType = $trade['open_type'] ?? null;
                $isCloseTrade = ($sideFilter === 'LONG' && $openType == 2) || ($sideFilter === 'SHORT' && $openType == 3);
                
                if ($isCloseTrade) {
                    $filledSize = \Brick\Math\BigDecimal::of((string)($trade['size'] ?? 0));
                    $filledPrice = \Brick\Math\BigDecimal::of((string)($trade['price'] ?? 0));
                    $fee = \Brick\Math\BigDecimal::of((string)($trade['fee'] ?? 0));
                    
                    $totalFilledSize = $totalFilledSize->plus($filledSize);
                    $totalFilledNotional = $totalFilledNotional->plus($filledSize->multipliedBy($filledPrice));
                    $totalFees = $totalFees->plus($fee);
                    
                    $tradeTime = isset($trade['create_time']) ? (int)($trade['create_time'] / 1000) : null;
                    if ($tradeTime !== null && ($lastTradeTime === null || $tradeTime > $lastTradeTime)) {
                        $lastTradeTime = $tradeTime;
                    }
                }
            }
            
            // Calculer le prix de sortie moyen
            $exitPrice = $totalFilledSize->isZero() 
                ? $localPos->markPrice 
                : $totalFilledNotional->dividedBy($totalFilledSize, 12, \Brick\Math\RoundingMode::HALF_UP);
            
            // 4. Récupérer le realized PnL depuis transaction history
            $realizedPnl = \Brick\Math\BigDecimal::zero();
            foreach ($transactions as $tx) {
                // flow_type=2 est realized PnL
                if (($tx['flow_type'] ?? null) == 2) {
                    $pnlValue = \Brick\Math\BigDecimal::of((string)($tx['amount'] ?? 0));
                    $realizedPnl = $realizedPnl->plus($pnlValue);
                }
            }
            
            // Si pas de realized PnL dans transaction history, utiliser unrealizedPnl comme approximation
            if ($realizedPnl->isZero()) {
                $realizedPnl = $localPos->unrealizedPnl;
            }
            
            // Date de fermeture : utiliser le dernier trade ou maintenant
            $closedAt = $lastTradeTime !== null 
                ? (new \DateTimeImmutable('@' . $lastTradeTime, new \DateTimeZone('UTC')))
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return new PositionHistoryEntryDto(
                symbol: $symbol,
                side: $localPos->side,
                size: $localPos->size,
                entryPrice: $localPos->entryPrice,
                exitPrice: $exitPrice,
                realizedPnl: $realizedPnl,
                fees: $totalFees->isZero() ? null : $totalFees,
                openedAt: $localPos->openedAt,
                closedAt: $closedAt,
                raw: array_merge($localPos->raw, [
                    'closed_detected_at' => $closedAt->format('Y-m-d H:i:s'),
                    'source' => 'trading_state_sync_api',
                    'trades_count' => count($trades),
                    'transactions_count' => count($transactions),
                    'total_filled_size' => $totalFilledSize->__toString(),
                ])
            );
        } catch (\Throwable $e) {
            $this->logger?->error('[TradingStateSync] Failed to create history from API data', [
                'symbol' => $localPos->symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}

