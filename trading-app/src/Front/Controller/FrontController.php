<?php

declare(strict_types=1);

namespace App\Front\Controller;

use App\Front\Query\CockpitSummaryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontController extends AbstractController
{
    public function __construct(
        private readonly CockpitSummaryQuery $cockpitSummaryQuery,
    ) {
    }

    #[Route('/app', name: 'front_cockpit', methods: ['GET'])]
    public function cockpit(): Response
    {
        return $this->render('front/cockpit.html.twig', [
            'current' => 'cockpit',
            'summary' => $this->cockpitSummaryQuery->summary(),
        ]);
    }

    #[Route('/app/api/cockpit/summary', name: 'front_api_cockpit_summary', methods: ['GET'])]
    public function cockpitSummary(): JsonResponse
    {
        return $this->json($this->cockpitSummaryQuery->summary()->toArray());
    }
}
