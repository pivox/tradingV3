<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuturesTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuturesTransaction>
 */
final class FuturesTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuturesTransaction::class);
    }

    /**
     * @return FuturesTransaction[]
     */
    public function findRecentBySymbol(
        string $symbol,
        int $limit = 200,
        ?\DateTimeImmutable $since = null
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.symbol = :symbol')
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('t.happenedAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('t.happenedAt >= :since')
                ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return FuturesTransaction[]
     */
    public function findBySymbolSince(string $symbol, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.symbol = :symbol')
            ->andWhere('t.happenedAt >= :since')
            ->setParameter('symbol', strtoupper($symbol))
            ->setParameter('since', $since)
            ->orderBy('t.happenedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
