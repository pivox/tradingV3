<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\ExchangeOrderRepository;
use App\Repository\OrderLifecycleRepository;
use App\Repository\OrderPlanRepository;
use App\Repository\PositionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/ws-worker/orders')]
final class OrderSignalQueryController
{
    public function __construct(
        private readonly ExchangeOrderRepository $exchangeOrderRepository,
        private readonly OrderLifecycleRepository $orderLifecycleRepository,
        private readonly OrderPlanRepository $orderPlanRepository,
        private readonly PositionRepository $positionRepository,
    ) {
    }

    #[Route('', name: 'api_ws_worker_orders_index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $symbol = $request->query->get('symbol');
        $kind = $request->query->get('kind');
        $limit = (int) $request->query->get('limit', '50');
        $limit = max(1, min(200, $limit));

        $orders = $this->exchangeOrderRepository->search($symbol, $kind, $limit);

        $data = array_map(function ($order) {
            $plan = $order->getOrderPlan();
            $position = $order->getPosition();

            return [
                'client_order_id' => $order->getClientOrderId(),
                'order_id' => $order->getOrderId(),
                'symbol' => $order->getSymbol(),
                'kind' => $order->getKind(),
                'status' => $order->getStatus(),
                'type' => $order->getType(),
                'side' => $order->getSide(),
                'price' => $order->getPrice(),
                'size' => $order->getSize(),
                'submitted_at' => $order->getSubmittedAt()->format(\DateTimeInterface::ATOM),
                'plan_id' => $plan?->getId(),
                'position' => $position?->getId(),
            ];
        }, $orders);

        return new JsonResponse([
            'status' => 'ok',
            'count' => count($data),
            'data' => $data,
        ]);
    }

    #[Route('/{clientOrderId}', name: 'api_ws_worker_orders_show', methods: ['GET'])]
    public function show(string $clientOrderId): JsonResponse
    {
        $clientOrderId = strtoupper($clientOrderId);

        $exchangeOrder = $this->exchangeOrderRepository->findOneByClientOrderId($clientOrderId);
        $orderLifecycle = $this->orderLifecycleRepository->findOneByClientOrderId($clientOrderId);

        if ($exchangeOrder === null && $orderLifecycle === null) {
            return new JsonResponse([
                'status' => 'error',
                'code' => 'order_not_found',
                'message' => sprintf('Aucun ordre trouvé pour %s', $clientOrderId),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        $plan = $exchangeOrder?->getOrderPlan();
        if ($plan === null && $orderLifecycle !== null) {
            $planIdFromPayload = $orderLifecycle->getPayload()['context']['plan_id'] ?? null;
            if ($planIdFromPayload !== null) {
                $plan = $this->orderPlanRepository->find((int) $planIdFromPayload);
            }
        }

        $position = null;
        if ($exchangeOrder?->getPosition() !== null) {
            $position = $exchangeOrder->getPosition();
        } elseif ($orderLifecycle !== null && $orderLifecycle->getSide() !== null) {
            $position = $this->positionRepository->findOneBySymbolSide(
                $orderLifecycle->getSymbol(),
                $orderLifecycle->getSide()
            );
        }

        return new JsonResponse([
            'status' => 'ok',
            'data' => [
                'exchange_order' => $exchangeOrder?->getId() !== null ? $this->serializeExchangeOrder($exchangeOrder) : null,
                'order_lifecycle' => $orderLifecycle?->getId() !== null ? $this->serializeLifecycle($orderLifecycle) : null,
                'order_plan' => $plan?->getId() !== null ? $this->serializePlan($plan) : null,
                'position' => $position?->getId() !== null ? $this->serializePosition($position) : null,
            ],
        ]);
    }

    private function serializeExchangeOrder(\App\Entity\ExchangeOrder $order): array
    {
        return [
            'id' => $order->getId(),
            'order_id' => $order->getOrderId(),
            'client_order_id' => $order->getClientOrderId(),
            'symbol' => $order->getSymbol(),
            'kind' => $order->getKind(),
            'status' => $order->getStatus(),
            'type' => $order->getType(),
            'side' => $order->getSide(),
            'price' => $order->getPrice(),
            'size' => $order->getSize(),
            'submitted_at' => $order->getSubmittedAt()->format(\DateTimeInterface::ATOM),
            'metadata' => $order->getMetadata(),
            'exchange_payload' => $order->getExchangePayload(),
        ];
    }

    private function serializeLifecycle(\App\Entity\OrderLifecycle $lifecycle): array
    {
        return [
            'id' => $lifecycle->getId(),
            'order_id' => $lifecycle->getOrderId(),
            'client_order_id' => $lifecycle->getClientOrderId(),
            'symbol' => $lifecycle->getSymbol(),
            'status' => $lifecycle->getStatus(),
            'kind' => $lifecycle->getKind(),
            'side' => $lifecycle->getSide(),
            'type' => $lifecycle->getType(),
            'last_action' => $lifecycle->getLastAction(),
            'last_event_at' => $lifecycle->getLastEventAt()->format(\DateTimeInterface::ATOM),
            'payload' => $lifecycle->getPayload(),
        ];
    }

    private function serializePlan(\App\Entity\OrderPlan $plan): array
    {
        return [
            'id' => $plan->getId(),
            'symbol' => $plan->getSymbol(),
            'side' => $plan->getSide()->value,
            'status' => $plan->getStatus(),
            'plan_time' => $plan->getPlanTime()->format(\DateTimeInterface::ATOM),
            'context' => $plan->getContextJson(),
            'execution' => $plan->getExecJson(),
        ];
    }

    private function serializePosition(\App\Entity\Position $position): array
    {
        return [
            'id' => $position->getId(),
            'symbol' => $position->getSymbol(),
            'side' => $position->getSide(),
            'status' => $position->getStatus(),
            'size' => $position->getSize(),
            'avg_entry_price' => $position->getAvgEntryPrice(),
            'payload' => $position->getPayload(),
        ];
    }
}
