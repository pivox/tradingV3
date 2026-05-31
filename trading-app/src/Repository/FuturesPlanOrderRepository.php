<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuturesPlanOrder;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuturesPlanOrder>
 */
final class FuturesPlanOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuturesPlanOrder::class);
    }

    public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?FuturesPlanOrder
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'orderId' => $orderId,
        ]);
    }

    public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?FuturesPlanOrder
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'clientOrderId' => $clientOrderId,
        ]);
    }

    /**
     * @return FuturesPlanOrder[]
     */
    public function findBySymbol(?string $symbol = null, int $limit = 100, ?ExchangeContext $context = null): array
    {
        $qb = $this->createQueryBuilder('fpo')
            ->where('fpo.exchange = :exchange')
            ->andWhere('fpo.marketType = :marketType')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->orderBy('fpo.createdTime', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fpo.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return FuturesPlanOrder[]
     */
    public function findByStatus(
        string $status,
        ?string $symbol = null,
        int $limit = 100,
        ?ExchangeContext $context = null,
    ): array
    {
        $qb = $this->createQueryBuilder('fpo')
            ->where('fpo.exchange = :exchange')
            ->andWhere('fpo.marketType = :marketType')
            ->andWhere('fpo.status = :status')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('status', strtolower($status))
            ->orderBy('fpo.createdTime', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fpo.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    public function save(FuturesPlanOrder $order): void
    {
        $this->getEntityManager()->persist($order);
        $this->getEntityManager()->flush();
    }
}
