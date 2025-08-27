<?php

namespace App\Controller\Bitmart;

use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Exchange\Bitmart\Dto\ContractDto;
use App\Service\Persister\ContractPersister;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class BitmartCallbackController extends AbstractController
{
    #[Route('/api/bitmart/contracts/callback', name: 'bitmart_contracts_callback', methods: ['GET'])]
    public function contractsCallback(
        Request $request,
        ContractPersister $persister,
        LoggerInterface $logger,
        BitmartFetcher $bitmartFetcher
    ): JsonResponse
    {
        $logger->critical(str_pad('*', 80, '*'));
        $logger->critical(str_pad('*', 80, '*'));
        $logger->critical(str_pad('*', 80, '*'));
        $logger->critical(str_pad('*', 80, '*'));
        $contracts = $bitmartFetcher->fetchContracts();
        $logger->info('Fetched ' . count($contracts) . ' contracts from Bitmart');
        foreach ($contracts as $contract) {
            $logger->info('Fetched contract from Bitmart');
            $logger->info(json_encode($contract));
            $persister->persistFromDto($contract, 'bitmart');
        }

        return new JsonResponse(['status' => 'Contracts processed']);
    }
}
