<?php
declare(strict_types=1);


namespace App\Service\Trading;


use App\Entity\Contract;
use App\Entity\Position;
use Doctrine\ORM\EntityManagerInterface;

class PositionRecorder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Enregistre une position en statut PENDING et retourne l'entité.
     * @param array{tp1Qty?: float, runnerQty?: float, mock?: mixed} $meta
     */
    public function recordPending(
        Contract $contract,
        string $sideUpper,            // 'LONG'|'SHORT'
        float $entryPrice,
        float $qtyContract,
        float $stopPrice,
        float $tp1Price,
        float $leverage,
        ?string $externalOrderId,
        float $amountUsdt,
        array $meta = []
    ): Position {
        $p = new Position();
        $p->setContract($contract)
            ->setExchange('bitmart')
            ->setSide($sideUpper)
            ->setStatus(Position::STATUS_PENDING)
            ->setEntryPrice($this->toDecimal($entryPrice, 8))      // precision=8 (colonne)
            ->setQtyContract($this->toDecimal($qtyContract, 12))   // precision=12 (colonne)
            ->setStopLoss($this->toDecimal($stopPrice, 8))
            ->setTakeProfit($this->toDecimal($tp1Price, 8))
            ->setLeverage($this->toDecimal($leverage, 2))
            ->setExternalOrderId($externalOrderId)
            ->setAmountUsdt($this->toDecimal($amountUsdt, 8))
            ->setMeta($meta)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($p);
        $this->em->flush();

        return $p;
    }

    private function toDecimal(float $value, int $scale): string
    {
        // évite notations scientifiques & garde un format DB-friendly
        return number_format($value, $scale, '.', '');
    }
}
