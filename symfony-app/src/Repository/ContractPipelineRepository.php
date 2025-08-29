<?php

namespace App\Repository;

use App\Entity\ContractPipeline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContractPipelineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractPipeline::class);
    }

    /**
     * Retourne les contrats éligibles à un timeframe donné (pending) avec un cap de résultats.
     */
    public function findEligibleFor(string $timeframe, int $limit = 500): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.currentTimeframe = :tf')->setParameter('tf', $timeframe)
            ->andWhere('p.status = :st')->setParameter('st', ContractPipeline::STATUS_PENDING)
            ->orderBy('p.updatedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
