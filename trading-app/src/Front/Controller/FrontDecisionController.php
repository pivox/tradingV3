<?php

declare(strict_types=1);

namespace App\Front\Controller;

use App\Front\Query\DecisionSummaryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontDecisionController extends AbstractController
{
    public function __construct(
        private readonly DecisionSummaryQuery $decisionSummaryQuery,
    ) {
    }

    #[Route('/app/decisions', name: 'front_decisions', methods: ['GET'])]
    public function decisions(): Response
    {
        return $this->render('front/decisions.html.twig', [
            'current' => 'decisions',
            'view' => $this->decisionSummaryQuery->latest(),
        ]);
    }

    #[Route('/app/decisions/{decisionKey}', name: 'front_decision_detail', requirements: ['decisionKey' => '.+'], methods: ['GET'])]
    public function detail(string $decisionKey): Response
    {
        return $this->render('front/decision_detail.html.twig', [
            'current' => 'decisions',
            'detail' => $this->decisionSummaryQuery->detail($decisionKey),
        ]);
    }

    #[Route('/app/api/decisions/latest', name: 'front_api_decisions_latest', methods: ['GET'])]
    public function latest(): JsonResponse
    {
        return $this->json($this->decisionSummaryQuery->latest()->toArray());
    }
}
