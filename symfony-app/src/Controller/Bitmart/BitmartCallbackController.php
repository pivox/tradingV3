<?php

namespace App\Controller\Bitmart;

use App\Entity\Contract;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\ContractPersister;
use App\Service\Pipeline\ContractPipelineService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * Callback pour rafraîchir la liste des contrats Bitmart.
 * - Persistance des contrats
 * - Seed du pipeline en 4h (idempotent)
 */
class BitmartCallbackController extends AbstractController
{
    #[Route('/api/callback/bitmart/fetch-all-contract', name: 'bitmart_contracts_callback', methods: ['POST'])]
    public function __invoke(
        Request $request,
        ContractPersister $persister,
        BitmartFetcher $bitmartFetcher,
        ContractPipelineService $pipelineService,
        LoggerInterface $logger,
    ): JsonResponse
    {
        $contracts = $bitmartFetcher->fetchContracts();
        $logger->info('Fetched ' . count($contracts) . ' contracts from Bitmart');

        $seeded = 0;
        foreach ($contracts as $dto) {
            // 1) Persistance du contrat
            $contract = $persister->persistFromDto($dto, 'bitmart');

            // 2) Seed pipeline 4h (idempotent)
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
