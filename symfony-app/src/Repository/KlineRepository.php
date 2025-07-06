<?php

namespace App\Repository;

use App\Entity\Kline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Kline>
 */
class KlineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Kline::class);
    }

    /**
     * Trouve les derniers Klines par symbol et interval, triés par timestamp DESC
     */
    public function findRecentKlines(string $symbol, int $step, int $limit): array
    {
        return $this->createQueryBuilder('k')
            ->innerJoin('k.contract', 'contract')
            ->where('contract.symbol = :symbol')
            ->andWhere('k.step = :step')
            ->orderBy('k.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('symbol', $symbol)
            ->setParameter('step', $step)
            ->getQuery()
            ->getResult();
    }
}
