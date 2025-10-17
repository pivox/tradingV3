<?php

namespace App\Controller\Web;

use App\Entity\Exchange;
use App\Repository\ExchangeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ExchangesController extends AbstractController
{
    public function __construct(
        private readonly ExchangeRepository $exchangeRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/exchanges', name: 'exchanges_index')]
    public function index(): Response
    {
        $exchanges = $this->exchangeRepository->findAllWithContracts();
        $stats = $this->getExchangesStats();

        return $this->render('exchanges/index.html.twig', [
            'exchanges' => $exchanges,
            'stats' => $stats,
        ]);
    }

    #[Route('/exchanges/{name}', name: 'exchanges_show')]
    public function show(string $name): Response
    {
        $exchange = $this->exchangeRepository->find($name);

        if (!$exchange) {
            throw $this->createNotFoundException('Exchange non trouvé');
        }

        return $this->render('exchanges/show.html.twig', [
            'exchange' => $exchange,
        ]);
    }

    private function getExchangesStats(): array
    {
        $totalExchanges = $this->exchangeRepository->count([]);

        // Total des contrats
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(c.symbol)')
            ->from('App\Entity\Contract', 'c');
        $totalContracts = $qb->getQuery()->getSingleScalarResult();

        $avgContractsPerExchange = $totalExchanges > 0 ? round($totalContracts / $totalExchanges, 1) : 0;

        return [
            'total_exchanges' => $totalExchanges,
            'total_contracts' => $totalContracts,
            'avg_contracts_per_exchange' => $avgContractsPerExchange,
        ];
    }
}
