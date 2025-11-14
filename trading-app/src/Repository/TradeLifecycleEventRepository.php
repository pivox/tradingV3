<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradeLifecycleEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeLifecycleEvent>
 */
final class TradeLifecycleEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeLifecycleEvent::class);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return TradeLifecycleEvent[]
     */
    public function findRecentBy(array $criteria, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('event')
            ->orderBy('event.happenedAt', 'DESC')
            ->setMaxResults($limit);

        foreach ($criteria as $field => $value) {
            $param = ':' . $field;
            $qb->andWhere(sprintf('event.%s = %s', $field, $param))
                ->setParameter($field, $value);
        }

        return $qb->getQuery()->getResult();
    }
}
