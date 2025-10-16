<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContractCooldown;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContractCooldown>
 */
final class ContractCooldownRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractCooldown::class);
    }

    public function findActive(string $symbol, ?DateTimeImmutable $now = null): ?ContractCooldown
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->createQueryBuilder('cooldown')
            ->andWhere('cooldown.symbol = :symbol')
            ->andWhere('cooldown.activeUntil > :now')
            ->setParameters([
                'symbol' => strtoupper($symbol),
                'now' => $now,
            ])
            ->orderBy('cooldown.activeUntil', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function purgeExpired(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->createQueryBuilder('cooldown')
            ->delete()
            ->andWhere('cooldown.activeUntil <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}

