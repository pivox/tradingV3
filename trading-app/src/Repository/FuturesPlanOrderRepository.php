<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuturesPlanOrder;
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

    public function findOneByOrderId(string $orderId): ?FuturesPlanOrder
    {
        return $this->findOneBy(['orderId' => $orderId]);
    }

    public function findOneByClientOrderId(string $clientOrderId): ?FuturesPlanOrder
    {
        return $this->findOneBy(['clientOrderId' => $clientOrderId]);
    }

    /**
     * @return FuturesPlanOrder[]
     */
    public function findBySymbol(?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fpo')
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
    public function findByStatus(string $status, ?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fpo')
            ->where('fpo.status = :status')
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

