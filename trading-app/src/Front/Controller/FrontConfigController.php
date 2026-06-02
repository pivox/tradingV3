<?php

declare(strict_types=1);

namespace App\Front\Controller;

use App\Front\Query\ConfigSummaryQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FrontConfigController extends AbstractController
{
    public function __construct(
        private readonly ConfigSummaryQuery $configSummaryQuery,
    ) {
    }

    #[Route('/app/config', name: 'front_config', methods: ['GET'])]
    public function config(): Response
    {
        return $this->render('front/config.html.twig', [
            'current' => 'config',
            'config' => $this->configSummaryQuery->summary(),
        ]);
    }
}
