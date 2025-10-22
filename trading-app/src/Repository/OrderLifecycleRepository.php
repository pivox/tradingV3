<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderLifecycle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderLifecycle>
 */
final class OrderLifecycleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderLifecycle::class);
    }

    public function findOneByOrderId(string $orderId): ?OrderLifecycle
    {
        return $this->findOneBy(['orderId' => $orderId]);
    }

    public function findOneByClientOrderId(string $clientOrderId): ?OrderLifecycle
    {
        return $this->findOneBy(['clientOrderId' => strtoupper($clientOrderId)]);
    }

    /**
     * @return OrderLifecycle[]
     */
    public function findBySymbol(string $symbol, int $limit = 50): array
    {
        return $this->createQueryBuilder('lifecycle')
            ->andWhere('lifecycle.symbol = :symbol')
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('lifecycle.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
