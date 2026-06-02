<?php

declare(strict_types=1);

namespace App\Front\Controller;

use App\Front\Query\TemporalSummaryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontTemporalController extends AbstractController
{
    public function __construct(
        private readonly TemporalSummaryQuery $temporalSummaryQuery,
    ) {
    }

    #[Route('/app/temporal', name: 'front_temporal', methods: ['GET'])]
    public function temporal(): Response
    {
        return $this->render('front/temporal.html.twig', [
            'current' => 'temporal',
            'temporal' => $this->temporalSummaryQuery->summary(),
        ]);
    }

    #[Route('/app/api/temporal/summary', name: 'front_api_temporal_summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        return $this->json($this->temporalSummaryQuery->summary());
    }
}
