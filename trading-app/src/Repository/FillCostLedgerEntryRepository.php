<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FillCostLedgerEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FillCostLedgerEntry>
 */
class FillCostLedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FillCostLedgerEntry::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?FillCostLedgerEntry
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    /**
     * @return FillCostLedgerEntry[]
     */
    public function findByInternalTradeId(string $internalTradeId): array
    {
        return $this->createQueryBuilder('entry')
            ->andWhere('entry.internalTradeId = :internalTradeId')
            ->setParameter('internalTradeId', $internalTradeId)
            ->orderBy('entry.occurredAt', 'ASC')
            ->addOrderBy('entry.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(FillCostLedgerEntry $entry): void
    {
        $this->getEntityManager()->persist($entry);
        $this->getEntityManager()->flush();
    }
}
