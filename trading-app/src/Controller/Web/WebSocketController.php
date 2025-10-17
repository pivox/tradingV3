<?php

namespace App\Controller\Web;

use App\Entity\Contract;
use App\Repository\ContractRepository;
use App\Service\ContractDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class WebSocketController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ContractDispatcher $dispatcher,
    ) {
    }

    #[Route('/websocket', name: 'websocket_management')]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $symbol = $request->query->get('symbol');

        $contracts = $this->contractRepository->findWithFilters($status, $symbol);
        $workers = $this->dispatcher->getWorkers();
        $currentAssignments = $this->dispatcher->getCurrentAssignments();

        return $this->render('websocket/index.html.twig', [
            'contracts' => $contracts,
            'timeframes' => ['1m', '5m', '15m', '1h', '4h', '1d'],
            'workers' => $workers,
            'currentAssignments' => $currentAssignments,
        ]);
    }
}






