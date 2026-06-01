<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SymbolExecutionLock;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SymbolExecutionLock>
 */
final class SymbolExecutionLockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SymbolExecutionLock::class);
    }

    public function findActive(string $exchange, string $marketType, string $symbol): ?SymbolExecutionLock
    {
        return $this->findOneBy([
            'exchange' => strtolower(trim($exchange)),
            'marketType' => strtolower(trim($marketType)),
            'symbol' => strtoupper(trim($symbol)),
            'releasedAt' => null,
        ]);
    }

    /**
     * @return SymbolExecutionLock[]
     */
    public function findActiveLocks(
        ?ExchangeContext $context = null,
        ?string $symbol = null,
        int $limit = 100,
    ): array {
        $qb = $this->createQueryBuilder('lock')
            ->andWhere('lock.releasedAt IS NULL')
            ->orderBy('lock.lockedAt', 'DESC')
            ->setMaxResults($limit);

        if ($context instanceof ExchangeContext) {
            $qb
                ->andWhere('lock.exchange = :exchange')
                ->andWhere('lock.marketType = :marketType')
                ->setParameter('exchange', $context->exchange->value)
                ->setParameter('marketType', $context->marketType->value);
        }

        if ($symbol !== null && trim($symbol) !== '') {
            $qb
                ->andWhere('lock.symbol = :symbol')
                ->setParameter('symbol', strtoupper(trim($symbol)));
        }

        return $qb->getQuery()->getResult();
    }

    public function save(SymbolExecutionLock $lock): void
    {
        $this->getEntityManager()->persist($lock);
        $this->getEntityManager()->flush();
    }
}
