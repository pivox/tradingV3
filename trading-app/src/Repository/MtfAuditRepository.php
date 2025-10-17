<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MtfAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\UuidInterface;

/**
 * @extends ServiceEntityRepository<MtfAudit>
 */
class MtfAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MtfAudit::class);
    }

    /**
     * Récupère les audits pour un symbole
     */
    public function findBySymbol(string $symbol, int $limit = 20): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.symbol = :symbol')
            ->setParameter('symbol', $symbol)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits pour un run_id
     */
    public function findByRunId(UuidInterface $runId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.runId = :runId')
            ->setParameter('runId', $runId)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits récents
     */
    public function findRecentAudits(int $limit = 50): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits par étape
     */
    public function findByStep(string $step, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.step = :step')
            ->setParameter('step', $step)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les audits dans une plage de dates
     */
    public function findByDateRange(
        \DateTimeImmutable $startDate, 
        \DateTimeImmutable $endDate
    ): array {
        return $this->createQueryBuilder('m')
            ->where('m.createdAt >= :startDate')
            ->andWhere('m.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les audits par symbole
     */
    public function countBySymbol(string $symbol): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.symbol = :symbol')
            ->setParameter('symbol', $symbol)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les audits par étape
     */
    public function countByStep(string $step): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.step = :step')
            ->setParameter('step', $step)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprime les audits anciens
     */
    public function deleteOldAudits(\DateTimeImmutable $cutoffDate): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->where('m.createdAt < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}




