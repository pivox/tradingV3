<?php

declare(strict_types=1);

namespace App\Repository;

use App\Common\Enum\Timeframe;
use App\Entity\Signal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Signal>
 */
class SignalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Signal::class);
    }

    /**
     * Récupère le dernier signal pour un symbole et timeframe
     */
    public function findLastBySymbolAndTimeframe(string $symbol, Timeframe $timeframe): ?Signal
    {
        return $this->createQueryBuilder('s')
            ->where('s.symbol = :symbol')
            ->andWhere('s.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('s.klineTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Récupère les signaux pour une période
     */
    public function findBySymbolTimeframeAndDateRange(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate
    ): array {
        return $this->createQueryBuilder('s')
            ->where('s.symbol = :symbol')
            ->andWhere('s.timeframe = :timeframe')
            ->andWhere('s.klineTime >= :startDate')
            ->andWhere('s.klineTime <= :endDate')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('s.klineTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les signaux récents
     */
    public function findRecentSignals(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.symbol = :symbol')
            ->andWhere('s.timeframe = :timeframe')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->orderBy('s.klineTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les signaux par côté (LONG/SHORT)
     */
    public function findBySide(string $symbol, Timeframe $timeframe, string $side): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.symbol = :symbol')
            ->andWhere('s.timeframe = :timeframe')
            ->andWhere('s.side = :side')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->setParameter('side', $side)
            ->orderBy('s.klineTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sauvegarde ou met à jour un signal
     */
    public function upsert(Signal $signal): void
    {
        $existing = $this->findOneBy([
            'symbol' => $signal->getSymbol(),
            'timeframe' => $signal->getTimeframe(),
            'klineTime' => $signal->getKlineTime()
        ]);

        if ($existing) {
            $existing->setSide($signal->getSide());
            $existing->setScore($signal->getScore());
            $existing->setMeta($signal->getMeta());
            $existing->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->getEntityManager()->flush();
        } else {
            $this->getEntityManager()->persist($signal);
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Récupère les signaux forts (score élevé)
     */
    public function findStrongSignals(string $symbol, Timeframe $timeframe, float $minScore = 0.7): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.symbol = :symbol')
            ->andWhere('s.timeframe = :timeframe')
            ->andWhere('s.score >= :minScore')
            ->setParameter('symbol', $symbol)
            ->setParameter('timeframe', $timeframe)
            ->setParameter('minScore', $minScore)
            ->orderBy('s.klineTime', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les derniers signaux par contrat et timeframe
     * Retourne un tableau avec les derniers signaux pour chaque combinaison symbole/timeframe
     */
    public function findLastSignalsByContractAndTimeframe(): array
    {
        $qb = $this->createQueryBuilder('s');

        // Sous-requête pour obtenir le dernier signal par symbole et timeframe
        $subQb = $this->createQueryBuilder('s2');
        $subQb->select('MAX(s2.klineTime)')
            ->where('s2.symbol = s.symbol')
            ->andWhere('s2.timeframe = s.timeframe');

        $qb->where($qb->expr()->in('s.klineTime', $subQb->getDQL()))
            ->orderBy('s.symbol', 'ASC')
            ->addOrderBy('s.timeframe', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les derniers signaux groupés par symbole et timeframe
     * Retourne un tableau associatif [symbole][timeframe] = signal
     */
    public function findLastSignalsGrouped(): array
    {
        $signals = $this->findLastSignalsByContractAndTimeframe();
        $grouped = [];

        foreach ($signals as $signal) {
            $symbol = $signal->getSymbol();
            $timeframe = $signal->getTimeframe()->value;

            if (!isset($grouped[$symbol])) {
                $grouped[$symbol] = [];
            }

            $grouped[$symbol][$timeframe] = $signal;
        }

        return $grouped;
    }
}




