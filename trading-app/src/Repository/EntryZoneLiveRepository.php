<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryZoneLive;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryZoneLive>
 */
final class EntryZoneLiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryZoneLive::class);
    }

    public function save(EntryZoneLive $entry, bool $flush = false): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entry);

        if ($flush) {
            $entityManager->flush();
        }
    }
}
