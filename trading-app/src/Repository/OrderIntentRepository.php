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

    /**
     * Retourne la liste des symboles distincts pour lesquels un OrderIntent a été créé
     * depuis une date donnée. Utilisé pour limiter les appels BitMart aux seuls symboles
     * effectivement tradés récemment.
     *
     * @return string[]
     */
    public function findDistinctSymbolsSince(\DateTimeImmutable $since, int $max = 50): array
    {
        // Utilise un GROUP BY pour satisfaire PostgreSQL (ORDER BY sur une colonne agrégée)
        $qb = $this->createQueryBuilder('oi')
            ->select('oi.symbol AS symbol, MAX(oi.createdAt) AS lastCreatedAt')
            ->where('oi.createdAt >= :since')
            // On ne retient que les intents réellement envoyés ou finalisés côté exchange
            ->andWhere('oi.status IN (:statuses)')
            ->setParameter('since', $since)
            ->setParameter('statuses', [
                OrderIntent::STATUS_SENT,
                OrderIntent::STATUS_FAILED,
                OrderIntent::STATUS_CANCELLED,
            ])
            ->groupBy('oi.symbol')
            ->orderBy('lastCreatedAt', 'DESC')
            ->setMaxResults($max);

        $results = $qb->getQuery()->getScalarResult();

        return array_values(array_map(
            static fn (array $row) => strtoupper((string) $row['symbol']),
            $results
        ));
    }
}
