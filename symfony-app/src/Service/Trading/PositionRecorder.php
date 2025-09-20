<?php

declare(strict_types=1);

namespace App\Service\Trading;

use App\Entity\Position;
use App\Entity\Contract;
use App\Repository\ContractRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistre les positions (PENDING/OPEN/CLOSED/CANCELLED) dans la table `positions` (entité Position).
 *
 * - Crée une ligne PENDING dès le placement de l'ordre d'entrée
 * - Passe en OPEN lors du fill (renseigne entryPrice/qty/leverage/openedAt)
 * - Passe en CLOSED à la sortie (renseigne pnlUsdt/closedAt)
 */
final class PositionRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContractRepository $contracts,
    ) {}

    /**
     * Crée une Position en statut PENDING à partir d'un plan/scenario d'exécution.
     * Retourne l'entité persistée (flush effectuée).
     *
     * @param 'LONG'|'SHORT' $sideUpper
     */
    public function recordPending(
        string $exchange,
        string $symbol,
        string $sideUpper,
        float $entryPrice,
        float $qty,
        float $stop,
        float $tp1,
        ?float $leverage = null,
        ?string $externalOrderId = null,
        array $meta = []
    ): Position {
        $contract = $this->requireContract($symbol);

        $pos = new Position();
        $pos->setContract($contract)
            ->setExchange($exchange)
            ->setSide($sideUpper)
            ->setStatus(Position::STATUS_PENDING)
            ->setAmountUsdt($this->toDec((string)($entryPrice * $qty), 8))
            ->setEntryPrice($this->toDec((string)$entryPrice, 8))
            ->setQtyContract($this->toDec((string)$qty, 12))
            ->setLeverage($leverage !== null ? $this->toDec((string)$leverage, 2) : null)
            ->setStopLoss($this->toDec((string)$stop, 8))
            ->setTakeProfit($this->toDec((string)$tp1, 8))
            ->setExternalOrderId($externalOrderId)
            ->setMeta($meta ?: null);

        $this->em->persist($pos);
        $this->em->flush();

        return $pos;
    }

    /** Marque la position comme OPEN après le fill de l'ordre d'entrée. */
    public function markOpen(Position $pos, float $entryPrice, float $qty, ?float $leverage, ?\DateTimeImmutable $openedAt = null): void
    {
        $pos->setStatus(Position::STATUS_OPEN)
            ->setEntryPrice($this->toDec((string)$entryPrice, 8))
            ->setQtyContract($this->toDec((string)$qty, 12))
            ->setLeverage($leverage !== null ? $this->toDec((string)$leverage, 2) : null)
            ->setOpenedAt($openedAt ?? new \DateTimeImmutable())
            ->setAmountUsdt($this->toDec((string)($entryPrice * $qty), 8));

        $this->em->flush();
    }

    /**
     * Clôture la position et renseigne le PnL réalisé (USDT) en fonction du side.
     * Si vous avez un PnL exact côté exchange, vous pouvez le passer directement.
     */
    public function markClosed(Position $pos, float $exitPrice, ?float $pnlOverrideUsdt = null, ?\DateTimeImmutable $closedAt = null): void
    {
        $entry  = (float)($pos->getEntryPrice() ?? '0');
        $qty    = (float)($pos->getQtyContract() ?? '0');
        $side   = $pos->getSide();
        $pnl = $pnlOverrideUsdt;
        if ($pnl === null) {
            $gross = ($side === Position::SIDE_LONG)
                ? ($exitPrice - $entry) * $qty
                : ($entry - $exitPrice) * $qty;
            $pnl = $gross; // frais/slippage non inclus ici
        }

        $pos->setStatus(Position::STATUS_CLOSED)
            ->setClosedAt($closedAt ?? new \DateTimeImmutable())
            ->setPnlUsdt($this->toDec((string)$pnl, 8));

        $this->em->flush();
    }

    /** Marque la position comme CANCELLED (ex: ordre expiré/non rempli). */
    public function markCancelled(Position $pos, ?string $reason = null): void
    {
        $meta = $pos->getMeta() ?? [];
        if ($reason) { $meta['cancel_reason'] = $reason; }

        $pos->setStatus(Position::STATUS_CANCELLED)
            ->setMeta($meta ?: null);

        $this->em->flush();
    }

    private function requireContract(string $symbol): Contract
    {
        $c = $this->contracts->find($symbol);
        if (!$c) {
            throw new \RuntimeException("Contract not found: {$symbol}");
        }
        return $c;
    }

    private function toDec(string $value, int $scale): string
    {
        // Normalise en string décimale (Doctrine DECIMAL attend une string)
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Non numeric value');
        }
        // Utilise number_format pour respecter le scale
        return number_format((float)$value, $scale, '.', '');
    }
}
