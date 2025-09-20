<?php

namespace App\Repository;

use App\Entity\Contract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Kline>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    public function allActiveSymbols()
    {
        return $this->createQueryBuilder('contract')
            ->andWhere('contract.symbol like :likeT')
            ->setParameter('likeT', '%USDT%')
            ->select('contract.symbol')
            ->getQuery()->getResult();
    }

    public function normalizeSubset(array $subset): array
    {
        return array_column($this->createQueryBuilder('contract')
            ->where('contract.symbol in (\''.implode('\',\'', $subset).'\')')
            ->select('contract.symbol')
            ->getQuery()
            ->getResult(), 'symbol');
    }
}
