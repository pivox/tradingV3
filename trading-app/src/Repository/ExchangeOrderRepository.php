<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExchangeOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExchangeOrder>
 */
final class ExchangeOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeOrder::class);
    }

    public function findOneByClientOrderId(string $clientOrderId): ?ExchangeOrder
    {
        return $this->findOneBy(['clientOrderId' => strtoupper($clientOrderId)]);
    }

    /**
     * @return ExchangeOrder[]
     */
    public function search(?string $symbol = null, ?string $kind = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('exchangeOrder')
            ->orderBy('exchangeOrder.submittedAt', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('exchangeOrder.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        if ($kind !== null && $kind !== '') {
            $qb->andWhere('exchangeOrder.kind = :kind')
                ->setParameter('kind', strtoupper($kind));
        }

        return $qb->getQuery()->getResult();
    }
}
