<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Trading\Service\RunTradeOutcomeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OBS-003 — Expose, en LECTURE SEULE, le rapprochement d'un run d'orchestration avec
 * ses trades résultants (`position_trade_analysis`), agrégé par `run_id`.
 *
 * Consommé par l'orchestrateur Python via HTTP (`GET /runs/{run_id}/outcome`), sans
 * coupler l'orchestrateur au schéma interne Symfony. Le PnL n'est jamais recalculé : on
 * ne fait que sommer/moyenner les valeurs déjà exposées par la vue.
 *
 * Fail-safe : une vue indisponible, un run inconnu ou un run sans trade renvoient un
 * agrégat vide explicite en HTTP 200 (jamais un 500). Seul un `run_id` manquant donne
 * un 400 (paramètre requis).
 */
class PositionAnalysisApiController extends AbstractController
{
    public function __construct(
        private readonly RunTradeOutcomeService $outcomeService,
    ) {
    }

    #[Route('/api/positions/analysis', name: 'api_positions_analysis', methods: ['GET'])]
    public function analysis(Request $request): JsonResponse
    {
        $runId = (string) ($request->query->get('run_id', ''));
        if (trim($runId) === '') {
            return $this->json([
                'status' => 'error',
                'message' => 'run_id is required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Le service est fail-safe : il ne lève jamais (vue absente / erreur SQL → agrégat
        // vide `available=false`). On renvoie donc toujours 200 avec un agrégat explicite.
        $outcome = $this->outcomeService->aggregateByRunId($runId);

        return $this->json($outcome);
    }
}
