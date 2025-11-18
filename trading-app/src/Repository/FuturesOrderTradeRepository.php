<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuturesOrderTrade;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuturesOrderTrade>
 */
final class FuturesOrderTradeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuturesOrderTrade::class);
    }

    public function findOneByTradeId(string $tradeId): ?FuturesOrderTrade
    {
        return $this->findOneBy(['tradeId' => $tradeId]);
    }

    /**
     * @return FuturesOrderTrade[]
     */
    public function findByOrderId(string $orderId, int $limit = 100): array
    {
        return $this->createQueryBuilder('fot')
            ->where('fot.orderId = :orderId')
            ->setParameter('orderId', $orderId)
            ->orderBy('fot.tradeTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return FuturesOrderTrade[]
     */
    public function findBySymbol(?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fot')
            ->orderBy('fot.tradeTime', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fot.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    public function save(FuturesOrderTrade $trade): void
    {
        $this->getEntityManager()->persist($trade);
        $this->getEntityManager()->flush();
    }
    /**
     * @return FuturesOrderTrade[]
     */
    public function findRecentBySymbol(
        string $symbol,
        int $limit = 200,
        ?\DateTimeImmutable $since = null
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.symbol = :symbol')
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('t.createdAt >= :since')
                ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

}

