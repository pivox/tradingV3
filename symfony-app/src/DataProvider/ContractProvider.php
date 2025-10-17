<?php

namespace App\DataProvider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Contract;
use App\Repository\ContractRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ContractProvider implements ProviderInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $exchange = $context['filters']['cex'] ?? null;

        if (!$exchange) {
            throw new NotFoundHttpException('Missing exchange filter');
        }

        return $this->em->getRepository(Contract::class)
            ->createQueryBuilder('c')
            ->join('c.exchange', 'e')
            ->where('e.name = :name')
            ->setParameter('name', $exchange)
            ->getQuery()
            ->getResult();
    }

}
