<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuturesOrder;
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

    public function findOneByOrderId(string $orderId): ?FuturesOrder
    {
        return $this->findOneBy(['orderId' => $orderId]);
    }

    public function findOneByClientOrderId(string $clientOrderId): ?FuturesOrder
    {
        return $this->findOneBy(['clientOrderId' => $clientOrderId]);
    }

    /**
     * @return FuturesOrder[]
     */
    public function findBySymbol(?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fo')
            ->orderBy('fo.createdTime', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fo.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return FuturesOrder[]
     */
    public function findByStatus(string $status, ?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fo')
            ->where('fo.status = :status')
            ->setParameter('status', strtolower($status))
            ->orderBy('fo.createdTime', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fo.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    public function save(FuturesOrder $order): void
    {
        $this->getEntityManager()->persist($order);
        $this->getEntityManager()->flush();
    }
}

