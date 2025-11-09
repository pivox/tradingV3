<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\WebSocket\Service\WsDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôleur pour les opérations WebSocket (subscribe/unsubscribe)
 */
final class WebSocketController extends AbstractController
{
    public function __construct(
        private readonly WsDispatcher $wsDispatcher
    ) {
    }

    #[Route('/ws/subscribe', name: 'ws_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['symbol']) || !isset($data['tfs']) || !is_array($data['tfs'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing required fields: symbol and tfs (array)',
            ], 400);
        }

        $symbol = (string) $data['symbol'];
        $tfs = $data['tfs'];

        if (empty($tfs)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Timeframes array cannot be empty',
            ], 400);
        }

        try {
            $this->wsDispatcher->subscribe($symbol, $tfs);

            return new JsonResponse([
                'success' => true,
                'message' => "Subscribed to $symbol for timeframes: " . implode(', ', $tfs),
                'symbol' => $symbol,
                'timeframes' => $tfs,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/ws/unsubscribe', name: 'ws_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['symbol']) || !isset($data['tfs']) || !is_array($data['tfs'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing required fields: symbol and tfs (array)',
            ], 400);
        }

        $symbol = (string) $data['symbol'];
        $tfs = $data['tfs'];

        if (empty($tfs)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Timeframes array cannot be empty',
            ], 400);
        }

        try {
            $this->wsDispatcher->unsubscribe($symbol, $tfs);

            return new JsonResponse([
                'success' => true,
                'message' => "Unsubscribed from $symbol for timeframes: " . implode(', ', $tfs),
                'symbol' => $symbol,
                'timeframes' => $tfs,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}




