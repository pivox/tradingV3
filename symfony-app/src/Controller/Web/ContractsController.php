<?php

namespace App\Controller\Web;

use App\Entity\Contract;
use App\Repository\ContractRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ContractsController extends AbstractController
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
    ) {
    }

    #[Route('/contracts', name: 'contracts_index')]
    public function index(Request $request): Response
    {
        $exchange = $request->query->get('exchange');
        $status = $request->query->get('status');
        $symbol = $request->query->get('symbol');

        $contracts = $this->contractRepository->findWithFilters($exchange, $status, $symbol);

        return $this->render('contracts/index.html.twig', [
            'contracts' => $contracts,
        ]);
    }

    #[Route('/contracts/{symbol}', name: 'contracts_show', requirements: ['symbol' => '[A-Z0-9]+'])]
    public function show(string $symbol): Response
    {
        $contract = $this->contractRepository->find($symbol);

        if (!$contract) {
            throw $this->createNotFoundException('Contrat non trouvé');
        }

        return $this->render('contracts/show.html.twig', [
            'contract' => $contract,
        ]);
    }
}
