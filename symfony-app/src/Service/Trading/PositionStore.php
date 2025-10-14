<?php
declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\Position;
use App\Repository\ContractRepository;
use App\Repository\PositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class PositionStore
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContractRepository $contracts,
        private readonly PositionRepository $positions,
        private readonly LoggerInterface $positionsLogger,
    ) {}

    /** Upsert la position (status OPEN) à partir d’un snapshot WS. */
    public function openOrUpdate(string $symbol, int $sideCode, ?float $avgOpenPrice, ?float $qtyContract, ?float $leverage = null): void
    {
        $symbol = strtoupper($symbol);
        $side   = $this->mapSide($sideCode);
        if ($side === null) {
            $this->positionsLogger->warning('[PositionStore] side invalide', compact('symbol','sideCode'));
            return;
        }

        $contract = $this->contracts->find($symbol);
        if (!$contract) {
            $this->positionsLogger->warning('[PositionStore] Contract introuvable', compact('symbol'));
            return;
        }

        $qb = $this->positions->createQueryBuilder('p')
            ->andWhere('p.contract = :c')->setParameter('c', $contract)
            ->andWhere('p.side = :s')->setParameter('s', $side)
            ->andWhere('p.status = :st')->setParameter('st', Position::STATUS_OPEN)
            ->setMaxResults(1);

        $existing = $qb->getQuery()->getOneOrNullResult();
        $now = new \DateTimeImmutable();

        if ($existing instanceof Position) {
            // Mise à jour des champs connus
            if ($avgOpenPrice !== null) { $existing->setEntryPrice(number_format($avgOpenPrice, 8, '.', '')); }
            if ($qtyContract  !== null) { $existing->setQtyContract(number_format($qtyContract, 12, '.', '')); }
            if ($leverage     !== null) { $existing->setLeverage(number_format($leverage, 2, '.', '')); }
            $existing->setUpdatedAt($now);
            $this->em->flush();
            return;
        }

        // Création d’une nouvelle position OPEN
        $p = new Position();
        $p->setContract($contract)
          ->setExchange('bitmart')
          ->setSide($side)
          ->setStatus(Position::STATUS_OPEN)
          ->setOpenedAt($now);
        if ($avgOpenPrice !== null) { $p->setEntryPrice(number_format($avgOpenPrice, 8, '.', '')); }
        if ($qtyContract  !== null) { $p->setQtyContract(number_format($qtyContract, 12, '.', '')); }
        if ($leverage     !== null) { $p->setLeverage(number_format($leverage, 2, '.', '')); }

        $this->em->persist($p);
        $this->em->flush();
        $this->positionsLogger->info('[PositionStore] OPEN persisted', ['symbol' => $symbol, 'side' => $side]);
    }

    /** Marque CLOSED toutes les positions OPEN pour (symbol, side). Retourne le nombre clos. */
    public function close(string $symbol, int $sideCode): int
    {
        $symbol = strtoupper($symbol);
        $side   = $this->mapSide($sideCode);
        if ($side === null) {
            $this->positionsLogger->warning('[PositionStore] side invalide (close)', compact('symbol','sideCode'));
            return 0;
        }

        $contract = $this->contracts->find($symbol);
        if (!$contract) {
            $this->positionsLogger->warning('[PositionStore] Contract introuvable (close)', compact('symbol'));
            return 0;
        }

        $list = $this->positions->createQueryBuilder('p')
            ->andWhere('p.contract = :c')->setParameter('c', $contract)
            ->andWhere('p.side = :s')->setParameter('s', $side)
            ->andWhere('p.status = :st')->setParameter('st', Position::STATUS_OPEN)
            ->getQuery()->getResult();

        $now = new \DateTimeImmutable();
        $n = 0;
        foreach ($list as $p) {
            if (!$p instanceof Position) { continue; }
            $p->setStatus(Position::STATUS_CLOSED);
            $p->setClosedAt($now);
            $p->setUpdatedAt($now);
            $n++;
        }
        if ($n > 0) {
            $this->em->flush();
            $this->positionsLogger->info('[PositionStore] CLOSED persisted', ['symbol' => $symbol, 'side' => $side, 'count' => $n]);
        }
        return $n;
    }

    private function mapSide(int $sideCode): ?string
    {
        return match($sideCode) {
            1 => Position::SIDE_LONG,
            2 => Position::SIDE_SHORT,
            default => null,
        };
    }
}

