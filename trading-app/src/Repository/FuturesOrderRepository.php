<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuturesOrder;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuturesOrder>
 */
final class FuturesOrderRepository extends ServiceEntityRepository
{
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
            ->setParameter('statuses', ['pending', 'partially_filled', 'new', 'sent', 'open', 'submitted'])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
