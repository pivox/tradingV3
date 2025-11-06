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

    /**
     * Récupère toutes les positions ouvertes
     * @return Position[]
     */
    public function findAllOpen(): array
    {
        return $this->findBy(['status' => 'OPEN']);
    }

    /**
     * Récupère les symboles uniques des positions ouvertes
     * @return string[]
     */
    public function findOpenSymbols(): array
    {
        $qb = $this->createQueryBuilder('p');
        $results = $qb
            ->select('DISTINCT p.symbol')
            ->where('p.status = :status')
            ->setParameter('status', 'OPEN')
            ->getQuery()
            ->getResult();

        // Doctrine peut retourner soit un tableau associatif ['symbol' => '...'], soit un tableau indexé
        return array_map(function($row) {
            if (is_array($row)) {
                return isset($row['symbol']) ? (string)$row['symbol'] : (string)array_values($row)[0];
            }
            return (string)$row;
        }, $results);
    }
}


