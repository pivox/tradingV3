<?php
// src/Controller/TradingController.php
namespace App\Controller;

use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Config\TradingParameters;
use App\Service\Signals\Timeframe\Signal4hService;
use App\Service\Trading\TradingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final class TradingController extends AbstractController
{
    public function __construct(private TradingService $tradingService) {}

    #[Route('/api/signal/4h', name: 'api_signal_4h', methods: ['GET'])]
    public function signal4h(Request $request): JsonResponse
    {
        $symbol = (string) ($request->query->get('symbol') ?? 'BTCUSDT');
        $limit  = (int) ($request->query->get('limit') ?? 300);

        $data = $this->tradingService->getSignal($symbol, '4h', $limit);

        return new JsonResponse($data);
    }

    #[Route('/api/signal-all/4h', name: 'api_signal_all_4h', methods: ['GET'])]
    public function signalAll4h(
        Request $request,
        ContractRepository $contractRepository
    ): JsonResponse
    {
        $qb = $contractRepository->createQueryBuilder('c')
            ->innerJoin('c.klines', 'k')
            ->groupBy('c')
            ->having('MAX(k.timestamp) >= :limitDate')
            ->setParameter('limitDate', new \DateTimeImmutable('-10 hours'))
            ->select('c');

        $contracts = $qb->getQuery()->getResult();
        $results = [];
        foreach ($contracts as $contract) {
            $symbol = $contract->getSymbol();
            $data = $this->tradingService->getSignal($symbol, '4h', 300);
            if ($data['result']['signal'] != 'NONE') {
                $results[] = $data;
            }
        }
        return new JsonResponse($results);
    }


}
