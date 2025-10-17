<?php

namespace App\Repository;

use App\Entity\Exchange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ExchangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exchange::class);
    }

    public function findAllWithContracts(): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.contracts', 'c')
            ->addSelect('c')
            ->orderBy('e.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
