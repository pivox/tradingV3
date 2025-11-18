<?php

declare(strict_types=1);

namespace App\MtfRunner\Service;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Entity\FuturesPlanOrder;
use App\Repository\FuturesOrderRepository;
use App\Repository\FuturesOrderTradeRepository;
use App\Repository\FuturesPlanOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class FuturesOrderSyncService
{
    public function __construct(
        private readonly FuturesOrderRepository $futuresOrderRepository,
        private readonly FuturesPlanOrderRepository $futuresPlanOrderRepository,
        private readonly FuturesOrderTradeRepository $futuresOrderTradeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Synchronise un ordre depuis les données de l'API BitMart
     * @param array<string,mixed> $orderData Données de l'API (order detail, order history, open orders)
     */
    public function syncOrderFromApi(array $orderData): ?FuturesOrder
    {
        try {
            $orderId = $this->extractString($orderData, 'order_id');
            $clientOrderId = $this->extractString($orderData, 'client_order_id');

            if (!$orderId && !$clientOrderId) {
                $this->logger->warning('[FuturesOrderSync] Missing order_id and client_order_id', [
                    'data' => $orderData,
                ]);
                return null;
            }

            // Chercher l'ordre existant par order_id ou client_order_id
            $order = null;
            if ($orderId) {
                $order = $this->futuresOrderRepository->findOneByOrderId($orderId);
            }
            if (!$order && $clientOrderId) {
                $order = $this->futuresOrderRepository->findOneByClientOrderId($clientOrderId);
            }

            if (!$order) {
                $order = new FuturesOrder();
            }

            // Mapper les champs depuis l'API
            $order->setOrderId($orderId);
            $order->setClientOrderId($clientOrderId);
            $order->setSymbol($this->extractString($orderData, 'symbol', ''));
            
            $side = $this->extractInt($orderData, 'side');
            if ($side !== null) {
                $order->setSide($side);
            }

            $order->setType($this->extractString($orderData, 'type'));
            $order->setStatus($this->extractString($orderData, 'status'));
            $order->setPrice($this->extractString($orderData, 'price'));
            
            $size = $this->extractInt($orderData, 'size');
            if ($size !== null) {
                $order->setSize($size);
            }

            $filledSize = $this->extractInt($orderData, 'filled_size');
            if ($filledSize !== null) {
                $order->setFilledSize($filledSize);
            }

            $order->setFilledNotional($this->extractString($orderData, 'filled_notional'));
            $order->setOpenType($this->extractString($orderData, 'open_type'));
            
            $positionMode = $this->extractInt($orderData, 'position_mode');
            if ($positionMode !== null) {
                $order->setPositionMode($positionMode);
            }

            $leverage = $this->extractInt($orderData, 'leverage');
            if ($leverage !== null) {
                $order->setLeverage($leverage);
            }

            $order->setFee($this->extractString($orderData, 'fee'));
            $order->setFeeCurrency($this->extractString($orderData, 'fee_currency'));
            $order->setAccount($this->extractString($orderData, 'account'));

            $filledTime = $this->extractInt($orderData, 'filled_time');
            if ($filledTime !== null) {
                $order->setFilledTime($filledTime);
            }

            $createdTime = $this->extractInt($orderData, 'created_time');
            if ($createdTime !== null) {
                $order->setCreatedTime($createdTime);
            }

            $updatedTime = $this->extractInt($orderData, 'updated_time');
            if ($updatedTime !== null) {
                $order->setUpdatedTime($updatedTime);
            }

            // Stocker les données brutes
            $order->setRawData($orderData);

            $this->entityManager->persist($order);
            $this->entityManager->flush();

            return $order;
        } catch (\Throwable $e) {
            $this->logger->error('[FuturesOrderSync] Error syncing order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $orderData,
            ]);
            return null;
        }
    }

    /**
     * Synchronise un ordre planifié depuis les données de l'API BitMart
     * @param array<string,mixed> $orderData Données de l'API (plan order)
     */
    public function syncPlanOrderFromApi(array $orderData): ?FuturesPlanOrder
    {
        try {
            $orderId = $this->extractString($orderData, 'order_id');
            $clientOrderId = $this->extractString($orderData, 'client_order_id');

            if (!$orderId && !$clientOrderId) {
                $this->logger->warning('[FuturesOrderSync] Missing order_id and client_order_id for plan order', [
                    'data' => $orderData,
                ]);
                return null;
            }

            // Chercher l'ordre planifié existant
            $planOrder = null;
            if ($orderId) {
                $planOrder = $this->futuresPlanOrderRepository->findOneByOrderId($orderId);
            }
            if (!$planOrder && $clientOrderId) {
                $planOrder = $this->futuresPlanOrderRepository->findOneByClientOrderId($clientOrderId);
            }

            if (!$planOrder) {
                $planOrder = new FuturesPlanOrder();
            }

            // Mapper les champs depuis l'API
            $planOrder->setOrderId($orderId);
            $planOrder->setClientOrderId($clientOrderId);
            $planOrder->setSymbol($this->extractString($orderData, 'symbol', ''));
            
            $side = $this->extractInt($orderData, 'side');
            if ($side !== null) {
                $planOrder->setSide($side);
            }

            $planOrder->setType($this->extractString($orderData, 'type'));
            $planOrder->setStatus($this->extractString($orderData, 'status'));
            $planOrder->setTriggerPrice($this->extractString($orderData, 'trigger_price'));
            $planOrder->setExecutionPrice($this->extractString($orderData, 'execution_price'));
            $planOrder->setPrice($this->extractString($orderData, 'price'));
            
            $size = $this->extractInt($orderData, 'size');
            if ($size !== null) {
                $planOrder->setSize($size);
            }

            $planOrder->setOpenType($this->extractString($orderData, 'open_type'));
            
            $positionMode = $this->extractInt($orderData, 'position_mode');
            if ($positionMode !== null) {
                $planOrder->setPositionMode($positionMode);
            }

            $leverage = $this->extractInt($orderData, 'leverage');
            if ($leverage !== null) {
                $planOrder->setLeverage($leverage);
            }

            $planOrder->setPlanType($this->extractString($orderData, 'plan_type'));

            $triggerTime = $this->extractInt($orderData, 'trigger_time');
            if ($triggerTime !== null) {
                $planOrder->setTriggerTime($triggerTime);
            }

            $createdTime = $this->extractInt($orderData, 'created_time');
            if ($createdTime !== null) {
                $planOrder->setCreatedTime($createdTime);
            }

            $updatedTime = $this->extractInt($orderData, 'updated_time');
            if ($updatedTime !== null) {
                $planOrder->setUpdatedTime($updatedTime);
            }

            // Stocker les données brutes
            $planOrder->setRawData($orderData);

            $this->entityManager->persist($planOrder);
            $this->entityManager->flush();

            return $planOrder;
        } catch (\Throwable $e) {
            $this->logger->error('[FuturesOrderSync] Error syncing plan order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $orderData,
            ]);
            return null;
        }
    }

    /**
     * Synchronise un trade depuis les données de l'API BitMart
     * @param array<string,mixed> $tradeData Données de l'API (order trade)
     */
    public function syncTradeFromApi(array $tradeData): ?FuturesOrderTrade
    {
        try {
            $tradeId = $this->extractString($tradeData, 'trade_id');
            if (!$tradeId) {
                // Essayer d'utiliser un identifiant alternatif ou générer un ID temporaire
                $tradeId = $this->extractString($tradeData, 'id') 
                    ?? sprintf('trade_%s_%d', $this->extractString($tradeData, 'order_id', 'unknown'), time());
                $this->logger->warning('[FuturesOrderSync] Missing trade_id, using generated ID', [
                    'generated_id' => $tradeId,
                    'data' => $tradeData,
                ]);
            }

            // Chercher le trade existant
            $trade = $this->futuresOrderTradeRepository->findOneByTradeId($tradeId);
            if (!$trade) {
                $trade = new FuturesOrderTrade();
            }

            // Mapper les champs depuis l'API
            $trade->setTradeId($tradeId);
            $trade->setOrderId($this->extractString($tradeData, 'order_id', ''));
            $trade->setSymbol($this->extractString($tradeData, 'symbol', ''));
            $trade->setSide($this->extractInt($tradeData, 'side', 0));
            $trade->setPrice($this->extractString($tradeData, 'price', '0'));
            $trade->setSize($this->extractInt($tradeData, 'size', 0));
            $trade->setFee($this->extractString($tradeData, 'fee'));
            $trade->setFeeCurrency($this->extractString($tradeData, 'fee_currency'));
            $trade->setTradeTime($this->extractInt($tradeData, 'trade_time', 0));

            // Lier au FuturesOrder si l'order_id existe
            $orderId = $this->extractString($tradeData, 'order_id');
            if ($orderId) {
                $futuresOrder = $this->futuresOrderRepository->findOneByOrderId($orderId);
                if ($futuresOrder) {
                    $trade->setFuturesOrder($futuresOrder);
                }
            }

            // Stocker les données brutes
            $trade->setRawData($tradeData);

            $this->entityManager->persist($trade);
            $this->entityManager->flush();

            return $trade;
        } catch (\Throwable $e) {
            $this->logger->error('[FuturesOrderSync] Error syncing trade', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $tradeData,
            ]);
            return null;
        }
    }

    /**
     * Synchronise un ordre depuis un événement WebSocket
     * @param array<string,mixed> $eventData Données normalisées de l'événement WebSocket
     */
    public function syncOrderFromWebSocket(array $eventData): ?FuturesOrder
    {
        // Mapper les champs WebSocket normalisés vers le format API
        $filledSize = $eventData['deal_size'] ?? $eventData['filled_size'] ?? null;

        $normalized = [
            'order_id' => $eventData['order_id'] ?? null,
            'client_order_id' => $eventData['client_order_id'] ?? null,
            'symbol' => $eventData['symbol'] ?? null,
            'side' => $eventData['side'] ?? null,
            'type' => $eventData['type'] ?? null,
            'status' => $this->mapWebSocketStateToStatus($eventData['state'] ?? null, $filledSize),
            'price' => $eventData['price'] ?? null,
            'size' => $eventData['size'] ?? null,
            'filled_size' => $filledSize,
            'filled_notional' => null, // Pas disponible dans WebSocket
            'open_type' => $eventData['open_type'] ?? null,
            'position_mode' => $eventData['position_mode'] ?? null,
            'leverage' => $eventData['leverage'] ?? null,
            'fee' => null, // Pas disponible dans WebSocket
            'fee_currency' => null,
            'account' => null,
            'filled_time' => null,
            'created_time' => null,
            'updated_time' => $eventData['update_time_ms'] ?? $eventData['update_time'] ?? null,
        ];

        return $this->syncOrderFromApi(array_merge($normalized, ['_ws_event' => true]));
    }

    /**
     * Mappe l'état WebSocket vers le statut de l'ordre
     * 
     * États BitMart WebSocket:
     * - 1 = APPROVAL (en attente d'approbation)
     * - 2 = CHECK (en vérification)
     * - 4 = FINISH (terminé)
     * 
     * Le statut final dépend aussi de deal_size pour distinguer filled vs cancelled
     */
    private function mapWebSocketStateToStatus(?int $state, mixed $filledSize = null): ?string
    {
        if ($state === null) {
            return null;
        }

        // Mapping BitMart WebSocket state to status
        return match ($state) {
            1 => 'pending',      // APPROVAL
            2 => 'pending',      // CHECK
            4 => $this->mapFilledStateFromExecutedSize($filledSize),
            default => 'unknown',
        };
    }

    private function mapFilledStateFromExecutedSize(mixed $filledSize): ?string
    {
        if ($filledSize === null) {
            return null; // Attendre des données complémentaires pour déterminer le statut final
        }

        if (is_numeric($filledSize)) {
            $executed = (float) $filledSize;

            if ($executed > 0.0) {
                return 'filled';
            }

            if ($executed === 0.0) {
                return 'cancelled';
            }
        }

        return null;
    }

    private function extractString(array $data, string $key, ?string $default = null): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        return (string) $value;
    }

    private function extractInt(array $data, string $key, ?int $default = null): ?int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        return (int) $value;
    }
}


