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
    public function findRecentBySymbol(
        string $symbol,
        int $limit = 200,
        ?\DateTimeImmutable $since = null
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.symbol = :symbol')
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('o.createdAt >= :since')
                ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }
}
