<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\FuturesOrderSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

final class OrderWebhookController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?FuturesOrderSyncService $syncService = null,
    ) {
    }

    #[Route('/api/orders/events', name: 'api_orders_events', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if ($content === '') {
            return new JsonResponse(['status' => 'ignored', 'reason' => 'empty_body'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($content, true);
        if (!\is_array($payload)) {
            return new JsonResponse(['status' => 'ignored', 'reason' => 'invalid_json'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $channel = (string)($payload['channel'] ?? $payload['group'] ?? '');
        if (strtolower($channel) !== 'futures/order') {
            return new JsonResponse(['status' => 'ignored', 'reason' => 'unsupported_channel']);
        }

        $normalizedEvents = $this->normalizeEvents($payload['data'] ?? []);
        foreach ($normalizedEvents as $event) {
            // Synchroniser l'ordre depuis l'événement WebSocket
            if ($this->syncService) {
                try {
                    $this->syncService->syncOrderFromWebSocket($event);
                } catch (\Throwable $e) {
                    $this->logger->warning('[OrderWebhook] Failed to sync order from WebSocket', [
                        'order_id' => $event['order_id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->debug('[OrderWebhook] Events processed', [
            'count' => count($normalizedEvents),
        ]);

        return new JsonResponse([
            'status' => 'ok',
            'processed' => count($normalizedEvents),
        ]);
    }

    /**
     * @param mixed $data
     * @return array<int,array<string,mixed>>
     */
    private function normalizeEvents(mixed $data): array
    {
        $rows = [];
        if (!\is_array($data)) {
            return $rows;
        }

        if (isset($data['data']) && \is_array($data['data'])) {
            $data = $data['data'];
        }

        foreach ($data as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $order = (array)($row['order'] ?? []);
            $rows[] = [
                'action' => (int)($row['action'] ?? 0),
                'order_id' => (string)($order['order_id'] ?? ''),
                'client_order_id' => (string)($order['client_order_id'] ?? ''),
                'symbol' => (string)($order['symbol'] ?? ''),
                'side' => $order['side'] ?? null,
                'type' => $order['type'] ?? null,
                'state' => (int)($order['state'] ?? 0),
                'price' => $order['price'] ?? null,
                'size' => $order['size'] ?? null,
                'deal_avg_price' => $order['deal_avg_price'] ?? null,
                'deal_size' => $order['deal_size'] ?? null,
                'leverage' => $order['leverage'] ?? null,
                'open_type' => $order['open_type'] ?? null,
                'position_mode' => $order['position_mode'] ?? null,
                'update_time_ms' => $order['update_time'] ?? null,
            ];
        }

        return $rows;
    }
}
