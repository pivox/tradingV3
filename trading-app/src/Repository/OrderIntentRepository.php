<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderIntent;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderIntent>
 */
final class OrderIntentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderIntent::class);
    }

    public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?OrderIntent
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'clientOrderId' => $clientOrderId,
        ]);
    }

    public function findOneByDecisionKey(string $decisionKey, ?ExchangeContext $context = null): ?OrderIntent
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'decisionKey' => $decisionKey,
        ], ['createdAt' => 'DESC']);
    }

    public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?OrderIntent
    {
        return $this->createQueryBuilder('oi')
            ->where('oi.exchange = :exchange')
            ->andWhere('oi.marketType = :marketType')
            ->andWhere('(oi.orderId = :orderId OR oi.exchangeOrderId = :orderId)')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('orderId', $orderId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneById(int $id): ?OrderIntent
    {
        return $this->find($id);
    }

    /**
     * @return OrderIntent[]
     */
    public function findByStatus(
        string $status,
        ?string $symbol = null,
        int $limit = 100,
        ?ExchangeContext $context = null,
    ): array
    {
        $qb = $this->createQueryBuilder('oi')
            ->where('oi.exchange = :exchange')
            ->andWhere('oi.marketType = :marketType')
            ->andWhere('oi.status = :status')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('status', $status)
            ->orderBy('oi.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('oi.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return OrderIntent[]
     */
    public function findReadyToSend(?string $symbol = null, int $limit = 50, ?ExchangeContext $context = null): array
    {
        return $this->findByStatus(OrderIntent::STATUS_READY_TO_SEND, $symbol, $limit, $context);
    }

    public function save(OrderIntent $intent): void
    {
        $this->getEntityManager()->persist($intent);
        $this->getEntityManager()->flush();
    }

    /**
     * Retourne la liste des symboles distincts pour lesquels un OrderIntent a été créé
     * depuis une date donnée. Utilisé pour limiter les appels BitMart aux seuls symboles
     * effectivement tradés récemment.
     *
     * @return string[]
     */
    public function findDistinctSymbolsSince(
        \DateTimeImmutable $since,
        int $max = 50,
        ?ExchangeContext $context = null,
    ): array
    {
        // Utilise un GROUP BY pour satisfaire PostgreSQL (ORDER BY sur une colonne agrégée)
        $qb = $this->createQueryBuilder('oi')
            ->select('oi.symbol AS symbol, MAX(oi.createdAt) AS lastCreatedAt')
            ->where('oi.exchange = :exchange')
            ->andWhere('oi.marketType = :marketType')
            ->andWhere('oi.createdAt >= :since')
            // On ne retient que les intents réellement envoyés ou finalisés côté exchange
            ->andWhere('oi.status IN (:statuses)')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('since', $since)
            ->setParameter('statuses', [
                OrderIntent::STATUS_SENT,
                OrderIntent::STATUS_FAILED,
                OrderIntent::STATUS_CANCELLED,
            ])
            ->groupBy('oi.symbol')
            ->orderBy('lastCreatedAt', 'DESC')
            ->setMaxResults($max);

        $results = $qb->getQuery()->getScalarResult();

        return array_values(array_map(
            static fn (array $row) => strtoupper((string) $row['symbol']),
            $results
        ));
    }
}
