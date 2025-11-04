<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\FuturesOrderRepository;
use App\Repository\OrderIntentRepository;
use App\Service\OrderIntentManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class OrderSubmittedController
{
    public function __construct(
        private readonly FuturesOrderRepository $futuresOrderRepository,
        private readonly OrderIntentRepository $orderIntentRepository,
        private readonly ?OrderIntentManager $intentManager = null,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/order-submitted', name: 'api_order_submitted', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return new JsonResponse(['status' => 'error', 'reason' => 'empty_body'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($content, true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'reason' => 'invalid_json'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $orderId = $payload['order_id'] ?? null;
        $clientOrderId = $payload['client_order_id'] ?? null;
        $symbol = $payload['symbol'] ?? null;
        $submittedAt = $payload['submitted_at'] ?? null;

        if (!$orderId && !$clientOrderId) {
            return new JsonResponse([
                'status' => 'error',
                'reason' => 'missing_order_id_or_client_order_id',
            ], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            // Mettre à jour l'OrderIntent si disponible
            if ($this->intentManager) {
                $intent = $this->intentManager->findIntent(clientOrderId: $clientOrderId, orderId: $orderId);
                if ($intent && !$intent->isSent()) {
                    $this->intentManager->markAsSent($intent, $orderId ?? '');
                    $this->logger->info('[OrderSubmitted] Updated OrderIntent', [
                        'order_id' => $orderId,
                        'client_order_id' => $clientOrderId,
                    ]);
                }
            }

            // Vérifier que l'ordre existe dans FuturesOrder
            $futuresOrder = null;
            if ($orderId) {
                $futuresOrder = $this->futuresOrderRepository->findOneByOrderId($orderId);
            }
            if (!$futuresOrder && $clientOrderId) {
                $futuresOrder = $this->futuresOrderRepository->findOneByClientOrderId($clientOrderId);
            }

            if ($futuresOrder) {
                $this->logger->info('[OrderSubmitted] Order found and confirmed', [
                    'order_id' => $orderId,
                    'client_order_id' => $clientOrderId,
                    'symbol' => $symbol,
                ]);
            } else {
                $this->logger->warning('[OrderSubmitted] Order not found in database', [
                    'order_id' => $orderId,
                    'client_order_id' => $clientOrderId,
                    'symbol' => $symbol,
                ]);
            }

            return new JsonResponse([
                'status' => 'ok',
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[OrderSubmitted] Error processing notification', [
                'order_id' => $orderId,
                'client_order_id' => $clientOrderId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

