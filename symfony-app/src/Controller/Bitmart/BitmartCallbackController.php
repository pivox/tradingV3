<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Repository\ContractPipelineRepository;
use App\Repository\ContractRepository;
use App\Repository\KlineRepository;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\ContractPersister;
use App\Service\Pipeline\ContractPipelineService;
use App\Service\Temporal\Dto\WorkflowRef;
use App\Service\Temporal\Orchestrators\BitmartOrchestrator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Callbacks pour Bitmart :
 * - Rafraîchissement de la liste des contrats
 * - Cron périodiques (1m, 5m, 15m, 1h, 4h)
 */
class BitmartCallbackController extends AbstractController
{
    public function __construct(
        private readonly BitmartOrchestrator $bitmartOrchestrator,
        private readonly ContractRepository $contractRepository,
        private readonly KlineRepository $klineRepository,
    )
    {
    }

    #[Route('/api/callback/bitmart/fetch-all-contract', name: 'bitmart_contracts_callback', methods: ['POST'])]
    public function fetchAllContracts(
        ContractPersister $persister,
        BitmartFetcher $bitmartFetcher,
        ContractPipelineService $pipelineService,
        LoggerInterface $logger,
    ): JsonResponse {
        $contracts = $bitmartFetcher->fetchContracts();
        $logger->info('Fetched ' . count($contracts) . ' contracts from Bitmart');

        $seeded = 0;
        foreach ($contracts as $dto) {
            $contract = $persister->persistFromDto($dto, 'bitmart');
            $pipelineService->ensureSeeded4h($contract);
            $seeded++;
        }

        return new JsonResponse([
            'status'   => 'ok',
            'persisted_contracts' => count($contracts),
            'seeded_pipeline_4h'  => $seeded,
        ]);
    }

}
