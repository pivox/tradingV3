<?php

declare(strict_types=1);

namespace App\Repository;

use App\Trading\Entity\PositionTradeAnalysis;
use App\Trading\Service\PositionTradeAnalysisReaderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class PositionTradeAnalysisRepository extends ServiceEntityRepository implements PositionTradeAnalysisReaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionTradeAnalysis::class);
    }

    /**
     * @param array{symbol?: string, from?: string, to?: string, timeframe?: string} $filters
     * @param array{sort?: string, direction?: string} $options
     * @return PositionTradeAnalysis[]
     */
    public function search(array $filters, array $options = [], int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('pta')
            ->orderBy('pta.entryTime', 'DESC')
            ->setMaxResults($limit);

        if (!empty($filters['symbol'])) {
            $qb->andWhere('pta.symbol = :symbol')
                ->setParameter('symbol', strtoupper($filters['symbol']));
        }

        if (!empty($filters['timeframe'])) {
            $qb->andWhere('pta.timeframe = :tf')
                ->setParameter('tf', $filters['timeframe']);
        }

        if (!empty($filters['from'])) {
            $qb->andWhere('pta.entryTime >= :from')
                ->setParameter('from', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $qb->andWhere('pta.entryTime <= :to')
                ->setParameter('to', $filters['to']);
        }

        $sort = $options['sort'] ?? 'entryTime';
        $direction = strtoupper($options['direction'] ?? 'DESC');
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }

        $allowedSorts = [
            'entryTime',
            'closeTime',
            'expectedRMultiple',
            'pnlR',
            'pnlUsdt',
            'mfePct',
            'maePct',
        ];

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'entryTime';
        }

        $qb->orderBy('pta.' . $sort, $direction);

        return $qb->getQuery()->getResult();
    }

    /**
     * Toutes les lignes de la vue rattachées à un `run_id` (OBS-003).
     *
     * Lecture seule, bornée par run : un run touche au plus l'univers actif (~100
     * symboles) et n'ouvre en pratique que peu de trades. Tri stable par symbole puis
     * date d'entrée pour une agrégation déterministe. Une borne `$limit` garde la
     * requête courte même si un run_id (tronqué à 64) venait à collisionner.
     *
     * @return PositionTradeAnalysis[]
     */
    public function findByRunId(string $runId, int $limit = 1000): array
    {
        return $this->createQueryBuilder('pta')
            ->andWhere('pta.runId = :runId')
            ->setParameter('runId', $runId)
            ->orderBy('pta.symbol', 'ASC')
            ->addOrderBy('pta.entryTime', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
