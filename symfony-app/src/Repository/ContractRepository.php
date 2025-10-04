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
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-1080 hours')
            ->getTimestamp(); // timestamp UNIX (bigint)
        return  $this->createQueryBuilder('contract')
            ->andWhere('contract.quoteCurrency = :quoteCurrency')->setParameter('quoteCurrency', 'USDT')
            ->andWhere('contract.status = :status')->setParameter('status', 'Trading')
            ->andWhere('contract.volume24h > :volume24h')->setParameter('volume24h', 50_000_000)
            ->andWhere('contract.openInterest <= :openInterest')->setParameter('openInterest', $date)
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
