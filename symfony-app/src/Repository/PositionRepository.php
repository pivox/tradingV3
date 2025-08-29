<?php

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\Position;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    /** Dernières positions ouvertes pour un contrat */
    public function findOpenByContract(Contract $contract): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.contract = :c')->setParameter('c', $contract)
            ->andWhere('p.status = :s')->setParameter('s', Position::STATUS_OPEN)
            ->orderBy('p.openedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** Positions actives (OPEN ou PENDING) toutes paires confondues */
    public function findActive(int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status IN (:sts)')->setParameter('sts', [Position::STATUS_OPEN, Position::STATUS_PENDING])
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
