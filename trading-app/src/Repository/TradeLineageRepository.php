<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradeLineage;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeLineage>
 */
final class TradeLineageRepository extends ServiceEntityRepository
{
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

    private function findUniqueByVenueIdentifier(string $field, string $value, ?ExchangeContext $context): ?TradeLineage
    {
        $matches = $this->findBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            $field => $value,
        ], null, 2);

        return \count($matches) === 1 ? $matches[0] : null;
    }
}
