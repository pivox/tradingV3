<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradeLineage;
use App\Provider\Context\ExchangeContext;
use App\Trading\Lineage\ReadModel\LineageReadCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeLineage>
 */
final class TradeLineageRepository extends ServiceEntityRepository
{
    private const CRITERIA_FIELD_MAP = [
        'orchestration_run_id' => 'orchestrationRunId',
        'correlation_run_id' => 'correlationRunId',
        'orchestration_set_id' => 'orchestrationSetId',
        'orchestration_dashboard_id' => 'orchestrationDashboardId',
        'internal_trade_id' => 'internalTradeId',
        'internal_position_id' => 'internalPositionId',
        'client_order_id' => 'clientOrderId',
        'exchange_order_id' => 'exchangeOrderId',
        'position_id' => 'positionId',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeLineage::class);
    }

    public function findOneByInternalTradeId(string $internalTradeId): ?TradeLineage
    {
        return $this->findOneBy(['internalTradeId' => $internalTradeId]);
    }

    public function findOneByOrderIntentId(int $orderIntentId): ?TradeLineage
    {
        return $this->createQueryBuilder('lineage')
            ->andWhere('IDENTITY(lineage.orderIntent) = :orderIntentId')
            ->setParameter('orderIntentId', $orderIntentId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?TradeLineage
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'clientOrderId' => $clientOrderId,
        ]);
    }

    public function findOneByExchangeOrderId(string $exchangeOrderId, ?ExchangeContext $context = null): ?TradeLineage
    {
        return $this->findUniqueByVenueIdentifier('exchangeOrderId', $exchangeOrderId, $context);
    }

    public function findOneByPositionId(string $positionId, ?ExchangeContext $context = null): ?TradeLineage
    {
        return $this->findUniqueByVenueIdentifier('positionId', $positionId, $context);
    }

    /**
     * @return TradeLineage[]
     */
    public function findPageByReadCriteria(LineageReadCriteria $criteria): array
    {
        return $this->createCriteriaQueryBuilder($criteria)
            ->orderBy('lineage.createdAt', 'ASC')
            ->addOrderBy('lineage.id', 'ASC')
            ->setFirstResult($criteria->offset)
            ->setMaxResults($criteria->limit)
            ->getQuery()
            ->getResult();
    }

    public function countByReadCriteria(LineageReadCriteria $criteria): int
    {
        return (int) $this->createCriteriaQueryBuilder($criteria)
            ->select('COUNT(lineage.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findUniqueByVenueIdentifier(string $field, string $value, ?ExchangeContext $context): ?TradeLineage
    {
        $matches = $this->findBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            $field => $value,
        ], null, 2);

        return \count($matches) === 1 ? $matches[0] : null;
    }

    private function createCriteriaQueryBuilder(LineageReadCriteria $criteria): QueryBuilder
    {
        $field = self::CRITERIA_FIELD_MAP[$criteria->kind] ?? null;
        if ($field === null) {
            throw new \InvalidArgumentException(sprintf('Unsupported lineage read criteria "%s".', $criteria->kind));
        }

        $qb = $this->createQueryBuilder('lineage')
            ->andWhere(sprintf('lineage.%s = :value', $field))
            ->setParameter('value', $criteria->value);

        if ($criteria->requiresVenue()) {
            $qb->andWhere('lineage.exchange = :exchange')
                ->andWhere('lineage.marketType = :marketType')
                ->setParameter('exchange', $criteria->exchange)
                ->setParameter('marketType', $criteria->marketType);
        }

        return $qb;
    }
}
