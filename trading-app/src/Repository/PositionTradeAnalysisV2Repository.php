<?php

declare(strict_types=1);

namespace App\Repository;

use App\Trading\Entity\PositionTradeAnalysisV2;
use App\Trading\Service\PositionTradeAnalysisReaderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * OBS-003 — Repository de la vue versionnée `position_trade_analysis_v2`. Implémente la
 * source de lecture des outcomes (lecture seule, bornée), distincte de la vue v1.
 */
final class PositionTradeAnalysisV2Repository extends ServiceEntityRepository implements PositionTradeAnalysisReaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionTradeAnalysisV2::class);
    }

    /**
     * @return PositionTradeAnalysisV2[]
     */
    public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array
    {
        $qb = $this->createQueryBuilder('pta')
            ->andWhere('pta.runId = :rid')
            ->setParameter('rid', $correlationRunId)
            ->orderBy('pta.entryTime', 'ASC')
            ->setMaxResults(max(1, $limit));

        if ($setId !== null && $setId !== '') {
            $qb->andWhere('pta.setId = :sid')
                ->setParameter('sid', $setId);
        }

        return $qb->getQuery()->getResult();
    }
}
