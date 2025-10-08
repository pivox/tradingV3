<?php

namespace App\Repository;

use App\Entity\Contract;
use App\Entity\ContractPipeline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ContractPipelineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContractPipeline::class);
    }


    /**
     * Retourne tous les pipelines au statut OPENED_LOCKED.
     * @return ContractPipeline[]
     */
    public function findAllOpenedLocked(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :st')->setParameter('st', ContractPipeline::STATUS_OPENED_LOCKED)
            ->orWhere('p.status = :st')->setParameter('st', ContractPipeline::STATUS_ORDER_OPENED)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les symboles (string[]) au statut OPENED_LOCKED.
     * Utilise la relation vers Contract(symbol).
     * @return string[]
     */
    public function getAllSymbolsOpenedLocked(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->innerJoin('p.contract', 'c')
            ->select('c.symbol AS symbol')
            ->andWhere('p.status = :st')->setParameter('st', ContractPipeline::STATUS_OPENED_LOCKED)
            ->getQuery()
            ->getResult();

        return array_map(static fn(array $r) => $r['symbol'], $rows) ?? [];
    }


    /**
     * Retourne les contrats éligibles à un timeframe donné (pending) avec un cap de résultats.
     */
    public function findEligibleFor(string $timeframe, int $limit = 500): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.currentTimeframe = :tf')->setParameter('tf', $timeframe)
            ->andWhere('p.status = :st')->setParameter('st', ContractPipeline::STATUS_PENDING)
            ->orderBy('p.updatedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getAllSymbolsWithActive4h(): array
    {
        return $this->getAllSymbolsWithActiveTimeframe('4h');
    }

    /**
     * Met à jour l'orderId pour un symbole donné
     */
    public function updateOrderIdBySymbol(string $symbol, string $orderId): void
    {
        $this->createQueryBuilder('p')
            ->update()
            ->set('p.orderId', ':orderId')
            ->where('IDENTITY(p.contract) = :symbol')
            ->setParameter('orderId', $orderId)
            ->setParameter('symbol', $symbol)
            ->getQuery()
            ->execute();
    }

    public function getAllSymbolsWithActive1h(): array
    {
        return $this->getAllSymbolsWithActiveTimeframe('1h');
    }

    public function getAllSymbolsWithActive15m(): array
    {
        return $this->getAllSymbolsWithActiveTimeframe('15m');
    }

    public function getAllSymbolsWithActive5m(): array
    {
        return $this->getAllSymbolsWithActiveTimeframe('5m');
    }

    public function getAllSymbolsWithActive1m(): array
    {
        return $this->getAllSymbolsWithActiveTimeframe('1m');
    }

    public function getAllSymbolsWithActiveTimeframe(string $timeframe, int $limit = 221): array
    {
        return $this->createQueryBuilder('cp')
            ->from(Contract::class, 'c') // root supplémentaire
            ->innerJoin('c.contractPipeline', 'p')
            ->select('c')
            ->where('p.currentTimeframe = :tf')->setParameter('tf', $timeframe)
            ->andWhere('p.status != :locked')->setParameter('locked', ContractPipeline::STATUS_OPENED_LOCKED)
            ->andWhere('p.status != :locked2')->setParameter('locked2', ContractPipeline::STATUS_ORDER_OPENED)
            ->andWhere('p.orderId IS NULL')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getAllSymbols(): array
    {
        $result = $this->createQueryBuilder('p')
            ->join('p.contract', 'c')
            ->select('c.symbol AS contractId')
            ->where('p.currentTimeframe != :tf')->setParameter('tf', '4h')
            ->getQuery()
            ->getResult();

        return array_map(fn($item) => $item['contractId'], $result) ?? [];
    }

    public function updateStatusBySymbol(string $symbol, string $status): int
    {
        return $this->createQueryBuilder('p')
            ->update()
            ->set('p.status', ':status')
            ->where('IDENTITY(p.contract) = :symbol')
            ->setParameter('status', $status)
            ->setParameter('symbol', $symbol)
            ->getQuery()
            ->execute();
    }

    public function isValidByTimeframe(string $string, true $true)
    {
    }

}
