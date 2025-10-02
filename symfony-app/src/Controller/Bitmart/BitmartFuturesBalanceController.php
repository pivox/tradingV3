<?php

declare(strict_types=1);

namespace App\Controller\Bitmart;

use App\Service\Bitmart\Private\AssetsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class BitmartFuturesBalanceController extends AbstractController
{
    public function __construct(private readonly AssetsService $assetsService) {}

    #[Route('/api/bitmart/balance/futures', name: 'bitmart_futures_balance', methods: ['GET'])]
    public function futures(): Response
    {
        $resp = $this->assetsService->getAssetsDetail();
        return $this->json($resp);
    }
}
