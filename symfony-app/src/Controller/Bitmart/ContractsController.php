<?php

namespace App\Controller\Bitmart;

use App\Dto\BitmartContractOutput;
use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\ContractPersister;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
class ContractsController extends AbstractController
{
    public function __invoke(
        BitmartFetcher $fetcher,
        ContractPersister $contractPersister
    ): BitmartContractOutput {
        $contracts = $fetcher->fetchContracts();
        foreach ($contracts as $dto) {
            $contractPersister->persistFromDto($dto, 'bitmart');
        }
        $contractPersister->flush();

        return new BitmartContractOutput($contracts);
    }
}
