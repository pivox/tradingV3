<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BatchRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ManagerRegistry;

final class BatchRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BatchRun::class);
    }

    public function getOrCreate(string $tf, \DateTimeImmutable $slotStartUtc, \DateTimeImmutable $slotEndUtc): BatchRun
    {
        $run = $this->findOneBy(['timeframe'=>$tf, 'slotStartUtc'=>$slotStartUtc]);
        if ($run) return $run;

        $run = new BatchRun($tf, $slotStartUtc, $slotEndUtc);
        $em = $this->getEntityManager();
        $em->persist($run);
        $em->flush();

        return $run;
    }

    public function findCreatedOrRunning(string $tf, \DateTimeImmutable $slotStartUtc): ?BatchRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.timeframe = :tf AND r.slotStartUtc = :start AND r.status IN (:st)')
            ->setParameters(new ArrayCollection([
                'tf' => $tf,
                'start' => $slotStartUtc,
                'st' => [BatchRun::STATUS_CREATED, BatchRun::STATUS_RUNNING],
            ]))
            ->getQuery()->getOneOrNullResult();
    }

    /** Dernier BatchRun SUCCESS du TF donné, dont slot_end <= borne */
    public function findLastSuccessUntil(string $tf, \DateTimeImmutable $until): ?BatchRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.timeframe = :tf AND r.status = :ok AND r.slotEndUtc <= :until')
            ->setParameters(new ArrayCollection(['tf'=>$tf, 'ok'=>BatchRun::STATUS_SUCCESS, 'until'=>$until]))
            ->orderBy('r.slotEndUtc', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }


    public function atomicDecrementRemaining(int $runId): int
    {
        return $this->_em->createQuery(
            'UPDATE App\Entity\BatchRun r
         SET r.remaining = r.remaining - 1
         WHERE r.id = :id AND r.remaining > 0'
        )
            ->setParameter('id', $runId)
            ->execute(); // retourne le nb de lignes affectées (0 ou 1)
    }

}
