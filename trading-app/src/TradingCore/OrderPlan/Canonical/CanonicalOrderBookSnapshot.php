<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalOrderBookSnapshot
{
    public function __construct(
        public string $exchange,
        public string $environment,
        public string $symbol,
        public string $marketType,
        public string $source,
        public float $bestBid,
        public float $bestAsk,
        public float $spreadBps,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
        if (
            trim($exchange) === ''
            || trim($environment) === ''
            || preg_match('/\A[A-Z0-9][A-Z0-9_.-]*\z/D', $symbol) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $marketType) !== 1
        ) {
            throw new CanonicalOrderPlanException('canonical_order_book_identity_invalid');
        }
        if (preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $source) !== 1) {
            throw new CanonicalOrderPlanException('canonical_order_book_source_invalid');
        }
        if (!\is_finite($bestBid) || !\is_finite($bestAsk) || $bestBid <= 0.0 || $bestAsk <= 0.0) {
            throw new CanonicalOrderPlanException('canonical_order_book_price_invalid');
        }
        if ($bestBid >= $bestAsk) {
            throw new CanonicalOrderPlanException('canonical_order_book_crossed');
        }
        if (!\is_finite($spreadBps) || $spreadBps <= 0.0) {
            throw new CanonicalOrderPlanException('canonical_order_book_spread_invalid');
        }
        if (abs($spreadBps - $this->derivedSpreadBps()) > 1.0e-9) {
            throw new CanonicalOrderPlanException('canonical_order_book_spread_mismatch');
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1) {
            throw new CanonicalOrderPlanException('canonical_order_book_hash_invalid');
        }
    }

    public function derivedSpreadBps(): float
    {
        return 10_000.0 * ($this->bestAsk - $this->bestBid) / (($this->bestAsk + $this->bestBid) / 2.0);
    }
}
