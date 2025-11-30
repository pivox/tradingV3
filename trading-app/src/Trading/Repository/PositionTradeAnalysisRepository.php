<?php

declare(strict_types=1);

namespace App\Trading\Repository;

use App\Trading\Entity\PositionTradeAnalysis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PositionTradeAnalysis>
 */
final class PositionTradeAnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionTradeAnalysis::class);
    }

    /**
     * @return PositionTradeAnalysis[]
     */
    public function findRecentBySymbol(string $symbol, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.symbol = :symbol')
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('p.entryTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PositionTradeAnalysis[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.entryTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

