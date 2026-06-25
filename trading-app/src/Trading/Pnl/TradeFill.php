<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

final readonly class TradeFill
{
    public function __construct(
        public string $fillId,
        public string $side,
        public float $quantity,
        public float $price,
        public ?float $feeUsdt,
        public ?string $feeCurrency,
        public ?string $liquidity,
        public \DateTimeImmutable $filledAt,
    ) {
        if (trim($fillId) === '') {
            throw new \InvalidArgumentException('fillId is required.');
        }
        if ($quantity <= 0.0) {
            throw new \InvalidArgumentException('fill quantity must be positive.');
        }
        if ($price <= 0.0) {
            throw new \InvalidArgumentException('fill price must be positive.');
        }
        if ($feeUsdt !== null && $feeUsdt < 0.0) {
            throw new \InvalidArgumentException('fill fee must be positive or zero.');
        }
    }

    public function notionalUsdt(): float
    {
        return $this->quantity * $this->price;
    }
}
