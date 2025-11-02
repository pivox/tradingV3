<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\OrderLifecycleRepository;
use App\Repository\PositionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Contr?leur de callback pour recevoir les notifications de ws-worker
 * 
 * Re?oit les notifications de:
 * - Ordres plac?s
 * - Ordres ?chou?s
 * - Positions ferm?es (SL/TP)
 * - Timeouts
 */
final class OrderCallbackController
{
    public function __construct(
        private readonly OrderLifecycleRepository $orderLifecycleRepository,
        private readonly PositionRepository $positionRepository,
        #[Autowire(service: 'monolog.logger.orders')]
        private readonly LoggerInterface $ordersLogger,
    ) {}

    #[Route('/api/orders/callback', name: 'api_orders_callback', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return new JsonResponse(['status' => 'error', 'message' => 'empty_body'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($content, true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'message' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        $status = $payload['status'] ?? 'unknown';

        $this->ordersLogger->info('[OrderCallback] Received callback', [
            'status' => $status,
            'order_id' => $payload['order_id'] ?? $payload['position_id'] ?? 'unknown',
            'payload' => $payload,
        ]);

        try {
            match($status) {
                'placed' => $this->handleOrderPlaced($payload),
                'failed' => $this->handleOrderFailed($payload),
                'timeout' => $this->handleOrderTimeout($payload),
                'closed' => $this->handlePositionClosed($payload),
                'close_failed' => $this->handlePositionCloseFailed($payload),
                default => $this->ordersLogger->warning('[OrderCallback] Unknown status', ['status' => $status])
            };

            return new JsonResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            $this->ordersLogger->error('[OrderCallback] Exception during callback processing', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function handleOrderPlaced(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        $exchangeOrderId = $payload['exchange_order_id'] ?? null;
        
        if ($orderId === null || $exchangeOrderId === null) {
            $this->ordersLogger->warning('[OrderCallback] Missing order IDs in placed callback');
            return;
        }

        $this->ordersLogger->info('[OrderCallback] Order placed successfully', [
            'order_id' => $orderId,
            'exchange_order_id' => $exchangeOrderId,
            'entry_price' => $payload['entry_price'] ?? null,
            'quantity' => $payload['quantity'] ?? null,
        ]);

        // TODO: Mettre ? jour l'OrderLifecycle dans la base de donn?es
        // $orderLifecycle = $this->orderLifecycleRepository->findByClientOrderId($orderId);
        // if ($orderLifecycle !== null) {
        //     $orderLifecycle->setExchangeOrderId($exchangeOrderId);
        //     $orderLifecycle->setFilledPrice($payload['entry_price'] ?? null);
        //     $orderLifecycle->setStatus('placed');
        //     $this->orderLifecycleRepository->save($orderLifecycle);
        // }
    }

    private function handleOrderFailed(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        $error = $payload['error'] ?? 'unknown';

        $this->ordersLogger->error('[OrderCallback] Order failed', [
            'order_id' => $orderId,
            'error' => $error,
        ]);

        // TODO: Mettre ? jour l'OrderLifecycle avec statut 'failed'
    }

    private function handleOrderTimeout(array $payload): void
    {
        $orderId = $payload['order_id'] ?? null;
        $reason = $payload['reason'] ?? 'unknown';

        $this->ordersLogger->warning('[OrderCallback] Order timeout', [
            'order_id' => $orderId,
            'reason' => $reason,
        ]);

        // TODO: Mettre ? jour l'OrderLifecycle avec statut 'timeout'
    }

    private function handlePositionClosed(array $payload): void
    {
        $positionId = $payload['position_id'] ?? null;
        $closeOrderId = $payload['close_order_id'] ?? null;
        $reason = $payload['reason'] ?? 'unknown';
        $closePrice = $payload['close_price'] ?? null;

        $this->ordersLogger->info('[OrderCallback] Position closed', [
            'position_id' => $positionId,
            'close_order_id' => $closeOrderId,
            'reason' => $reason,
            'close_price' => $closePrice,
        ]);

        // TODO: Mettre ? jour la Position dans la base de donn?es
        // $position = $this->positionRepository->findByOrderId($positionId);
        // if ($position !== null) {
        //     $position->setStatus('closed');
        //     $position->setClosePrice($closePrice);
        //     $position->setCloseReason($reason);
        //     $this->positionRepository->save($position);
        // }
    }

    private function handlePositionCloseFailed(array $payload): void
    {
        $positionId = $payload['position_id'] ?? null;
        $reason = $payload['reason'] ?? 'unknown';
        $error = $payload['error'] ?? 'unknown';

        $this->ordersLogger->error('[OrderCallback] Position close failed', [
            'position_id' => $positionId,
            'reason' => $reason,
            'error' => $error,
        ]);

        // TODO: Alerter l'?quipe / retry manuel
    }
}
