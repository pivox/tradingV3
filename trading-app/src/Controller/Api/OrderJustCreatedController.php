<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class OrderJustCreatedController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(WS_AGENT_BASE_URI)%')]
        private readonly ?string $wsAgentBaseUri = null,
    ) {
    }

    #[Route('/order/just-created/{clientId}/{orderId}/{symbol}', name: 'order_just_created', methods: ['GET'])]
    public function __invoke(string $clientId, string $orderId, string $symbol, Request $request): JsonResponse
    {
        if (!$this->wsAgentBaseUri) {
            $this->logger->warning('[OrderJustCreated] WS_AGENT_BASE_URI not configured, skipping notification');
            return new JsonResponse([
                'status' => 'ok',
                'message' => 'ws-agent not configured',
            ]);
        }

        try {
            // Informer le ws-agent qu'un ordre vient d'être créé
            $wsAgentUrl = rtrim($this->wsAgentBaseUri, '/') . '/internal/track-order';
            
            $response = $this->httpClient->request('POST', $wsAgentUrl, [
                'json' => [
                    'client_order_id' => $clientId,
                    'order_id' => $orderId,
                    'symbol' => $symbol,
                ],
                'timeout' => 2.0,
            ]);

            if ($response->getStatusCode() === 200 || $response->getStatusCode() === 202) {
                $this->logger->info('[OrderJustCreated] Notified ws-agent', [
                    'client_order_id' => $clientId,
                    'order_id' => $orderId,
                    'symbol' => $symbol,
                ]);

                return new JsonResponse([
                    'status' => 'ok',
                    'message' => 'ws-agent notified',
                ]);
            }

            return new JsonResponse([
                'status' => 'error',
                'message' => 'ws-agent notification failed',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('[OrderJustCreated] Failed to notify ws-agent', [
                'client_order_id' => $clientId,
                'order_id' => $orderId,
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

