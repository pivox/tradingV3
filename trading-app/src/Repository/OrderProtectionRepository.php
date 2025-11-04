<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderProtection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderProtection>
 */
final class OrderProtectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderProtection::class);
    }

    /**
     * @return OrderProtection[]
     */
    public function findByOrderIntent(int $orderIntentId): array
    {
        return $this->createQueryBuilder('op')
            ->where('op.orderIntent = :orderIntentId')
            ->setParameter('orderIntentId', $orderIntentId)
            ->orderBy('op.type', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return OrderProtection[]
     */
    public function findByType(string $type, int $orderIntentId): array
    {
        return $this->createQueryBuilder('op')
            ->where('op.orderIntent = :orderIntentId')
            ->andWhere('op.type = :type')
            ->setParameter('orderIntentId', $orderIntentId)
            ->setParameter('type', strtolower($type))
            ->getQuery()
            ->getResult();
    }

    public function save(OrderProtection $protection): void
    {
        $this->getEntityManager()->persist($protection);
        $this->getEntityManager()->flush();
    }
}

