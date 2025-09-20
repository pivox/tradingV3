<?php

namespace App\Repository;

use App\Entity\Kline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Kline>
 */
class KlineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Kline::class);
    }

    /**
     * Trouve les derniers Klines par symbol et interval, triés par timestamp DESC
     */
    public function fetchRecent(string $symbol, int $step, int $limit): array
    {
        return $this->createQueryBuilder('k')
            ->innerJoin('k.contract', 'contract')
            ->where('contract.symbol = :symbol')
            ->andWhere('k.step = :step')
            ->orderBy('k.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('symbol', $symbol)
            ->setParameter('step', $step)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve toutes les Klines par symbol et interval, triés par timestamp DESC
     */
    public function findBySymbolAndInterval(string $symbol, int $step): array
    {
        return $this->createQueryBuilder('k')
            ->innerJoin('k.contract', 'contract')
            ->where('contract.symbol = :symbol')
            ->andWhere('k.step = :step')
            ->orderBy('k.timestamp', 'DESC')
            ->setParameter('symbol', $symbol)
            ->setParameter('step', $step)
            ->getQuery()
            ->getResult();
    }

    public function findRecentBySymbolAndTimeframe(string $symbol, string $timeframe, int $limit)
    {
        $x = $this->createQueryBuilder('k')
            ->innerJoin('k.contract', 'contract')
            ->where('contract.symbol = :symbol')->setParameter('symbol', $symbol)
            ->andWhere('k.step = :step')->setParameter('step', $this->stepFor($timeframe))
            ->orderBy('k.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery();
            return $x->getResult();
    }

    private function stepFor(string $timeframe): int
    {
        return match($timeframe) {
            '1m'  => 1,
            '5m'  => 5,
            '15m' => 15,
            '1h'  => 60,
            '4h'  => 240,
            default => throw new \InvalidArgumentException("Unsupported timeframe: $timeframe"),
        };
    }

    public function findLastKline(mixed $contract, int $int)
    {
        $result = $this->createQueryBuilder('k')
            ->where('k.contract = :contract')
            ->andWhere('k.step = :step')
            ->setParameter('contract', $contract)
            ->setParameter('step', $int)
            ->select('k.timestamp')
            ->orderBy('k.timestamp', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        return $result['timestamp'] ?? null;
    }
}
