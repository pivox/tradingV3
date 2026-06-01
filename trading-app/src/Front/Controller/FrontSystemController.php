<?php

declare(strict_types=1);

namespace App\Front\Controller;

use App\Front\Query\SystemHealthQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontSystemController extends AbstractController
{
    public function __construct(
        private readonly SystemHealthQuery $systemHealthQuery,
    ) {
    }

    #[Route('/app/system', name: 'front_system', methods: ['GET'])]
    public function system(): Response
    {
        return $this->render('front/system.html.twig', [
            'current' => 'system',
            'health' => $this->systemHealthQuery->health(),
        ]);
    }

    #[Route('/app/api/system/health', name: 'front_api_system_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json($this->systemHealthQuery->health());
    }
}
