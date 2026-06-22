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
     * OBS-003 — toutes les lignes de la vue rattachées à un identifiant de corrélation
     * (== `trade_lifecycle_event.run_id`), en lecture seule et bornée. `$setId` filtre
     * en plus sur le set d'orchestration quand il est fourni.
     *
     * @return PositionTradeAnalysis[]
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

        // Alias de compat : la colonne `pnl_usdt` a été renommée `recorded_pnl_usdt`
        // (OBS-003). On accepte encore l'ancien nom de tri côté reporting.
        if ($sort === 'pnlUsdt') {
            $sort = 'recordedPnlUsdt';
        }

        $allowedSorts = [
            'entryTime',
            'closeTime',
            'expectedRMultiple',
            'pnlR',
            'recordedPnlUsdt',
            'mfePct',
            'maePct',
        ];

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'entryTime';
        }

        $qb->orderBy('pta.' . $sort, $direction);

        return $qb->getQuery()->getResult();
    }
}
