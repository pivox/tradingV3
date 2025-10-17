<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderPlan>
 */
class OrderPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderPlan::class);
    }

    /**
     * Récupère les plans d'ordre pour un symbole
     */
    public function findBySymbol(string $symbol, int $limit = 50): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.symbol = :symbol')
            ->setParameter('symbol', $symbol)
            ->orderBy('o.planTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les plans d'ordre par statut
     */
    public function findByStatus(string $status, int $limit = 100): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', $status)
            ->orderBy('o.planTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les plans d'ordre planifiés
     */
    public function findPlannedOrders(): array
    {
        return $this->findByStatus('PLANNED');
    }

    /**
     * Récupère les plans d'ordre exécutés
     */
    public function findExecutedOrders(): array
    {
        return $this->findByStatus('EXECUTED');
    }

    /**
     * Récupère les plans d'ordre échoués
     */
    public function findFailedOrders(): array
    {
        return $this->findByStatus('FAILED');
    }

    /**
     * Récupère les plans d'ordre dans une plage de dates
     */
    public function findByDateRange(
        \DateTimeImmutable $startDate, 
        \DateTimeImmutable $endDate
    ): array {
        return $this->createQueryBuilder('o')
            ->where('o.planTime >= :startDate')
            ->andWhere('o.planTime <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('o.planTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les plans d'ordre récents
     */
    public function findRecentOrders(int $limit = 20): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.planTime', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les plans d'ordre par statut
     */
    public function countByStatus(string $status): int
    {
        return $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Compte les plans d'ordre par symbole
     */
    public function countBySymbol(string $symbol): int
    {
        return $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->where('o.symbol = :symbol')
            ->setParameter('symbol', $symbol)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Met à jour le statut d'un plan d'ordre
     */
    public function updateStatus(int $id, string $status): void
    {
        $this->createQueryBuilder('o')
            ->update()
            ->set('o.status', ':status')
            ->where('o.id = :id')
            ->setParameter('status', $status)
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les anciens plans d'ordre
     */
    public function deleteOldOrders(\DateTimeImmutable $cutoffDate): int
    {
        return $this->createQueryBuilder('o')
            ->delete()
            ->where('o.planTime < :cutoffDate')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('cutoffDate', $cutoffDate)
            ->setParameter('statuses', ['EXECUTED', 'CANCELLED', 'FAILED'])
            ->getQuery()
            ->execute();
    }
}




