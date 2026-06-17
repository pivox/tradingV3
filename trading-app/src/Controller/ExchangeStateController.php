<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Runner\OpenStateSnapshotSerializer;
use App\Contract\Provider\MainProviderInterface;
use App\Provider\Context\ExchangeContextResolver;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SF-002b — endpoint en lecture seule produisant l'instantané d'état ouvert
 * (positions/ordres) que l'orchestrateur récupère UNE seule fois puis transmet
 * à chaque appel `/api/mtf/run` (open_state_snapshot), évitant un fetch exchange
 * par set.
 */
final class ExchangeStateController extends AbstractController
{
    public function __construct(
        private readonly MainProviderInterface $mainProvider,
        private readonly ExchangeContextResolver $contextResolver,
        private readonly OpenStateSnapshotSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/exchange/open-state', name: 'api_exchange_open_state', methods: ['GET'])]
    public function openState(Request $request): JsonResponse
    {
        try {
            $context = $this->contextResolver->resolve($request->query->all());
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $provider = $this->mainProvider->forContext($context);
            $accountProvider = $provider->getAccountProvider();
            $orderProvider = $provider->getOrderProvider();

            $openPositions = $accountProvider !== null ? $accountProvider->getOpenPositions() : [];
            $openOrders = $orderProvider !== null ? $orderProvider->getOpenOrders() : [];

            $snapshot = $this->serializer->serialize($openPositions, $openOrders);

            $this->logger->info('[Exchange State] Open-state snapshot produced', [
                'exchange' => $context->exchange->value,
                'market_type' => $context->marketType->value,
                'positions_count' => count($snapshot['open_positions']),
                'orders_count' => count($snapshot['open_orders']),
            ]);

            return $this->json($snapshot);
        } catch (\Throwable $e) {
            $this->logger->error('[Exchange State] Failed to produce open-state snapshot', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
