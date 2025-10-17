<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\MtfPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class MtfPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, MtfPlan::class); }

    /** Retourne les symboles activés pour un TF donné. */
    public function findEnabledSymbolsFor(string $tf): array
    {
        $col = match ($tf) {
            '4h'  => 'enabled4h',
            '1h'  => 'enabled1h',
            '15m' => 'enabled15m',
            '5m'  => 'enabled5m',
            '1m'  => 'enabled1m',
            default => throw new \InvalidArgumentException("TF invalide: $tf"),
        };

        $qb = $this->createQueryBuilder('p')
            ->select('p.symbol')
            ->where("p.$col = :on")->setParameter('on', true)
            ->orderBy('p.symbol', 'ASC');

        return array_column($qb->getQuery()->getScalarResult(), 'symbol');
    }

    /** Lecture du flag cascade_parents pour un symbole. */
    public function shouldCascade(string $symbol): bool
    {
        $row = $this->createQueryBuilder('p')
            ->select('p.cascadeParents AS c')
            ->where('p.symbol = :s')->setParameter('s', $symbol)
            ->getQuery()->getOneOrNullResult();

        return (bool)($row['c'] ?? true);
    }
}
