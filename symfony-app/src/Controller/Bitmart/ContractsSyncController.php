<?php

namespace App\Controller\Bitmart;

use App\Service\Persister\ContractPersister;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/contracts', name: 'api_contracts', methods: ['POST'])]
class ContractsSyncController extends AbstractController
{
    public function __invoke(Request $request, ContractPersister $contractPersister): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json(['status' => 'Invalid payload'], 400);
        }

        foreach ($data as $dto) {
            $contractPersister->persistFromDto($dto, 'bitmart');
        }
        $contractPersister->flush();

        return $this->json(['status' => 'Contracts persisted', 'count' => count($data)]);
    }
}
