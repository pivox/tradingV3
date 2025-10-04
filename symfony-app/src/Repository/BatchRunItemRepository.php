<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BatchRun;
use App\Entity\BatchRunItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ManagerRegistry;

final class BatchRunItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BatchRunItem::class);
    }

    /** Récupère tous les items d’un run. (Pratique pour enqueuer) */
    public function findByRun(BatchRun $run): array
    {
        return $this->findBy(['batchRun'=>$run]);
    }

    /** Idempotence callback: récupère l’item par run+symbol */
    public function findOneByRunAndSymbol(BatchRun $run, string $symbol): ?BatchRunItem
    {
        return $this->createQueryBuilder('i')
            ->where('i.batchRun = :run AND i.symbol = :sym')
            ->setParameters(new ArrayCollection(['run'=>$run, 'sym'=>strtoupper($symbol)]))
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }
}
