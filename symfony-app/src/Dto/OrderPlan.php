<?php

namespace App\Dto;

final class OrderPlan
{
    public function __construct(
        private readonly string $symbol,
        private readonly string $side, // 'long' | 'short'
        private readonly float $entryPrice,
        private readonly float $totalQty,
        private readonly float $tp1Price,
        private readonly float $stopPrice,
        private readonly float $tp1Qty,
        private readonly float $runnerQty,
        private readonly bool $postOnly,
        private readonly bool $reduceOnly,
        private readonly array $meta = [],
    ) {}
    public function symbol(): string { return $this->symbol; }
    public function side(): string { return $this->side; }
    public function entryPrice(): float { return $this->entryPrice; }
    public function totalQty(): float { return $this->totalQty; }
    public function tp1Price(): float { return $this->tp1Price; }
    public function stopPrice(): float { return $this->stopPrice; }
    public function tp1Qty(): float { return $this->tp1Qty; }
    public function runnerQty(): float { return $this->runnerQty; }
    public function postOnly(): bool { return $this->postOnly; }
    public function reduceOnly(): bool { return $this->reduceOnly; }
    public function meta(): array { return $this->meta; }
}
