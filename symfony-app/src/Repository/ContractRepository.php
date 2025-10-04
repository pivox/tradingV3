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

    public function allActiveSymbols(): array
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-1080 hours')
            ->getTimestamp();

        $qb = $this->createQueryBuilder('contract');
        $qb->andWhere('contract.quoteCurrency = :quoteCurrency')
            ->andWhere('contract.status = :status')
            ->andWhere('contract.volume24h > :volume24h')
            ->andWhere('contract.openInterest <= :openInterest')
            ->setParameter('quoteCurrency', 'USDT')
            ->setParameter('status', 'Trading')
            ->setParameter('volume24h', 2_000_000)
            ->setParameter('openInterest', $date);

        $qb->andWhere($qb->expr()->notIn(
            'contract.symbol',
            $this->getEntityManager()->createQueryBuilder()
                ->select('b.symbol')
                ->from('App\Entity\BlacklistedContract', 'b')
                ->getDQL()
        ));

        return $qb->getQuery()->getResult();
    }

    public function allActiveSymbolNames(): array
    {
        $date = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-1080 hours')
            ->getTimestamp();

        $qb = $this->createQueryBuilder('contract');
        $qb->select('contract.symbol')
            ->andWhere('contract.quoteCurrency = :quoteCurrency')
            ->andWhere('contract.status = :status')
            ->andWhere('contract.volume24h > :volume24h')
            ->andWhere('contract.openInterest <= :openInterest')
            ->setParameter('quoteCurrency', 'USDT')
            ->setParameter('status', 'Trading')
            ->setParameter('volume24h', 2_000_000)
            ->setParameter('openInterest', $date);

        $qb->andWhere($qb->expr()->notIn(
            'contract.symbol',
            $this->getEntityManager()->createQueryBuilder()
                ->select('b.symbol')
                ->from('App\Entity\BlacklistedContract', 'b')
                ->getDQL()
        ));

        return array_column($qb->getQuery()->getResult(), 'symbol');
    }

    public function findTopByVolumeOrOI(int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('contract');
        $qb->select('contract.symbol')
            ->where('contract.quoteCurrency = :quoteCurrency')
            ->andWhere('contract.status = :status')
            ->setParameter('quoteCurrency', 'USDT')
            ->setParameter('status', 'Trading')
            ->orderBy('contract.volume24h', 'DESC')
            ->setMaxResults($limit);

        $qb->andWhere($qb->expr()->notIn(
            'contract.symbol',
            $this->getEntityManager()->createQueryBuilder()
                ->select('b.symbol')
                ->from('App\Entity\BlacklistedContract', 'b')
                ->getDQL()
        ));

        return array_column($qb->getQuery()->getResult(), 'symbol');
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
