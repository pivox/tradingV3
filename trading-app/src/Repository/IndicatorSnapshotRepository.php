<?php

declare(strict_types=1);

namespace App\Repository;

use App\Common\Enum\Timeframe;
use App\Entity\IndicatorSnapshot;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @extends ServiceEntityRepository<IndicatorSnapshot>
 */
class IndicatorSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        #[Autowire(service: 'monolog.logger.indicators')]
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($registry, IndicatorSnapshot::class);
    }

    /**
     * Récupère le dernier snapshot d'indicateurs
     */
    public function findLastBySymbolAndTimeframe(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): ?IndicatorSnapshot
    {
        return $this->createQueryBuilder('i')
            ->where('i.exchange = :exchange')
            ->andWhere('i.marketType = :marketType')
            ->andWhere('i.symbol = :symbol')
            ->andWhere('i.timeframe = :timeframe')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
        \DateTimeImmutable $endDate,
        ?ExchangeContext $context = null,
    ): array {
        return $this->createQueryBuilder('i')
            ->where('i.exchange = :exchange')
            ->andWhere('i.marketType = :marketType')
            ->andWhere('i.symbol = :symbol')
            ->andWhere('i.timeframe = :timeframe')
            ->andWhere('i.klineTime >= :startDate')
            ->andWhere('i.klineTime <= :endDate')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
    public function findRecentForIndicators(
        string $symbol,
        Timeframe $timeframe,
        int $limit = 100,
        ?ExchangeContext $context = null,
    ): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.exchange = :exchange')
            ->andWhere('i.marketType = :marketType')
            ->andWhere('i.symbol = :symbol')
            ->andWhere('i.timeframe = :timeframe')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
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
    public function upsert(IndicatorSnapshot $snapshot, ?ExchangeContext $context = null): void
    {
        if ($context !== null) {
            $snapshot->setExchange($context->exchange);
            $snapshot->setMarketType($context->marketType);
        }

        $existing = $this->findOneBy([
            'exchange' => $snapshot->getExchange(),
            'marketType' => $snapshot->getMarketType(),
            'symbol' => $snapshot->getSymbol(),
            'timeframe' => $snapshot->getTimeframe(),
            'klineTime' => $snapshot->getKlineTime()
        ]);

        if ($existing) {
            $existing->setValues($snapshot->getValues());
            if ($snapshot->getRunId() !== null) {
                $existing->setRunId($snapshot->getRunId());
            }
            $existing->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $this->logger->debug('[IndicatorSnapshotRepository] Snapshot updated', [
                'symbol' => $existing->getSymbol(),
                'exchange' => $existing->getExchange(),
                'market_type' => $existing->getMarketType(),
                'timeframe' => $existing->getTimeframe()->value,
                'kline_time' => $existing->getKlineTime()->format('Y-m-d H:i:s'),
                'run_id' => $existing->getRunId(),
            ]);
            $this->getEntityManager()->flush();
        } else {
            $this->getEntityManager()->persist($snapshot);
            $this->logger->debug('[IndicatorSnapshotRepository] Snapshot inserted', [
                'symbol' => $snapshot->getSymbol(),
                'exchange' => $snapshot->getExchange(),
                'market_type' => $snapshot->getMarketType(),
                'timeframe' => $snapshot->getTimeframe()->value,
                'kline_time' => $snapshot->getKlineTime()->format('Y-m-d H:i:s'),
                'run_id' => $snapshot->getRunId(),
            ]);
            $this->getEntityManager()->flush();
        }
    }
}

