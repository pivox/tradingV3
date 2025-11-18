<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradeZoneEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeZoneEvent>
 */
final class TradeZoneEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeZoneEvent::class);
    }

    public function save(TradeZoneEvent $event, bool $flush = false): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($event);
        if ($flush) {
            $entityManager->flush();
        }
    }

    /**
     * @return TradeZoneEvent[]
     */
    public function findRecentForSymbol(string $symbol, int $limit = 100): array
    {
        return $this->createQueryBuilder('event')
            ->andWhere('event.symbol = :symbol')
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('event.happenedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, array{zoneDevPct: float, zoneMaxDevPct: float, happenedAt: \DateTimeImmutable}>
     */
    public function getDeviationSeries(string $symbol, \DateTimeImmutable $since, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('event')
            ->select('event.zoneDevPct AS zoneDevPct', 'event.zoneMaxDevPct AS zoneMaxDevPct', 'event.happenedAt AS happenedAt')
            ->andWhere('event.symbol = :symbol')
            ->andWhere('event.happenedAt >= :since')
            ->orderBy('event.happenedAt', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('symbol', strtoupper($symbol))
            ->setParameter('since', $since);

        /** @var array<int, array{zoneDevPct: float, zoneMaxDevPct: float, happenedAt: \DateTimeImmutable}> $result */
        $result = $qb->getQuery()->getArrayResult();

        return $result;
    }

    /**
     * @return array<int, array{symbol: string, avgDevPct: float, avgMaxDevPct: float, events: int}>
     */
    public function getAggregatedStats(\DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('event')
            ->select('event.symbol AS symbol')
            ->addSelect('AVG(event.zoneDevPct) AS avgDevPct')
            ->addSelect('AVG(event.zoneMaxDevPct) AS avgMaxDevPct')
            ->addSelect('COUNT(event.id) AS events')
            ->andWhere('event.happenedAt >= :since')
            ->groupBy('event.symbol')
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            return [
                'symbol' => $row['symbol'],
                'avgDevPct' => isset($row['avgDevPct']) ? (float)$row['avgDevPct'] : 0.0,
                'avgMaxDevPct' => isset($row['avgMaxDevPct']) ? (float)$row['avgMaxDevPct'] : 0.0,
                'events' => isset($row['events']) ? (int)$row['events'] : 0,
            ];
        }, $rows);
    }

    /**
     * Récupère la dernière raison "pas de trade" par symbole
     *
     * @param string[] $symbols
     * @return array<string, array{reason: string, happened_at: \DateTimeImmutable}>
     */
    public function getLastReasonBySymbols(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        // Récupérer le dernier événement pour chaque symbole
        // On utilise une sous-requête pour obtenir le dernier événement par symbole
        $subQb = $this->createQueryBuilder('event2')
            ->select('MAX(event2.happenedAt)')
            ->where('event2.symbol = event.symbol');

        $qb = $this->createQueryBuilder('event')
            ->where('event.symbol IN (:symbols)')
            ->andWhere('event.happenedAt = (' . $subQb->getDQL() . ')')
            ->setParameter('symbols', $symbols)
            ->orderBy('event.symbol', 'ASC');

        $events = $qb->getQuery()->getResult();

        $result = [];
        foreach ($events as $event) {
            $symbol = $event->getSymbol();
            $result[$symbol] = [
                'reason' => $event->getReason(),
                'happened_at' => $event->getHappenedAt(),
            ];
        }

        return $result;
    }
}
