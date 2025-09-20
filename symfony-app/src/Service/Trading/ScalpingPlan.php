<?php


declare(strict_types=1);


namespace App\Service\Trading;


/**
 * Objet de transfert immuable décrivant le plan d'exécution Scalping.
 */
final class ScalpingPlan
{
    public function __construct(
        private readonly string $symbol,
        /** 'long'|'short' */ private readonly string $side,
        private readonly float $entryPrice,
        private readonly float $totalQty,
        private readonly float $tp1Price,
        private readonly float $stopPrice,
        private readonly float $tp1Qty,
        private readonly float $runnerQty,
        private readonly bool $postOnly,
        private readonly bool $reduceOnly,
    ) {}


    public function symbol(): string { return $this->symbol; }
    /** @return 'long'|'short' */
    public function side(): string { return $this->side; }
    public function entryPrice(): float { return $this->entryPrice; }
    public function totalQty(): float { return $this->totalQty; }
    public function tp1Price(): float { return $this->tp1Price; }
    public function stopPrice(): float { return $this->stopPrice; }
    public function tp1Qty(): float { return $this->tp1Qty; }
    public function runnerQty(): float { return $this->runnerQty; }
    public function postOnly(): bool { return $this->postOnly; }
    public function reduceOnly(): bool { return $this->reduceOnly; }
}
