<?php

namespace App\Controller\Web;

use App\Service\Exchange\Bitmart\BitmartFetcher;
use App\Service\Persister\ContractPersister;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class FetcherTestController extends AbstractController
{
    #[Route('/t/c', name: 'bitmart_test_contracts', methods: ['Get'])]
    public function testContracts(
        Request $request,
        BitmartFetcher $fetcher,
        LoggerInterface $logger): JsonResponse
    {
        $fetcher->fetchContracts();
        return new JsonResponse(['hello' => 'world']);
    }
}
