<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BlacklistedContract;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlacklistedContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlacklistedContract::class);
    }

    /**
     * Vérifie si un contrat est blacklisté et non expiré
     */
    public function isBlacklisted(string $symbol): bool
    {
        $now = new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.symbol = :symbol')
            ->andWhere('(b.expiresAt IS NULL OR b.expiresAt > :now)')
            ->setParameter('symbol', strtoupper($symbol))
            ->setParameter('now', $now);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
