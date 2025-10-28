<?php

declare(strict_types=1);

namespace App\Repository;

use App\Common\Enum\Timeframe;
use App\Entity\IndicatorSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndicatorSnapshot>
 */
class IndicatorSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndicatorSnapshot::class);
    }

    /**
     * Récupère le dernier snapshot d'indicateurs
     */
    public function findLastBySymbolAndTimeframe(string $symbol, Timeframe $timeframe): ?IndicatorSnapshot
    {
        return $this->createQueryBuilder('i')
            ->where('i.symbol = :symbol')
            ->andWhere('i.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('i.klineTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère les snapshots d'indicateurs pour une période
     */
    public function findBySymbolTimeframeAndDateRange(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate
    ): array {
        return $this->createQueryBuilder('i')
            ->where('i.symbol = :symbol')
            ->andWhere('i.timeframe = :timeframe')
            ->andWhere('i.klineTime >= :startDate')
            ->andWhere('i.klineTime <= :endDate')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('i.klineTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les snapshots récents pour le calcul des indicateurs
     */
    public function findRecentForIndicators(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.symbol = :symbol')
            ->andWhere('i.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('i.klineTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Sauvegarde ou met à jour un snapshot d'indicateurs
     */
    public function upsert(IndicatorSnapshot $snapshot): void
    {
        $existing = $this->findOneBy([
            'symbol' => $snapshot->getSymbol(),
            'timeframe' => $snapshot->getTimeframe(),
            'klineTime' => $snapshot->getKlineTime()
        ]);

        if ($existing) {
            $existing->setValues($snapshot->getValues());
            $existing->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->getEntityManager()->flush();
        } else {
            $this->getEntityManager()->persist($snapshot);
            $this->getEntityManager()->flush();
        }
    }
}




