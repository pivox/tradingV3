<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderIntent;
use App\Entity\FuturesOrder;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuturesOrder>
 */
final class FuturesOrderRepository extends ServiceEntityRepository
{
    private const OPEN_STATUSES = ['pending', 'partially_filled', 'new', 'sent', 'open', 'submitted'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuturesOrder::class);
    }

    public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?FuturesOrder
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'orderId' => $orderId,
        ]);
    }

    public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?FuturesOrder
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'clientOrderId' => $clientOrderId,
        ]);
    }

    /**
     * @return FuturesOrder[]
     */
    public function findRecentBySymbol(
        string $symbol,
        int $limit = 200,
        ?\DateTimeImmutable $since = null,
        ?ExchangeContext $context = null,
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.exchange = :exchange')
            ->andWhere('o.marketType = :marketType')
            ->andWhere('o.symbol = :symbol')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('o.createdAt >= :since')
                ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    public function hasOpenOrderForSymbol(string $symbol, ?ExchangeContext $context = null): bool
    {
        $count = $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.exchange = :exchange')
            ->andWhere('o.marketType = :marketType')
            ->andWhere('o.symbol = :symbol')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', strtoupper($symbol))
            ->setParameter('statuses', self::OPEN_STATUSES)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function markOpenOrdersCancelledForIntent(OrderIntent $intent): int
    {
        $ids = array_values(array_filter([
            $intent->getExchangeOrderId(),
            $intent->getOrderId(),
        ], static fn (?string $id): bool => $id !== null && trim($id) !== ''));
        $clientOrderId = $intent->getClientOrderId();

        if ($ids === [] && trim($clientOrderId) === '') {
            return 0;
        }

        $qb = $this->createQueryBuilder('o')
            ->update()
            ->set('o.status', ':cancelled')
            ->set('o.updatedAt', ':now')
            ->where('o.exchange = :exchange')
            ->andWhere('o.marketType = :marketType')
            ->andWhere('o.symbol = :symbol')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('cancelled', 'cancelled')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('exchange', $intent->getExchange())
            ->setParameter('marketType', $intent->getMarketType())
            ->setParameter('symbol', strtoupper($intent->getSymbol()))
            ->setParameter('statuses', self::OPEN_STATUSES);

        if ($ids !== []) {
            $qb->andWhere('(o.clientOrderId = :clientOrderId OR o.orderId IN (:orderIds))')
                ->setParameter('clientOrderId', $clientOrderId)
                ->setParameter('orderIds', $ids);
        } else {
            $qb->andWhere('o.clientOrderId = :clientOrderId')
                ->setParameter('clientOrderId', $clientOrderId);
        }

        return (int) $qb->getQuery()->execute();
    }
}
