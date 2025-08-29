<?php

namespace App\Service\Trading;

use App\Entity\Contract;
use App\Entity\Position;
use App\Repository\KlineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class PositionManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly KlineRepository $klines,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Ouvre une position "virtuelle" de 100 USDT sur le dernier close 1m.
     * $side : Position::SIDE_LONG ou Position::SIDE_SHORT (selon ta stratégie).
     */
    public function openPositionOn1m(Contract $contract, string $side, float $amountUsdt = 100.0, ?float $leverage = null, ?array $meta = null): Position
    {
        // 1) Dernier kline 1m (step = 60s)
        $last1m = $this->klines->findOneBy(
            ['contract' => $contract, 'step' => 60],
            ['timestamp' => 'DESC']
        );
        if (!$last1m) {
            // si pas de dernière bougie 1m, on enregistre la position "PENDING"
            $this->logger->warning('Pas de dernier kline 1m, position en PENDING', ['symbol' => $contract->getSymbol()]);
        }

        // 2) Calcule le prix d’entrée (dernier close 1m si dispo)
        $entryPrice = $last1m ? (float) $last1m->getClose() : null;
        $qty        = $entryPrice ? ($amountUsdt / $entryPrice) : null;

        // 3) Crée la position
        $p = new Position();
        $p->setContract($contract);
        $p->setExchange('bitmart');
        $p->setSide($side);
        $p->setAmountUsdt((string) $amountUsdt);
        $p->setLeverage($leverage ? (string)$leverage : null);
        $p->setMeta($meta);

        if ($entryPrice && $qty) {
            $p->setEntryPrice((string) $entryPrice);
            $p->setQtyContract((string) $qty);
            $p->setStatus(Position::STATUS_OPEN);
            $p->setOpenedAt(new \DateTimeImmutable());
        } else {
            // pas de prix → en attente (sera complété plus tard)
            $p->setStatus(Position::STATUS_PENDING);
        }

        $this->em->persist($p);
        $this->em->flush();

        $this->logger->info('Position créée', [
            'symbol' => $contract->getSymbol(),
            'side' => $side,
            'amount_usdt' => $amountUsdt,
            'entry_price' => $entryPrice,
            'qty' => $qty,
            'status' => $p->getStatus()
        ]);

        return $p;
    }
}
