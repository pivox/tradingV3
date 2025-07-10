<?php

namespace App\Controller\Bitmart;

use App\Service\KlineService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/kline', name: 'api_kline', methods: ['POST'])]
class KlineSyncController extends AbstractController
{
    public function __invoke(Request $request, KlineService $klineService): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || empty($data)) {
            return $this->json(['status' => 'Invalid or empty payload'], 400);
        }

        try {
            // Persister les klines via le service existant
            $klineService->persistFromArray($data, 'bitmart');
            $klineService->flush();

            return $this->json(['status' => 'Klines persisted', 'count' => count($data)]);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        }
    }
}
