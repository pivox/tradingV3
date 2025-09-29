<?php

namespace App\Controller;

use App\Service\Trading\OpenedLockedSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class RefreshOpenedLockedController extends AbstractController
{
    public function __construct(private readonly OpenedLockedSyncService $svc) {}

    #[Route('/api/refresh-opened-locked', name: 'api_refresh_opened_locked', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $payload = $this->svc->sync();

        return new JsonResponse([
            'status' => 'ok',
            'bitmart_open_symbols' => $payload['bitmart_open_symbols'],
            'locked_symbols_before' => $payload['locked_symbols_before'],
            'removed_symbols' => $payload['removed_symbols'],
            'kept_symbols' => $payload['kept_symbols'],
            'total_unlocked' => $payload['total_unlocked'],
        ]);
    }
}
