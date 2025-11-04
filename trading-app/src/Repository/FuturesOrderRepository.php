<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FuturesOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuturesOrder>
 */
final class FuturesOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuturesOrder::class);
    }

    public function findOneByOrderId(string $orderId): ?FuturesOrder
    {
        return $this->findOneBy(['orderId' => $orderId]);
    }

    public function findOneByClientOrderId(string $clientOrderId): ?FuturesOrder
    {
        return $this->findOneBy(['clientOrderId' => $clientOrderId]);
    }

    /**
     * @return FuturesOrder[]
     */
    public function findBySymbol(?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fo')
            ->orderBy('fo.createdTime', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fo.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return FuturesOrder[]
     */
    public function findByStatus(string $status, ?string $symbol = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fo')
            ->where('fo.status = :status')
            ->setParameter('status', strtolower($status))
            ->orderBy('fo.createdTime', 'DESC')
            ->setMaxResults($limit);

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fo.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        return $qb->getQuery()->getResult();
    }

    public function save(FuturesOrder $order): void
    {
        $this->getEntityManager()->persist($order);
        $this->getEntityManager()->flush();
    }

    /**
     * Récupère les ordres avec filtres optionnels
     * @param string|null $symbol
     * @param string|null $status
     * @param string|null $kind 'main', 'sl', 'tp'
     * @param int $limit
     * @return FuturesOrder[]
     */
    public function findWithFilters(?string $symbol = null, ?string $status = null, ?string $kind = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('fo')
            ->orderBy('fo.createdTime', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)));

        if ($symbol !== null && $symbol !== '') {
            $qb->andWhere('fo.symbol = :symbol')
                ->setParameter('symbol', strtoupper($symbol));
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere('fo.status = :status')
                ->setParameter('status', strtolower($status));
        }

        $orders = $qb->getQuery()->getResult();

        // Filtrer par kind si spécifié (nécessite vérification dans rawData)
        if ($kind !== null && $kind !== '') {
            $orders = array_filter($orders, function (FuturesOrder $order) use ($kind) {
                $rawData = $order->getRawData();
                $orderKind = $this->detectOrderKind($rawData);
                return $orderKind === strtolower($kind);
            });
        }

        return array_values($orders);
    }

    /**
     * Détecte le type d'ordre depuis rawData
     * @param array<string,mixed> $rawData
     * @return string 'main', 'sl', 'tp', ou 'unknown'
     */
    private function detectOrderKind(array $rawData): string
    {
        if (isset($rawData['preset_take_profit_price']) && $rawData['preset_take_profit_price'] !== null) {
            return 'tp';
        }

        if (isset($rawData['preset_stop_loss_price']) && $rawData['preset_stop_loss_price'] !== null) {
            return 'sl';
        }

        return 'main';
    }
}

