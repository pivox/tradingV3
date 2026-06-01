<?php

declare(strict_types=1);

namespace App\Front\Controller;

use App\Front\Query\RiskSummaryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontRiskController extends AbstractController
{
    public function __construct(
        private readonly RiskSummaryQuery $riskSummaryQuery,
    ) {
    }

    #[Route('/app/risk', name: 'front_risk', methods: ['GET'])]
    public function risk(): Response
    {
        return $this->render('front/risk.html.twig', [
            'current' => 'risk',
            'view' => $this->riskSummaryQuery->getSummary(),
        ]);
    }

    #[Route('/app/api/risk/summary', name: 'front_api_risk_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        return $this->json($this->riskSummaryQuery->getSummary()->toArray());
    }
}
