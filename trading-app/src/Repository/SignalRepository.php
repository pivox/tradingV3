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

    /**
     * Nettoie les anciens signaux en ne gardant que les N derniers jours.
     *
     * @param string|null $symbol      Filtrer par symbole (null = tous les symboles)
     * @param int         $daysToKeep  Nombre de jours à conserver
     * @param bool        $dryRun      Si true, ne supprime pas mais retourne les stats
     * 
     * @return array Statistiques détaillées
     *               [
     *                 'total' => 2000,
     *                 'to_delete' => 1800,
     *                 'to_keep' => 200,
     *                 'cutoff_date' => '2025-10-30 12:00:00',
     *                 'by_timeframe' => ['1m' => 500, '5m' => 300, ...],
     *                 'symbols_affected' => ['BTCUSDT' => 500, 'ETHUSDT' => 300],
     *                 'dry_run' => true
     *               ]
     */
    public function cleanupOldSignals(?string $symbol, int $daysToKeep, bool $dryRun): array
    {
        $cutoffDate = new \DateTimeImmutable("-{$daysToKeep} days", new \DateTimeZone('UTC'));
        
        $qb = $this->createQueryBuilder('s');
        
        // Filtre par date
        $qb->where('s.insertedAt < :cutoff')
           ->setParameter('cutoff', $cutoffDate);

        // Filtre optionnel par symbole
        if ($symbol) {
            $qb->andWhere('s.symbol = :symbol')
               ->setParameter('symbol', $symbol);
        }

        try {
            // Statistiques globales
            $countQb = clone $qb;
            $toDelete = (int)$countQb->select('COUNT(s.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $totalQb = $this->createQueryBuilder('s2');
            if ($symbol) {
                $totalQb->where('s2.symbol = :symbol')
                    ->setParameter('symbol', $symbol);
            }
            $total = (int)$totalQb->select('COUNT(s2.id)')
                ->getQuery()
                ->getSingleScalarResult();

            // Statistiques par timeframe
            $tfQb = clone $qb;
            $byTimeframe = $tfQb
                ->select('s.timeframe', 'COUNT(s.id) as count')
                ->groupBy('s.timeframe')
                ->getQuery()
                ->getResult();

            $byTfArray = [];
            foreach ($byTimeframe as $row) {
                $byTfArray[$row['timeframe']->value] = (int)$row['count'];
            }

            // Statistiques par symbole
            $symbolQb = clone $qb;
            $bySymbol = $symbolQb
                ->select('s.symbol', 'COUNT(s.id) as count')
                ->groupBy('s.symbol')
                ->orderBy('count', 'DESC')
                ->getQuery()
                ->getResult();

            $symbolsAffected = [];
            foreach ($bySymbol as $row) {
                $symbolsAffected[$row['symbol']] = (int)$row['count'];
            }

            $stats = [
                'total' => $total,
                'to_delete' => $toDelete,
                'to_keep' => $total - $toDelete,
                'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
                'by_timeframe' => $byTfArray,
                'symbols_affected' => $symbolsAffected,
                'dry_run' => $dryRun,
            ];

            // Si dry-run ou rien à supprimer, on s'arrête ici
            if ($dryRun || $toDelete === 0) {
                return $stats;
            }

            // Exécution réelle de la suppression
            $deletedCount = $qb->delete()
                ->getQuery()
                ->execute();

            $stats['deleted_count'] = $deletedCount;

            return $stats;

        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf('Erreur lors du nettoyage des signaux: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }
}




