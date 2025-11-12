<?php

declare(strict_types=1);

namespace App\MtfValidator\Repository;

use App\MtfValidator\Entity\MtfRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MtfRun>
 */
final class MtfRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MtfRun::class);
    }
}

