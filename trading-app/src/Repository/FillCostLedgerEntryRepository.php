<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FillCostLedgerEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

/**
 * @extends ServiceEntityRepository<FillCostLedgerEntry>
 */
class FillCostLedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(private ManagerRegistry $registry)
    {
        parent::__construct($this->registry, FillCostLedgerEntry::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?FillCostLedgerEntry
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    public function resetManagerAndFindOneByIdempotencyKey(string $idempotencyKey): ?FillCostLedgerEntry
    {
        $this->registry->resetManager();
        $manager = $this->registry->getManagerForClass(FillCostLedgerEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('Could not reset the fill-cost ledger entity manager.');
        }

        return $manager->createQueryBuilder()
            ->select('entry')
            ->from(FillCostLedgerEntry::class, 'entry')
            ->andWhere('entry.idempotencyKey = :idempotencyKey')
            ->setParameter('idempotencyKey', $idempotencyKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
