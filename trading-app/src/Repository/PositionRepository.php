<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Position;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Position>
 */
class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    public function findOneBySymbolSide(string $symbol, string $side): ?Position
    {
        return $this->findOneBy([
            'symbol' => strtoupper($symbol),
            'side' => strtoupper($side),
        ]);
    }

    public function upsert(Position $position): void
    {
        $em = $this->getEntityManager();
        $em->persist($position);
        $em->flush();
    }
}


