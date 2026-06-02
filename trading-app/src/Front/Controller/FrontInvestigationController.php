<?php

declare(strict_types=1);

namespace App\Front\Controller;

use App\Front\Query\InvestigationQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontInvestigationController extends AbstractController
{
    public function __construct(
        private readonly InvestigationQuery $investigationQuery,
    ) {
    }

    #[Route('/app/investigate', name: 'front_investigate', methods: ['GET'])]
    public function investigate(Request $request): Response
    {
        return $this->render('front/investigate.html.twig', [
            'current' => 'investigate',
            'result' => $this->investigationQuery->investigate(
                $request->query->get('symbol'),
                $request->query->get('date'),
                $request->query->get('decision_key'),
                $request->query->get('run_id'),
            ),
        ]);
    }
}
