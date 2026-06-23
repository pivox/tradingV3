<?php

declare(strict_types=1);

namespace App\Trading\Controller\Api;

use App\Trading\Service\PositionTradeOutcomeSourceException;
use App\Trading\Service\RunCorrelationId;
use App\Trading\Service\RunTradeOutcomeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OBS-003 — Endpoint read-only reliant un run d'orchestration à ses trades résultants.
 *
 * `GET /api/positions/analysis?run_id=…[&set_id=…]`. Le `run_id` reçu est l'identifiant
 * ORIGINAL du run ; l'identifiant de corrélation canonique (≤64) est dérivé côté serveur,
 * exactement comme côté Python ({@see RunCorrelationId}). Lecture seule, aucune écriture.
 *
 * Codes :
 *  - 400 si `run_id` absent/vide ;
 *  - 200 + agrégat (éventuellement vide, `trade_count = 0`) si la source répond ;
 *  - 503 + `source_available = false` si la source est indisponible (jamais un 500, et
 *    jamais un agrégat vide silencieux qui masquerait l'indisponibilité).
 */
final class PositionAnalysisApiController extends AbstractController
{
    public function __construct(
        private readonly RunTradeOutcomeService $outcomeService,
    ) {
    }

    #[Route('/api/positions/analysis', name: 'api_positions_analysis', methods: ['GET'])]
    public function analysis(Request $request): JsonResponse
    {
        $runId = trim((string) $request->query->get('run_id', ''));
        if ($runId === '') {
            return $this->json([
                'error' => 'missing_run_id',
                'message' => 'Query parameter "run_id" is required.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $setIdRaw = $request->query->get('set_id');
        $setId = is_string($setIdRaw) && trim($setIdRaw) !== '' ? trim($setIdRaw) : null;

        try {
            $outcome = $this->outcomeService->buildOutcome($runId, $setId);
        } catch (PositionTradeOutcomeSourceException $e) {
            // Indisponibilité explicite — surtout pas confondue avec « 0 trade ».
            return $this->json([
                'run_id' => $runId,
                'correlation_run_id' => RunCorrelationId::canonicalOrNull($runId),
                'set_id' => $setId,
                'source_available' => false,
                'error' => 'source_unavailable',
                'message' => 'position_trade_analysis source is currently unavailable.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json($outcome);
    }
}
