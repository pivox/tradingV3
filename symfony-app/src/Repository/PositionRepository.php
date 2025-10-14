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

    // Expire toutes les PENDING dont le TTL est dépassé
    public function expireStalePending(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $qb = $this->createQueryBuilder('p')
            ->update()
            ->set('p.status', ':expired')
            ->set('p.updatedAt', ':now')
            ->where('p.status = :pending')
            ->andWhere('p.expiresAt IS NOT NULL')
            ->andWhere('p.expiresAt <= :now')
            ->setParameters([
                'expired' => Position::STATUS_EXPIRED,
                'pending' => Position::STATUS_PENDING,
                'now'     => $now,
            ]);

        return $qb->getQuery()->execute();
    }

    // Marque EXPIRED si l’ordre n’existe pas / est introuvable chez BitMart
    public function expireIfMissingOnExchange(string $externalOrderId): void
    {
        $em = $this->getEntityManager();
        $p  = $this->findOneBy(['externalOrderId' => $externalOrderId, 'status' => Position::STATUS_PENDING]);
        if (!$p) { return; }
        $p->setStatus(Position::STATUS_EXPIRED);
        $p->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
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

    public function findWithFilters(?string $status = null, ?string $side = null, ?string $contract = null, ?string $exchange = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.contract', 'c')
            ->addSelect('c')
            ->leftJoin('c.exchange', 'e')
            ->addSelect('e')
            ->orderBy('p.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('p.status = :status')
                ->setParameter('status', $status);
        }

        if ($side) {
            $qb->andWhere('p.side = :side')
                ->setParameter('side', $side);
        }

        if ($contract) {
            $qb->andWhere('c.symbol LIKE :contract')
                ->setParameter('contract', '%' . $contract . '%');
        }

        if ($exchange) {
            $qb->andWhere('e.name = :exchange')
                ->setParameter('exchange', $exchange);
        }

        return $qb->getQuery()->getResult();
    }
}
