<?php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HealthController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('OK', 200, ['Content-Type' => 'text/plain']);
    }
}

