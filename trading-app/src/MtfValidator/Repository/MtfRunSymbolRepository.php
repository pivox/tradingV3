<?php

declare(strict_types=1);

namespace App\MtfValidator\Repository;

use App\MtfValidator\Entity\MtfRunSymbol;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MtfRunSymbol>
 */
final class MtfRunSymbolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MtfRunSymbol::class);
    }
}

