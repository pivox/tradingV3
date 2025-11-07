<?php

namespace App\Controller\Web;

use App\Provider\Entity\Contract;
use App\Provider\Repository\ContractRepository;
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
        $status = $request->query->get('status');
        $symbol = $request->query->get('symbol');

        $contracts = $this->contractRepository->findWithFilters($status, $symbol);

        return $this->render('Provider/contracts/index.html.twig', [
            'contracts' => $contracts,
        ]);
    }

    #[Route('/contracts/{id}', name: 'contracts_show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $contract = $this->contractRepository->find($id);

        if (!$contract) {
            throw $this->createNotFoundException('Contrat non trouvé');
        }

        return $this->render('Provider/contracts/show.html.twig', [
            'contract' => $contract,
        ]);
    }
}