<?php

declare(strict_types=1);

namespace App\Repository;

use App\Trading\Entity\PositionTradeAnalysisV2;
use App\Trading\Reporting\PositionTradeAnalysisCertifiedReaderInterface;
use App\Trading\Service\PositionTradeAnalysisReaderInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * OBS-003 — Repository de la vue versionnée `position_trade_analysis_v2`. Implémente la
 * source de lecture des outcomes (lecture seule, bornée), distincte de la vue v1.
 *
 * @extends ServiceEntityRepository<PositionTradeAnalysisV2>
 */
final class PositionTradeAnalysisV2Repository extends ServiceEntityRepository implements PositionTradeAnalysisReaderInterface, PositionTradeAnalysisCertifiedReaderInterface
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

    /**
     * @param array{symbol?: string|null, from?: string|null, to?: string|null, timeframe?: string|null} $filters
     * @param array{sort?: string, direction?: string} $options
     * @return PositionTradeAnalysisV2[]
     */
    public function search(array $filters, array $options = [], int $limit = 200): array
    {
        $qb = $this->createQueryBuilder('pta')
            ->orderBy('pta.entryTime', 'DESC')
            ->setMaxResults(max(1, $limit));

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
            'entryTime' => 'entryTime',
            'closeTime' => 'closeTime',
            'expectedRMultiple' => 'expectedRMultiple',
            'pnlR' => 'realizedNetPnlR',
            'pnlUsdt' => 'netPnlUsdt',
            'mfePct' => 'mfePct',
            'maePct' => 'maePct',
        ];

        $qb->orderBy('pta.' . ($allowedSorts[$sort] ?? 'entryTime'), $direction);

        return $qb->getQuery()->getResult();
    }
}
