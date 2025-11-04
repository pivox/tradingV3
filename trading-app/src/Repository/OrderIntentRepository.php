<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderIntent;
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

    public function findOneByClientOrderId(string $clientOrderId): ?OrderIntent
    {
        return $this->findOneBy(['clientOrderId' => $clientOrderId]);
    }

    public function findOneByOrderId(string $orderId): ?OrderIntent
    {
        return $this->findOneBy(['orderId' => $orderId]);
    }

    /**
     * @return OrderIntent[]
     */
    public function findByStatus(string $status, ?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('oi')
            ->where('oi.status = :status')
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
    public function findReadyToSend(?string $symbol = null, int $limit = 50): array
    {
        return $this->findByStatus(OrderIntent::STATUS_READY_TO_SEND, $symbol, $limit);
    }

    public function save(OrderIntent $intent): void
    {
        $this->getEntityManager()->persist($intent);
        $this->getEntityManager()->flush();
    }
}

